<?php
declare(strict_types=1);

/**
 * Identidad visual de obras: calcula y persiste embeddings multimodales
 * (Vertex multimodalembedding@001) por obra raíz, y asigna obras nuevas a la
 * ficha existente más parecida por similitud de coseno.
 */
class ArtworkEmbeddingService
{
    /** Similitud mínima para asignar una obra nueva a una ficha existente sin preguntar. */
    public const AUTO_ASSIGN_THRESHOLD = 0.82;

    private PDO $pdo;
    private GeminiImageClient $client;

    public function __construct(?PDO $pdo = null, ?GeminiImageClient $client = null)
    {
        $this->pdo = $pdo ?: Database::connection();
        $this->client = $client ?: new GeminiImageClient();
    }

    public function resolveArtworkImagePath(array $artwork): ?string
    {
        $file = basename(str_replace('\\', '/', (string)(($artwork['root_file'] ?? '') ?: ($artwork['main_file'] ?? '') ?: '')));
        if ($file === '') {
            return null;
        }
        $dirs = [RESULTS_DIR, __DIR__ . '/../../uploads'];
        foreach ($dirs as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** Calcula y guarda embeddings para todas las obras del usuario que no lo tengan. Devuelve cuántas embebió. */
    public function embedMissing(int $userId, int $limit = 50): int
    {
        $stmt = $this->pdo->prepare('
            SELECT a.id, a.root_file, a.main_file
            FROM artworks a
            LEFT JOIN artwork_embeddings e ON e.artwork_id = a.id
            WHERE a.user_id = ? AND e.id IS NULL
            ORDER BY a.id
        ');
        $stmt->execute([$userId]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $done = 0;
        foreach ($pending as $artwork) {
            if ($done >= $limit) {
                break;
            }
            $path = $this->resolveArtworkImagePath($artwork);
            if ($path === null) {
                continue;
            }
            $vector = $this->embedImage($path);
            $this->storeEmbedding((int)$artwork['id'], basename($path), $vector);
            $done++;
        }
        return $done;
    }

    /** @return float[] */
    public function embedImage(string $path): array
    {
        $python = $this->client->getPythonExecutable();
        $bridge = __DIR__ . '/vertex_bridge.py';
        $cmd = '"' . $python . '" ' . escapeshellarg($bridge) . ' embed-image --crop-artwork --image ' . escapeshellarg($path);

        $env = getenv();
        $env = is_array($env) ? array_map('strval', $env) : [];
        $env['PYTHONIOENCODING'] = 'utf-8';
        $env['PYTHONUTF8'] = '1';
        if (defined('VERTEX_PROJECT_ID') && VERTEX_PROJECT_ID !== '') {
            $env['VERTEX_PROJECT_ID'] = (string)VERTEX_PROJECT_ID;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el bridge de Vertex para embeddings.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Embedding falló (exit ' . $exitCode . '): ' . trim(substr($stderr, 0, 400)));
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded) || !isset($decoded['embedding']) || !is_array($decoded['embedding'])) {
            throw new RuntimeException('Respuesta de embedding inválida para ' . basename($path));
        }
        return array_map('floatval', $decoded['embedding']);
    }

    public function storeEmbedding(int $artworkId, string $sourceFile, array $vector): void
    {
        $this->pdo->prepare('DELETE FROM artwork_embeddings WHERE artwork_id = ?')->execute([$artworkId]);
        $this->pdo->prepare('INSERT INTO artwork_embeddings (artwork_id, source_file, model, embedding_json, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$artworkId, $sourceFile, 'multimodalembedding@001', json_encode($vector), date('c')]);
    }

    /** @return array<int, float[]> artwork_id => vector */
    public function loadVectors(int $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT e.artwork_id, e.embedding_json
            FROM artwork_embeddings e
            INNER JOIN artworks a ON a.id = e.artwork_id
            WHERE a.user_id = ?
        ');
        $stmt->execute([$userId]);
        $vectors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vector = json_decode((string)$row['embedding_json'], true);
            if (is_array($vector)) {
                $vectors[(int)$row['artwork_id']] = array_map('floatval', $vector);
            }
        }
        return $vectors;
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * Para una obra sin ficha: busca la ficha existente más parecida comparando contra
     * todas las obras ya asignadas. Devuelve ['sheet_id' => int, 'similarity' => float] o null.
     */
    public function bestSheetFor(int $artworkId, int $userId): ?array
    {
        $vectors = $this->loadVectors($userId);
        if (!isset($vectors[$artworkId])) {
            return null;
        }
        $target = $vectors[$artworkId];

        $stmt = $this->pdo->prepare('SELECT id, canonical_artwork_id, related_artwork_ids FROM artwork_sheets WHERE user_id = ?');
        $stmt->execute([$userId]);
        $best = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sheet) {
            $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
            $ids = is_array($decoded) ? array_map('intval', $decoded) : [];
            $ids[] = (int)$sheet['canonical_artwork_id'];
            foreach (array_unique($ids) as $memberId) {
                if ($memberId === $artworkId || !isset($vectors[$memberId])) {
                    continue;
                }
                $similarity = self::cosine($target, $vectors[$memberId]);
                if ($best === null || $similarity > $best['similarity']) {
                    $best = ['sheet_id' => (int)$sheet['id'], 'similarity' => $similarity];
                }
            }
        }
        return $best;
    }
}
