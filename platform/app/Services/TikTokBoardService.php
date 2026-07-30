<?php
declare(strict_types=1);

/**
 * TikTok Studio date boards: pure organizational grouping of finished
 * videos by intended publish date, independent of whether a video has
 * actually been scheduled yet (scheduling lives in TikTokPublishScheduler).
 */
final class TikTokBoardService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{id:int,publish_date:string,title:string} */
    public function boardForDate(int $userId, string $date, string $title = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Fecha de board inválida.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM tiktok_boards WHERE user_id=? AND publish_date=? LIMIT 1');
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if ($title !== '' && $title !== (string)$row['title']) {
                $this->pdo->prepare('UPDATE tiktok_boards SET title=?,updated_at=? WHERE id=?')
                    ->execute([mb_substr($title, 0, 255), date('c'), (int)$row['id']]);
                $row['title'] = $title;
            }
            return ['id' => (int)$row['id'], 'publish_date' => (string)$row['publish_date'], 'title' => (string)$row['title']];
        }
        $now = date('c');
        $this->pdo->prepare('INSERT INTO tiktok_boards (user_id,publish_date,title,created_at,updated_at) VALUES (?,?,?,?,?)')
            ->execute([$userId, $date, mb_substr($title, 0, 255), $now, $now]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'publish_date' => $date, 'title' => $title];
    }

    /** @return array<int,array{id:int,publish_date:string,title:string,video_export_ids:list<int>}> ordered by date */
    public function boardsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tiktok_boards WHERE user_id=? ORDER BY publish_date ASC,id ASC');
        $stmt->execute([$userId]);
        $boards = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $boards[(int)$row['id']] = [
                'id' => (int)$row['id'],
                'publish_date' => (string)$row['publish_date'],
                'title' => (string)$row['title'],
                'video_export_ids' => [],
            ];
        }
        if (!$boards) {
            return [];
        }
        $itemsStmt = $this->pdo->prepare(
            'SELECT board_id,video_export_id FROM tiktok_board_items WHERE user_id=? ORDER BY board_id ASC,position ASC,id ASC'
        );
        $itemsStmt->execute([$userId]);
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $boardId = (int)$item['board_id'];
            if (isset($boards[$boardId])) {
                $boards[$boardId]['video_export_ids'][] = (int)$item['video_export_id'];
            }
        }
        return array_values($boards);
    }

    /** @return array<int,int> video_export_id => board_id, for every assigned video */
    public function boardIdsByVideo(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT board_id,video_export_id FROM tiktok_board_items WHERE user_id=?');
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['video_export_id']] = (int)$row['board_id'];
        }
        return $map;
    }

    public function assignVideo(int $userId, int $videoExportId, string $date, string $title = ''): array
    {
        $this->assertVideoExportOwned($userId, $videoExportId);
        $board = $this->boardForDate($userId, $date, $title);
        $this->pdo->prepare('DELETE FROM tiktok_board_items WHERE user_id=? AND video_export_id=?')
            ->execute([$userId, $videoExportId]);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM tiktok_board_items WHERE board_id=?');
        $countStmt->execute([$board['id']]);
        $position = (int)$countStmt->fetchColumn();
        $this->pdo->prepare('INSERT INTO tiktok_board_items (board_id,user_id,video_export_id,position,created_at) VALUES (?,?,?,?,?)')
            ->execute([$board['id'], $userId, $videoExportId, $position, date('c')]);
        return $board;
    }

    public function unassignVideo(int $userId, int $videoExportId): void
    {
        $this->pdo->prepare('DELETE FROM tiktok_board_items WHERE user_id=? AND video_export_id=?')
            ->execute([$userId, $videoExportId]);
    }

    private function assertVideoExportOwned(int $userId, int $videoExportId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id FROM video_exports e
             INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
             WHERE e.id=? AND e.user_id=? AND e.status='succeeded' LIMIT 1"
        );
        $stmt->execute([$videoExportId, $userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('El video seleccionado no está disponible.');
        }
    }
}
