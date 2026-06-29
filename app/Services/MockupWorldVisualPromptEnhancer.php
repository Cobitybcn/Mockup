<?php
declare(strict_types=1);

class MockupWorldVisualPromptEnhancer
{
    public function __construct(private ?MockupContextWorldRegistry $registry = null)
    {
        $this->registry = $registry ?: new MockupContextWorldRegistry();
    }

    public function enhancePromptForContextId(string $prompt, string $contextId): string
    {
        $contextId = trim($contextId);
        if ($contextId === '' || str_contains($prompt, 'WORLD VISUAL CONTRACT:')) {
            return $prompt;
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM mockup_contexts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $contextId]);
            $contextRow = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            Logger::log('World visual contract skipped for context_id=' . $contextId . ': ' . $e->getMessage(), 'warning');
            return $prompt;
        }

        return is_array($contextRow)
            ? $this->enhancePromptForContextRow($prompt, $contextRow)
            : $prompt;
    }

    public function enhancePromptForContextRow(string $prompt, array $contextRow): string
    {
        if (str_contains($prompt, 'WORLD VISUAL CONTRACT:')) {
            return $prompt;
        }

        $contextJson = [];
        if (isset($contextRow['context_json']) && is_string($contextRow['context_json'])) {
            $decoded = json_decode($contextRow['context_json'], true);
            $contextJson = is_array($decoded) ? $decoded : [];
        } elseif (isset($contextRow['context_json']) && is_array($contextRow['context_json'])) {
            $contextJson = $contextRow['context_json'];
        } else {
            $contextJson = $contextRow;
        }

        $contract = $this->registry->worldVisualContractForContextJson($contextJson);
        $block = $this->registry->formatWorldVisualContractBlock($contract);
        if ($block === '') {
            return $prompt;
        }

        return $this->insertContractBlock($prompt, $block);
    }

    public function contractBlockForContextRow(array $contextRow): string
    {
        $contextJson = [];
        if (isset($contextRow['context_json']) && is_string($contextRow['context_json'])) {
            $decoded = json_decode($contextRow['context_json'], true);
            $contextJson = is_array($decoded) ? $decoded : [];
        }

        return $this->registry->formatWorldVisualContractBlock(
            $this->registry->worldVisualContractForContextJson($contextJson)
        );
    }

    private function insertContractBlock(string $prompt, string $block): string
    {
        $needle = "MOCKUP CONTEXT PROPOSAL:\n";
        $pos = strpos($prompt, $needle);
        if ($pos === false) {
            return $block . "\n\n" . $prompt;
        }

        $insertAt = $pos + strlen($needle);
        return substr($prompt, 0, $insertAt)
            . $block . "\n\n"
            . substr($prompt, $insertAt);
    }
}
