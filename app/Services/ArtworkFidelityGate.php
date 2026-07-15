<?php
declare(strict_types=1);

final class ArtworkFidelityGate
{
    private GeminiImageClient $client;

    public function __construct(?GeminiImageClient $client = null)
    {
        $this->client = $client ?: new GeminiImageClient();
    }

    /**
     * Compare the authoritative root artwork with the artwork visible in a generated scene.
     * This is a visual review only; it never edits or resizes either image.
     *
     * @return array<string,mixed>
     */
    public function review(string $rootArtworkPath, string $candidatePath, array $metadata = []): array
    {
        if (!is_file($rootArtworkPath) || !is_file($candidatePath)) {
            throw new RuntimeException('Fidelity review requires both the root artwork and generated candidate.');
        }

        $combination = is_array($metadata['mockup_combination'] ?? null)
            ? $metadata['mockup_combination']
            : [];
        $cameraSlot = trim((string)($combination['selected_camera_slot_id'] ?? 'unknown'));
        $cameraTitle = trim((string)($combination['selected_camera_slot_name'] ?? $combination['camera_slot_name'] ?? ''));
        $minimumScore = defined('MOCKUP_FIDELITY_MIN_SCORE') ? (float)MOCKUP_FIDELITY_MIN_SCORE : 72.0;

        $prompt = <<<PROMPT
You are a strict visual quality-control reviewer for artwork mockups.

The FIRST image is the authoritative ROOT ARTWORK.
The SECOND image is a GENERATED ROOM MOCKUP that must contain that exact same artwork.

Camera slot: {$cameraSlot}
Camera title: {$cameraTitle}

Judge the identity of the artwork only. Ignore the room, furniture, wall, frame, shadows and lighting.
Allow normal photographic perspective, lens distortion, glare, cast shadows, small color shifts caused by illumination, and partial cropping when the selected camera is a close-up/detail view. Do not require pixel identity.

Reject the candidate when the visible artwork has been replaced or repainted: different composition, different subject, invented marks, missing major marks, moved color fields, substantially different color balance, mirrored identity, different orientation, different texture structure, or content borrowed from the room reference. If the artwork is too hidden or too small to verify, reject it.

Return only one JSON object with exactly these fields:
{
  "same_artwork": true,
  "substitution_detected": false,
  "artwork_visible_enough": true,
  "identity_score": 0,
  "composition_match": "high|medium|low",
  "color_structure_match": "high|medium|low",
  "reason": "short factual explanation"
}

identity_score must be an integer from 0 to 100. Scores at or above {$minimumScore} mean the artwork identity is sufficiently preserved.
PROMPT;

        $raw = $this->client->generateText([
            $this->client->textPart($prompt),
            $this->client->imagePart($rootArtworkPath),
            $this->client->imagePart($candidatePath),
        ], defined('MOCKUP_FIDELITY_REVIEW_MODEL') ? (string)MOCKUP_FIDELITY_REVIEW_MODEL : 'gemini-2.5-flash');

        $review = self::parseResponse($raw);
        $score = (float)($review['identity_score'] ?? 0);
        if ($score >= 0 && $score <= 1) {
            $score *= 100;
        }

        $review['identity_score'] = max(0, min(100, (int)round($score)));
        $review['same_artwork'] = self::toBool($review['same_artwork'] ?? false);
        $review['substitution_detected'] = self::toBool($review['substitution_detected'] ?? true);
        $review['artwork_visible_enough'] = self::toBool($review['artwork_visible_enough'] ?? false);
        $review['minimum_score'] = $minimumScore;
        $review['passed'] = $review['same_artwork']
            && !$review['substitution_detected']
            && $review['artwork_visible_enough']
            && $review['identity_score'] >= $minimumScore;
        $review['review_model'] = defined('MOCKUP_FIDELITY_REVIEW_MODEL')
            ? (string)MOCKUP_FIDELITY_REVIEW_MODEL
            : 'gemini-2.5-flash';

        return $review;
    }

    /** @return array<string,mixed> */
    public static function parseResponse(string $raw): array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $clean = substr($clean, $start, $end - $start + 1);
        }

        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Fidelity reviewer returned invalid JSON.');
        }
        return $decoded;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }
}
