<?php
declare(strict_types=1);

class MockupPromptApprovalService
{
    /**
     * Approves one or more prompt drafts for an artwork, saving them to approved-prompts JSON.
     *
     * @param int $artworkId The ID of the artwork.
     * @param array $draftIndexes The draft indexes to approve (usually 1-based, matching branch_index).
     * @return array The resulting approved prompts JSON structure.
     * @throws RuntimeException If the prompt drafts JSON is missing.
     * @throws InvalidArgumentException If a requested draft index is not found.
     */
    public function approveDrafts(int $artworkId, array $draftIndexes): array
    {
        Logger::log("MOCKUP_PROMPT_APPROVAL_START for artwork_id: {$artworkId}, drafts: " . implode(',', $draftIndexes), 'info');

        $baseDir = dirname(__DIR__, 2);
        $draftsPath = $baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'mockup-prompt-drafts' . DIRECTORY_SEPARATOR . $artworkId . '.prompt-drafts.json';
        $approvedPath = $baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'mockup-approved-prompts' . DIRECTORY_SEPARATOR . $artworkId . '.approved-prompts.json';

        // 1. Read prompt drafts JSON
        if (!is_file($draftsPath)) {
            throw new RuntimeException("Mockup prompt drafts JSON file not found for artwork ID {$artworkId} at path: {$draftsPath}");
        }

        $draftsContent = file_get_contents($draftsPath);
        if ($draftsContent === false) {
            throw new RuntimeException("Failed to read prompt drafts JSON file for artwork ID {$artworkId}");
        }

        $draftsJson = json_decode($draftsContent, true);
        if (!is_array($draftsJson)) {
            throw new RuntimeException("Failed to parse prompt drafts JSON file for artwork ID {$artworkId}");
        }

        $promptDrafts = $draftsJson['prompt_drafts'] ?? [];

        // 2. Load existing approved prompts (if any) to merge
        $existingApproved = [];
        if (is_file($approvedPath)) {
            $existingContent = file_get_contents($approvedPath);
            if ($existingContent !== false) {
                $existingJson = json_decode($existingContent, true);
                if (is_array($existingJson) && is_array($existingJson['approved_prompts'] ?? null)) {
                    foreach ($existingJson['approved_prompts'] as $item) {
                        if (isset($item['draft_index'])) {
                            $existingApproved[(int)$item['draft_index']] = $item;
                        }
                    }
                }
            }
        }

        // 3. Process each requested draft index
        $warnings = [];
        foreach ($draftIndexes as $draftIndex) {
            $foundDraft = null;
            foreach ($promptDrafts as $d) {
                if (($d['branch_index'] ?? null) === $draftIndex) {
                    $foundDraft = $d;
                    break;
                }
            }

            if ($foundDraft === null) {
                throw new InvalidArgumentException("Requested draft index {$draftIndex} not found in prompt drafts for artwork ID {$artworkId}");
            }

            $approvedItem = [
                'draft_index' => (int)$draftIndex,
                'branch_index' => (int)($foundDraft['branch_index'] ?? $draftIndex),
                'context_name' => $foundDraft['context_name'] ?? '',
                'resolved_root_view' => $foundDraft['resolved_root_view'] ?? [],
                'prompt_blocks' => $foundDraft['prompt_blocks'] ?? [],
                'final_prompt' => $foundDraft['final_prompt'] ?? '',
                'approval_status' => 'approved',
            ];

            $existingApproved[(int)$draftIndex] = $approvedItem;
        }

        // Sort by draft_index key to keep it clean and ordered
        ksort($existingApproved);

        $result = [
            'schema' => 'mockup_approved_prompts.v1',
            'artwork_id' => (int)$artworkId,
            'source_prompt_drafts_json' => "analysis/mockup-prompt-drafts/{$artworkId}.prompt-drafts.json",
            'approved_at' => date(DATE_ATOM),
            'approval_mode' => 'manual_review',
            'approved_prompts' => array_values($existingApproved),
            'warnings' => $warnings,
        ];

        // 4. Write output JSON to disk
        $approvedDir = $baseDir . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'mockup-approved-prompts';
        if (!is_dir($approvedDir)) {
            if (!mkdir($approvedDir, 0775, true) && !is_dir($approvedDir)) {
                throw new RuntimeException("Failed to create folder: {$approvedDir}");
            }
        }

        $jsonString = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonString === false) {
            throw new RuntimeException("Failed to encode approved prompts JSON.");
        }

        if (file_put_contents($approvedPath, $jsonString) === false) {
            throw new RuntimeException("Failed to write approved prompts JSON to disk at: {$approvedPath}");
        }

        Logger::log("MOCKUP_PROMPT_APPROVAL_SUCCESS for artwork_id: {$artworkId}. Written to {$approvedPath}", 'info');
        return $result;
    }
}
