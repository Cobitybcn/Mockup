<?php
declare(strict_types=1);

final class ReferenceSetService
{
    private const COLORS = ['rose', 'clay', 'ochre', 'sage', 'lilac', 'blue'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureStarterSets(int $userId): void
    {
        foreach (StudioReferenceCatalog::starterSets() as $starter) {
            $stmt = $this->pdo->prepare('SELECT id FROM reference_sets WHERE user_id = :user_id AND name = :name LIMIT 1');
            $stmt->execute(['user_id' => $userId, 'name' => (string)$starter['name']]);
            if ((int)$stmt->fetchColumn() > 0) {
                continue;
            }
            $this->create(
                $userId,
                (string)$starter['name'],
                (string)$starter['description'],
                (string)$starter['color'],
                (array)$starter['references']
            );
        }
    }

    public function listForUser(int $userId, bool $withItems = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reference_sets WHERE user_id = :user_id ORDER BY created_at DESC, id DESC');
        $stmt->execute(['user_id' => $userId]);
        $sets = [];
        foreach ($stmt->fetchAll() as $row) {
            $set = $this->normalizeSet($row);
            if ($withItems) {
                $set['items'] = $this->items((int)$set['id']);
            }
            $sets[] = $set;
        }
        return $sets;
    }

    public function findForUser(int $userId, int $referenceSetId, bool $withItems = false): ?array
    {
        if ($referenceSetId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM reference_sets WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $referenceSetId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $set = $this->normalizeSet($row);
        if ($withItems) {
            $set['items'] = $this->items((int)$set['id']);
        }
        return $set;
    }

    public function create(int $userId, string $name, string $description, string $identifierColor, array $referenceKeys): array
    {
        $name = trim($name);
        $description = trim($description);
        if ($name === '') {
            throw new InvalidArgumentException('Reference Set name is required.');
        }
        if (strlen($name) > 160) {
            throw new InvalidArgumentException('Reference Set name is too long.');
        }
        if (strlen($description) > 2000) {
            throw new InvalidArgumentException('Reference Set description is too long.');
        }

        $identifierColor = in_array($identifierColor, self::COLORS, true) ? $identifierColor : 'rose';
        $catalog = array_merge(
            StudioReferenceCatalog::map(),
            (new ReferenceAssetService($this->pdo))->catalogMapForUser($userId)
        );
        $ordered = [];
        $seen = [];
        foreach ($referenceKeys as $referenceKey) {
            $referenceKey = trim((string)$referenceKey);
            if ($referenceKey === '' || isset($seen[$referenceKey]) || !isset($catalog[$referenceKey])) {
                continue;
            }
            $seen[$referenceKey] = true;
            $ordered[] = $catalog[$referenceKey];
            if (count($ordered) >= 50) {
                break;
            }
        }
        if (!$ordered) {
            throw new InvalidArgumentException('Assign at least one valid reference before saving.');
        }

        $categories = [];
        foreach ($ordered as $reference) {
            $category = (string)$reference['category'];
            if (!in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        $now = date('c');
        $driver = strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'mysql') {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO reference_sets
                (user_id, name, description, thumbnail, identifier_color, categories_json, created_at, updated_at)
                VALUES (:user_id, :name, :description, :thumbnail, :identifier_color, :categories_json, :created_at, :updated_at)');
            $stmt->execute([
                'user_id' => $userId,
                'name' => $name,
                'description' => $description,
                'thumbnail' => (string)$ordered[0]['image'],
                'identifier_color' => $identifierColor,
                'categories_json' => json_encode($categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $referenceSetId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare('INSERT INTO reference_set_items
                (reference_set_id, reference_asset_id, reference_key, title, category, thumbnail, position, created_at)
                VALUES (:reference_set_id, :reference_asset_id, :reference_key, :title, :category, :thumbnail, :position, :created_at)');
            foreach ($ordered as $position => $reference) {
                $itemStmt->execute([
                    'reference_set_id' => $referenceSetId,
                    'reference_asset_id' => (int)($reference['reference_asset_id'] ?? 0) ?: null,
                    'reference_key' => (string)$reference['id'],
                    'title' => (string)$reference['title'],
                    'category' => (string)$reference['category'],
                    'thumbnail' => (string)$reference['image'],
                    'position' => $position,
                    'created_at' => $now,
                ]);
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $error;
        }

        return $this->findForUser($userId, $referenceSetId, true)
            ?? throw new RuntimeException('The Reference Set could not be loaded after saving.');
    }

    private function items(int $referenceSetId): array
    {
        $stmt = $this->pdo->prepare('SELECT reference_asset_id, reference_key, title, category, thumbnail, position
            FROM reference_set_items WHERE reference_set_id = :reference_set_id ORDER BY position ASC, id ASC');
        $stmt->execute(['reference_set_id' => $referenceSetId]);
        return $stmt->fetchAll();
    }

    private function normalizeSet(array $row): array
    {
        $categories = json_decode((string)($row['categories_json'] ?? '[]'), true);
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['categories'] = is_array($categories) ? array_values($categories) : [];
        return $row;
    }
}
