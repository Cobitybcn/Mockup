<?php
declare(strict_types=1);

/**
 * PUBLICACION_DISENO: one cover, decided in one place. The website header used
 * to have its own picker over ALL of the artwork's mockups, independent of the
 * gallery composition, so the two drifted apart: pages whose header was the
 * 7th image of the grid, or a mockup that was not in the grid at all.
 *
 * From here the cover IS the first image of the composition. This migration
 * makes that true WITHOUT changing a single published page: instead of
 * overwriting the artist's header with whatever sat first, it moves the header
 * he already chose to position 1.
 */
return [
    'description' => 'Website cover becomes the first image of the composition, preserving every chosen header',
    'up' => static function (PDO $pdo): void {
        // Data repair, not schema: on a fresh install the tables it reads do not
        // exist yet and there is nothing to repair either way.
        try {
            $publications = $pdo->query('SELECT id, user_id, artwork_sheet_id, header_file FROM publications')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return;
        }
        if (!$publications) return;

        $itemsStmt = $pdo->prepare('SELECT pi.id, pi.mockup_sheet_id, ms.mockup_file
            FROM publication_items pi
            JOIN mockup_sheets ms ON ms.id = pi.mockup_sheet_id
            WHERE pi.publication_id = ?
            ORDER BY pi.position ASC, pi.id ASC');
        $sheetMockups = $pdo->prepare('SELECT id, mockup_file FROM mockup_sheets WHERE user_id=? AND artwork_sheet_id=? ORDER BY id ASC');
        $insert = $pdo->prepare('INSERT INTO publication_items (publication_id, mockup_sheet_id, position, role, title, alt_text, caption)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $reposition = $pdo->prepare('UPDATE publication_items SET position=? WHERE id=?');

        foreach ($publications as $publication) {
            $header = basename(trim((string)($publication['header_file'] ?? '')));
            if ($header === '') continue;

            $itemsStmt->execute([(int)$publication['id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            $files = array_map(static fn(array $i): string => basename((string)$i['mockup_file']), $items);

            // Already leading: nothing to repair.
            if ($files !== [] && $files[0] === $header) continue;

            $headerIndex = array_search($header, $files, true);
            if ($headerIndex !== false) {
                // In the grid but not first — pull it to the front.
                $ordered = $items;
                $moved = array_splice($ordered, (int)$headerIndex, 1);
                array_unshift($ordered, $moved[0]);
                foreach ($ordered as $position => $item) {
                    $reposition->execute([$position, (int)$item['id']]);
                }
                continue;
            }

            // The header is not in the composition at all. Find its sheet.
            $sheetMockups->execute([(int)$publication['user_id'], (int)$publication['artwork_sheet_id']]);
            $available = $sheetMockups->fetchAll(PDO::FETCH_ASSOC);
            $headerSheetId = 0;
            foreach ($available as $candidate) {
                if (basename((string)$candidate['mockup_file']) === $header) {
                    $headerSheetId = (int)$candidate['id'];
                    break;
                }
            }
            // Header outside this artwork's mockups (a root view, a stale file):
            // out of scope here, and the page keeps working as it does today.
            if ($headerSheetId === 0) continue;

            if ($items === []) {
                // An empty composition means "show every mockup". Inserting a
                // single item would silently collapse that gallery to one
                // image, so the whole set is written with the header leading.
                $position = 0;
                $insert->execute([(int)$publication['id'], $headerSheetId, $position++, 'cover', '', '', '']);
                foreach ($available as $candidate) {
                    if ((int)$candidate['id'] === $headerSheetId) continue;
                    $insert->execute([(int)$publication['id'], (int)$candidate['id'], $position++, 'context', '', '', '']);
                }
                continue;
            }

            foreach ($items as $position => $item) {
                $reposition->execute([$position + 1, (int)$item['id']]);
            }
            $insert->execute([(int)$publication['id'], $headerSheetId, 0, 'cover', '', '', '']);
        }
    },
];
