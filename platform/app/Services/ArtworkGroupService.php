<?php
declare(strict_types=1);

final class ArtworkGroupService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: Database::connection();
    }

    public function syncUser(int $userId): array
    {
        $created = 0;
        $updated = 0;
        $groupedArtworkIds = [];

        foreach ($this->sheets($userId) as $sheet) {
            $memberIds = $this->sheetMemberIds($sheet);
            if (!$memberIds) {
                continue;
            }

            $canonicalId = (int)$sheet['canonical_artwork_id'];
            if (!in_array($canonicalId, $memberIds, true)) {
                $canonicalId = $memberIds[0];
            }

            $groupId = $this->activeGroupIdForMembers($userId, $memberIds);
            if ($groupId > 0) {
                $canonicalId = $this->canonicalArtworkIdForGroup($groupId, $userId) ?: $canonicalId;
            }
            if ($groupId <= 0) {
                $groupId = $this->findGroupIdByCanonical($userId, $canonicalId);
            }
            if ($groupId <= 0) {
                $groupId = $this->createGroup($userId, $canonicalId, (string)($sheet['title'] ?? ''));
                $created++;
            } else {
                $updated++;
            }

            $officialIds = $this->officialRootIds($canonicalId, $memberIds);
            $this->updateGroup($groupId, $userId, $canonicalId, $officialIds, (string)($sheet['title'] ?? ''));
            $this->assignArtworksToGroup($userId, $groupId, $memberIds, $officialIds);
            foreach ($memberIds as $memberId) {
                $groupedArtworkIds[$memberId] = true;
            }
        }

        foreach ($this->ungroupedArtworkIds($userId, array_keys($groupedArtworkIds)) as $artworkId) {
            $title = $this->artworkTitle($userId, $artworkId);
            $groupId = $this->findGroupIdByCanonical($userId, $artworkId);
            if ($groupId <= 0) {
                $groupId = $this->createGroup($userId, $artworkId, $title);
                $created++;
            } else {
                $updated++;
            }
            $this->updateGroup($groupId, $userId, $artworkId, [$artworkId], $title);
            $this->assignArtworksToGroup($userId, $groupId, [$artworkId], [$artworkId]);
        }

        $this->normalizeUserGroups($userId);
        $this->syncMockupLineage($userId);
        $this->adoptLegacyMockupRoots($userId);
        $this->normalizeUserGroups($userId);
        $this->syncMockupLineage($userId);

        return ['created' => $created, 'updated' => $updated];
    }

    public function syncAllUsers(): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'users' => 0];
        $stmt = $this->pdo->query('SELECT id FROM users ORDER BY id');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $result = $this->syncUser((int)$userId);
            $totals['created'] += (int)$result['created'];
            $totals['updated'] += (int)$result['updated'];
            $totals['users']++;
        }
        return $totals;
    }

    public function mergeGroups(int $userId, int $primaryGroupId, int $secondaryGroupId): void
    {
        if ($primaryGroupId <= 0 || $secondaryGroupId <= 0 || $primaryGroupId === $secondaryGroupId) {
            return;
        }

        $primary = $this->groupRow($userId, $primaryGroupId);
        $secondary = $this->groupRow($userId, $secondaryGroupId);
        if (!$primary || !$secondary || (string)$primary['status'] !== 'active') {
            return;
        }

        Database::withBusyRetry(function () use ($userId, $primaryGroupId, $secondaryGroupId, $primary): void {
            Database::beginWriteTransaction($this->pdo);
            try {
                $primaryArtworkIds = $this->artworkIdsForGroup($userId, $primaryGroupId);
                $secondaryArtworkIds = $this->artworkIdsForGroup($userId, $secondaryGroupId);
                $this->mergeArtworkSheets(
                    $userId,
                    (int)$primary['canonical_artwork_id'],
                    array_merge($primaryArtworkIds, $secondaryArtworkIds)
                );

                $this->pdo->prepare('
                    UPDATE artworks
                    SET artwork_group_id = :primary_group_id,
                        root_view_status = :root_view_status,
                        updated_at = :updated_at
                    WHERE user_id = :user_id
                    AND artwork_group_id = :secondary_group_id
                ')->execute([
                    'primary_group_id' => $primaryGroupId,
                    'root_view_status' => 'variant',
                    'updated_at' => date('c'),
                    'user_id' => $userId,
                    'secondary_group_id' => $secondaryGroupId,
                ]);

                foreach (['mockups', 'mockup_generation_jobs', 'mockup_sheets'] as $table) {
                    $this->pdo->prepare("
                        UPDATE {$table}
                        SET artwork_group_id = :primary_group_id
                        WHERE user_id = :user_id
                        AND artwork_group_id = :secondary_group_id
                    ")->execute([
                        'primary_group_id' => $primaryGroupId,
                        'user_id' => $userId,
                        'secondary_group_id' => $secondaryGroupId,
                    ]);
                }

                $this->pdo->prepare("
                    UPDATE artwork_groups
                    SET status = 'merged',
                        merged_into_group_id = :primary_group_id,
                        updated_at = :updated_at
                    WHERE id = :secondary_group_id
                    AND user_id = :user_id
                ")->execute([
                    'primary_group_id' => $primaryGroupId,
                    'updated_at' => date('c'),
                    'secondary_group_id' => $secondaryGroupId,
                    'user_id' => $userId,
                ]);

                $canonicalId = (int)$primary['canonical_artwork_id'];
                $officialIds = $this->officialRootIdsForGroup($userId, $primaryGroupId, $canonicalId);
                $this->updateGroup($primaryGroupId, $userId, $canonicalId, $officialIds, (string)($primary['title'] ?? ''));
                $this->assignArtworksToGroup($userId, $primaryGroupId, $this->artworkIdsForGroup($userId, $primaryGroupId), $officialIds);

                $this->pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $this->pdo->exec('ROLLBACK');
                throw $e;
            }
        });

        $this->syncMockupLineage($userId);
    }

    private function sheets(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM artwork_sheets WHERE user_id = :user_id AND COALESCE(status, '') <> 'merged' ORDER BY id");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Keep the primary editorial sheet as the single active reference while
     * preserving the former sheets for audit and existing foreign keys.
     *
     * @param array<int|string> $artworkIds
     */
    private function mergeArtworkSheets(int $userId, int $primaryArtworkId, array $artworkIds): void
    {
        $artworkIds = array_values(array_unique(array_filter(array_map('intval', $artworkIds))));
        if ($primaryArtworkId <= 0 || !$artworkIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($artworkIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM artwork_sheets
            WHERE user_id = ?
            AND canonical_artwork_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $stmt->execute(array_merge([$userId], $artworkIds));
        $sheets = $stmt->fetchAll();

        $primarySheet = null;
        $relatedArtworkIds = $artworkIds;
        foreach ($sheets as $sheet) {
            if ((int)($sheet['canonical_artwork_id'] ?? 0) === $primaryArtworkId) {
                $primarySheet = $sheet;
            }
            $related = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
            if (is_array($related)) {
                $relatedArtworkIds = array_merge($relatedArtworkIds, $related);
            }
        }

        if (!is_array($primarySheet)) {
            return;
        }

        $relatedArtworkIds = array_values(array_unique(array_filter(array_map('intval', $relatedArtworkIds))));
        sort($relatedArtworkIds);
        $now = date('c');
        $this->pdo->prepare('
            UPDATE artwork_sheets
            SET related_artwork_ids = :related_artwork_ids,
                updated_at = :updated_at
            WHERE id = :id
            AND user_id = :user_id
        ')->execute([
            'related_artwork_ids' => json_encode($relatedArtworkIds, JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
            'id' => (int)$primarySheet['id'],
            'user_id' => $userId,
        ]);

        $secondarySheetIds = array_values(array_filter(array_map(
            static fn (array $sheet): int => (int)$sheet['id'],
            $sheets
        ), static fn (int $sheetId): bool => $sheetId !== (int)$primarySheet['id']));
        if (!$secondarySheetIds) {
            return;
        }

        $sheetPlaceholders = implode(',', array_fill(0, count($secondarySheetIds), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE artwork_sheets
            SET status = 'merged',
                updated_at = ?
            WHERE user_id = ?
            AND id IN ({$sheetPlaceholders})
        ");
        $stmt->execute(array_merge([$now, $userId], $secondarySheetIds));
    }

    private function sheetMemberIds(array $sheet): array
    {
        $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
        $ids = is_array($decoded) ? $decoded : [];
        $ids[] = (int)($sheet['canonical_artwork_id'] ?? 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        return $ids;
    }

    private function officialRootIds(int $canonicalId, array $memberIds): array
    {
        $ids = [$canonicalId];
        foreach ($memberIds as $memberId) {
            if ($memberId !== $canonicalId) {
                $ids[] = $memberId;
            }
            if (count($ids) >= 3) {
                break;
            }
        }
        return array_values(array_unique($ids));
    }

    private function findGroupIdByCanonical(int $userId, int $canonicalArtworkId): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM artwork_groups WHERE user_id = :user_id AND canonical_artwork_id = :canonical_artwork_id AND status = 'active' LIMIT 1");
        $stmt->execute(['user_id' => $userId, 'canonical_artwork_id' => $canonicalArtworkId]);
        return (int)$stmt->fetchColumn();
    }

    private function activeGroupIdForMembers(int $userId, array $memberIds): int
    {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if (!$memberIds) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT a.artwork_group_id
            FROM artworks a
            INNER JOIN artwork_groups g ON g.id = a.artwork_group_id
            WHERE a.user_id = ?
            AND a.id IN ({$placeholders})
            AND g.status = 'active'
            ORDER BY CASE WHEN a.root_view_status = 'official' THEN 0 ELSE 1 END, a.id ASC
            LIMIT 1
        ");
        $stmt->execute(array_merge([$userId], $memberIds));
        return (int)$stmt->fetchColumn();
    }

    private function canonicalArtworkIdForGroup(int $groupId, int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT canonical_artwork_id FROM artwork_groups WHERE id = :id AND user_id = :user_id AND status = 'active' LIMIT 1");
        $stmt->execute(['id' => $groupId, 'user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    private function groupRow(int $userId, int $groupId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artwork_groups WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $groupId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function artworkIdsForGroup(int $userId, int $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM artworks WHERE user_id = :user_id AND artwork_group_id = :artwork_group_id ORDER BY id ASC');
        $stmt->execute(['user_id' => $userId, 'artwork_group_id' => $groupId]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function officialRootIdsForGroup(int $userId, int $groupId, int $canonicalId): array
    {
        $ids = [$canonicalId];
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM artworks
            WHERE user_id = :user_id
            AND artwork_group_id = :artwork_group_id
            ORDER BY CASE WHEN id = :canonical_id THEN 0 WHEN root_view_status = 'official' THEN 1 ELSE 2 END, id ASC
        ");
        $stmt->execute([
            'user_id' => $userId,
            'artwork_group_id' => $groupId,
            'canonical_id' => $canonicalId,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= 3) {
                break;
            }
        }
        return $ids;
    }

    private function createGroup(int $userId, int $canonicalArtworkId, string $title): int
    {
        $now = date('c');
        $referenceSetId = $this->referenceSetIdForArtwork($userId, $canonicalArtworkId);
        $stmt = $this->pdo->prepare('
            INSERT INTO artwork_groups (user_id, canonical_artwork_id, official_root_artwork_ids, title, status, reference_set_id, created_at, updated_at)
            VALUES (:user_id, :canonical_artwork_id, :official_root_artwork_ids, :title, :status, :reference_set_id, :created_at, :updated_at)
        ');
        $stmt->execute([
            'user_id' => $userId,
            'canonical_artwork_id' => $canonicalArtworkId,
            'official_root_artwork_ids' => json_encode([$canonicalArtworkId], JSON_UNESCAPED_SLASHES),
            'title' => trim($title),
            'status' => 'active',
            'reference_set_id' => $referenceSetId > 0 ? $referenceSetId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateGroup(int $groupId, int $userId, int $canonicalArtworkId, array $officialIds, string $title): void
    {
        $referenceSetId = $this->referenceSetIdForArtwork($userId, $canonicalArtworkId);
        $stmt = $this->pdo->prepare('
            UPDATE artwork_groups
            SET canonical_artwork_id = :canonical_artwork_id,
                official_root_artwork_ids = :official_root_artwork_ids,
                title = :title,
                reference_set_id = COALESCE(:reference_set_id, reference_set_id),
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ');
        $stmt->execute([
            'canonical_artwork_id' => $canonicalArtworkId,
            'official_root_artwork_ids' => json_encode(array_values(array_unique(array_map('intval', $officialIds))), JSON_UNESCAPED_SLASHES),
            'title' => trim($title),
            'reference_set_id' => $referenceSetId > 0 ? $referenceSetId : null,
            'updated_at' => date('c'),
            'id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    private function assignArtworksToGroup(int $userId, int $groupId, array $memberIds, array $officialIds): void
    {
        $official = array_fill_keys(array_map('intval', $officialIds), true);
        $referenceSetId = $this->referenceSetIdForGroup($userId, $groupId);
        $stmt = $this->pdo->prepare('
            UPDATE artworks
            SET artwork_group_id = :artwork_group_id,
                root_view_type = :root_view_type,
                root_view_status = :root_view_status,
                reference_set_id = COALESCE(reference_set_id, :reference_set_id),
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ');

        foreach ($memberIds as $memberId) {
            $stmt->execute([
                'artwork_group_id' => $groupId,
                'root_view_type' => $this->viewTypeForArtwork((int)$memberId),
                'root_view_status' => isset($official[(int)$memberId]) ? 'official' : 'variant',
                'reference_set_id' => $referenceSetId > 0 ? $referenceSetId : null,
                'updated_at' => date('c'),
                'id' => (int)$memberId,
                'user_id' => $userId,
            ]);
        }
    }

    private function referenceSetIdForArtwork(int $userId, int $artworkId): int
    {
        $stmt = $this->pdo->prepare('SELECT reference_set_id FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $artworkId, 'user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    private function referenceSetIdForGroup(int $userId, int $groupId): int
    {
        $stmt = $this->pdo->prepare('SELECT reference_set_id FROM artwork_groups WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $groupId, 'user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    private function viewTypeForArtwork(int $artworkId): string
    {
        $stmt = $this->pdo->prepare('
            SELECT c.view_type
            FROM root_artwork_candidates c
            INNER JOIN artworks a ON a.id = c.artwork_id
            WHERE c.artwork_id = :artwork_id
            AND c.file_name = a.root_file
            ORDER BY c.is_selected DESC, c.id ASC
            LIMIT 1
        ');
        $stmt->execute(['artwork_id' => $artworkId]);
        $viewType = trim((string)$stmt->fetchColumn());
        return $viewType !== '' ? $viewType : 'unknown';
    }

    private function ungroupedArtworkIds(int $userId, array $alreadyGroupedIds): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id
            FROM artworks
            WHERE user_id = :user_id
            AND status = :status
            AND root_file IS NOT NULL
            AND root_file <> \'\'
            AND artwork_group_id IS NULL
            ORDER BY id
        ');
        $stmt->execute(['user_id' => $userId, 'status' => 'done']);
        $skip = array_fill_keys(array_map('intval', $alreadyGroupedIds), true);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            if (!isset($skip[$id])) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function artworkTitle(int $userId, int $artworkId): string
    {
        $stmt = $this->pdo->prepare('SELECT final_title FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $artworkId, 'user_id' => $userId]);
        $title = trim((string)$stmt->fetchColumn());
        return $title !== '' ? $title : 'Untitled';
    }

    private function syncMockupLineage(int $userId): void
    {
        $stmt = $this->pdo->prepare('
            SELECT id, root_file, main_file, artwork_group_id
            FROM artworks
            WHERE user_id = :user_id
            AND artwork_group_id IS NOT NULL
        ');
        $stmt->execute(['user_id' => $userId]);
        foreach ($stmt->fetchAll() as $artwork) {
            $artworkId = (int)$artwork['id'];
            $groupId = (int)$artwork['artwork_group_id'];
            $artworkFiles = [];
            foreach (['root_file', 'main_file'] as $key) {
                $file = basename((string)($artwork[$key] ?? ''));
                if ($file !== '') {
                    $artworkFiles[$file] = true;
                }
            }

            foreach (array_keys($artworkFiles) as $artworkFile) {
                $this->pdo->prepare('
                    UPDATE mockups
                    SET artwork_group_id = :artwork_group_id,
                        source_artwork_id = :source_artwork_id
                    WHERE user_id = :user_id
                    AND artwork_file = :artwork_file
                ')->execute([
                    'artwork_group_id' => $groupId,
                    'source_artwork_id' => $artworkId,
                    'user_id' => $userId,
                    'artwork_file' => $artworkFile,
                ]);
            }

            $this->pdo->prepare('
                UPDATE mockup_generation_jobs
                SET artwork_group_id = :artwork_group_id,
                    source_artwork_id = :source_artwork_id
                WHERE user_id = :user_id
                AND artwork_id = :artwork_id
            ')->execute([
                'artwork_group_id' => $groupId,
                'source_artwork_id' => $artworkId,
                'user_id' => $userId,
                'artwork_id' => $artworkId,
            ]);

            $this->pdo->prepare('
                UPDATE mockup_sheets
                SET artwork_group_id = :artwork_group_id
                WHERE user_id = :user_id
                AND artwork_id = :artwork_id
            ')->execute([
                'artwork_group_id' => $groupId,
                'user_id' => $userId,
                'artwork_id' => $artworkId,
            ]);
        }
    }

    private function normalizeUserGroups(int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT id, canonical_artwork_id, title FROM artwork_groups WHERE user_id = :user_id AND status = 'active' ORDER BY id ASC");
        $stmt->execute(['user_id' => $userId]);
        foreach ($stmt->fetchAll() as $group) {
            $groupId = (int)$group['id'];
            $canonicalId = (int)$group['canonical_artwork_id'];
            $artworkIds = $this->artworkIdsForGroup($userId, $groupId);
            if (!$artworkIds) {
                continue;
            }
            if (!in_array($canonicalId, $artworkIds, true)) {
                $canonicalId = $artworkIds[0];
            }
            $officialIds = $this->officialRootIdsForGroup($userId, $groupId, $canonicalId);
            $this->updateGroup($groupId, $userId, $canonicalId, $officialIds, (string)($group['title'] ?? ''));
            $this->assignArtworksToGroup($userId, $groupId, $artworkIds, $officialIds);
        }
    }

    private function adoptLegacyMockupRoots(int $userId): void
    {
        $stmt = $this->pdo->prepare('
            SELECT artwork_file
            FROM mockups
            WHERE user_id = :user_id
            AND artwork_group_id IS NULL
            AND artwork_file IS NOT NULL
            AND artwork_file <> \'\'
            GROUP BY artwork_file
        ');
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rawFile) {
            $file = basename((string)$rawFile);
            if ($file === '' || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
                continue;
            }

            $artworkId = $this->findArtworkIdByFile($userId, $file);
            if ($artworkId <= 0) {
                $artworkId = $this->createAdoptedArtwork($userId, $file);
            }

            $groupId = $this->findGroupIdByCanonical($userId, $artworkId);
            if ($groupId <= 0) {
                $groupId = $this->createGroup($userId, $artworkId, 'Untitled');
            }
            $this->updateGroup($groupId, $userId, $artworkId, [$artworkId], $this->artworkTitle($userId, $artworkId));
            $this->assignArtworksToGroup($userId, $groupId, [$artworkId], [$artworkId]);
        }
    }

    private function findArtworkIdByFile(int $userId, string $file): int
    {
        $stmt = $this->pdo->prepare('
            SELECT id
            FROM artworks
            WHERE user_id = :user_id
            AND (root_file = :file OR main_file = :file)
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId, 'file' => basename($file)]);
        return (int)$stmt->fetchColumn();
    }

    private function createAdoptedArtwork(int $userId, string $file): int
    {
        $now = date('c');
        $jobId = 'adopted_legacy_root_' . $userId . '_' . substr(sha1($file), 0, 16);
        $stmt = $this->pdo->prepare('
            INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)
            VALUES (:user_id, :job_id, :main_file, :root_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
        ');
        $stmt->execute([
            'user_id' => $userId,
            'job_id' => $jobId,
            'main_file' => basename($file),
            'root_file' => basename($file),
            'status' => 'done',
            'width' => '',
            'height' => '',
            'depth' => '',
            'unit' => 'cm',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
