<?php
declare(strict_types=1);

final class ArtworkFidelityRejectedException extends RuntimeException
{
}

final class FidelityValidatingMockupGenerator implements MockupGeneratorInterface
{
    private MockupGeneratorInterface $generator;
    private ArtworkFidelityGate $gate;

    public function __construct(MockupGeneratorInterface $generator, ?ArtworkFidelityGate $gate = null)
    {
        $this->generator = $generator;
        $this->gate = $gate ?: new ArtworkFidelityGate();
    }

    public function generate(string $imagePath, string $contextId, string $prompt, array $metadata = []): array
    {
        if (!$this->shouldReview($metadata)) {
            return $this->generator->generate($imagePath, $contextId, $prompt, $metadata);
        }

        $maxRegenerations = defined('MOCKUP_FIDELITY_MAX_REGENERATIONS')
            ? max(0, min(2, (int)MOCKUP_FIDELITY_MAX_REGENERATIONS))
            : 2;
        $reviews = [];
        $currentPrompt = $prompt;
        $currentMetadata = $metadata;

        for ($attempt = 0; $attempt <= $maxRegenerations; $attempt++) {
            $result = $this->generator->generate($imagePath, $contextId, $currentPrompt, $currentMetadata);
            $candidatePath = (string)($result['path'] ?? '');

            try {
                $review = $this->gate->review($imagePath, $candidatePath, $metadata);
            } catch (Throwable $e) {
                $failOpen = defined('MOCKUP_FIDELITY_FAIL_OPEN') && MOCKUP_FIDELITY_FAIL_OPEN;
                if ($failOpen) {
                    Logger::log('Artwork fidelity reviewer unavailable; fail-open accepted candidate. Error: ' . $e->getMessage(), 'fidelity_warning');
                    $result['fidelity_review'] = [
                        'passed' => null,
                        'status' => 'review_unavailable_fail_open',
                        'reason' => $e->getMessage(),
                    ];
                    $result['fidelity_attempts'] = $attempt + 1;
                    return $result;
                }

                $this->discardCandidate($result);
                Logger::log('Artwork fidelity reviewer unavailable; candidate discarded. Error: ' . $e->getMessage(), 'fidelity_error');
                throw new ArtworkFidelityRejectedException(
                    'Artwork fidelity review was unavailable, so the unverified candidate was not saved: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $review['attempt'] = $attempt + 1;
            $review['candidate_file'] = (string)($result['file'] ?? '');
            $reviews[] = $review;
            Logger::log('Artwork fidelity review: ' . json_encode($review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'fidelity');

            if (!empty($review['passed'])) {
                $result['fidelity_review'] = $review;
                $result['fidelity_attempts'] = $attempt + 1;
                $result['fidelity_rejected_candidates'] = $attempt;
                $result['fidelity_reviews'] = $reviews;
                return $result;
            }

            $this->discardCandidate($result);

            if ($attempt < $maxRegenerations) {
                [$currentPrompt, $currentMetadata] = $this->retryInstructions($prompt, $metadata, $attempt + 1, $review);
            }
        }

        $last = $reviews[count($reviews) - 1] ?? [];
        throw new ArtworkFidelityRejectedException(
            'Artwork fidelity validation rejected all generated candidates. Last review: '
            . (string)($last['reason'] ?? 'artwork substitution detected')
        );
    }

    private function shouldReview(array $metadata): bool
    {
        $enabled = defined('MOCKUP_FIDELITY_GATE_ENABLED')
            ? (bool)MOCKUP_FIDELITY_GATE_ENABLED
            : true;
        return $enabled && !empty($metadata['mockup_combination']);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function retryInstructions(string $prompt, array $metadata, int $retryNumber, array $review): array
    {
        $reason = trim((string)($review['reason'] ?? 'artwork identity mismatch'));
        $directive = "\n\nFIDELITY RETRY {$retryNumber}: The previous candidate was rejected by visual QC ({$reason}). "
            . "Generate a new scene candidate, but copy the ROOT ARTWORK identity exactly. Preserve the same major shapes, "
            . "color-field positions, marks, empty areas, orientation, proportions and texture structure. Do not repaint, "
            . "reinterpret, mirror or borrow content from the environment reference. Simplify the room or camera treatment "
            . "before changing any artwork content.";

        $retryMetadata = $metadata;
        if (isset($retryMetadata['prompt_passthrough_mode']) && is_string($retryMetadata['prompt_passthrough_mode'])) {
            $retryMetadata['prompt_passthrough_mode'] .= $directive;
        }
        $retryMetadata['fidelity_retry_number'] = $retryNumber;

        return [$prompt . $directive, $retryMetadata];
    }

    private function discardCandidate(array $result): void
    {
        $path = trim((string)($result['path'] ?? ''));
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        $file = trim((string)($result['file'] ?? ''));
        if ($file !== '') {
            StorageService::delete('results/' . basename($file));
        }

        $promptFile = trim((string)($result['prompt_file'] ?? ''));
        if ($promptFile !== '') {
            $localPrompt = PROMPTS_DIR . DIRECTORY_SEPARATOR . basename($promptFile);
            if (is_file($localPrompt)) {
                @unlink($localPrompt);
            }
            StorageService::delete('mockup-prompts/' . basename($promptFile));
        }
    }
}
