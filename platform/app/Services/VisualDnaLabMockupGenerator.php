<?php
declare(strict_types=1);

final class VisualDnaLabMockupGenerator implements MockupGeneratorInterface
{
    private const OUTPUT_ASPECT_RATIO = '4:5';

    public function __construct(private readonly GeminiImageClient $client = new GeminiImageClient())
    {
    }

    public static function buildPrompt(array $referenceSet): string
    {
        $name = trim((string)($referenceSet['name'] ?? 'Untitled Visual DNA'));
        $description = trim((string)($referenceSet['description'] ?? ''));
        $categories = array_values(array_filter(array_map('strval', (array)($referenceSet['categories'] ?? []))));

        return "VISUAL DNA LAB — ISOLATED MOCKUP TEST\n"
            . "This request belongs only to the Visual DNA experiment.\n\n"
            . "IMAGE ROLE CONTRACT\n"
            . "- IMAGE 1 is the ROOT ARTWORK and is the absolute product authority. Preserve its exact visible artwork, colors, marks, texture, composition, proportions, format, and orientation.\n"
            . "- IMAGES 2 onward are VISUAL DNA REFERENCES. Read them together as evidence of a shared visual intention for architecture, materiality, light, palette, atmosphere, furniture language, and spatial restraint.\n"
            . "- Do not copy a reference room, crop, camera, layout, object arrangement, artwork, text, logo, or recognizable composition. Synthesize a new original environment from the qualities shared by the references.\n"
            . "- Never transfer marks, motifs, colors, or texture from a reference into the ROOT ARTWORK.\n\n"
            . "REFERENCE SET\n"
            . "Name: {$name}\n"
            . ($description !== '' ? "Intent: {$description}\n" : '')
            . ($categories ? 'Categories: ' . implode(', ', $categories) . "\n" : '')
            . "\nOUTPUT\n"
            . "Create one photorealistic editorial interior mockup in a clean three-quarter view. The artwork is the protagonist, fully visible, naturally integrated on a plausible wall, and never visually overpowered by the environment.\n"
            . "Use an exact 4:5 portrait frame. Keep the interface, captions, labels, measurements, watermarks, and all visible text out of the image.\n"
            . "When a Visual DNA cue conflicts with artwork fidelity, simplify the environment and preserve IMAGE 1 exactly.";
    }

    public function generate(string $imagePath, string $contextId, string $prompt, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('The root artwork file was not found for the Visual DNA LAB.');
        }

        $references = array_values(array_filter(
            (array)($metadata['visual_dna_references'] ?? []),
            static fn($reference): bool => is_array($reference)
                && is_file((string)($reference['local_path'] ?? ''))
        ));
        $references = array_slice($references, 0, 6);
        if (!$references) {
            throw new RuntimeException('This Visual DNA has no real reference images.');
        }

        if (!is_dir(RESULTS_DIR)) {
            mkdir(RESULTS_DIR, 0775, true);
        }
        if (!is_dir(PROMPTS_DIR)) {
            mkdir(PROMPTS_DIR, 0775, true);
        }

        $submittedPrompt = trim($prompt);
        if ($submittedPrompt === '') {
            $submittedPrompt = self::buildPrompt((array)($metadata['reference_set'] ?? []));
        }

        $parts = [
            $this->client->textPart($submittedPrompt),
            $this->client->textPart('IMAGE 1 — ROOT ARTWORK: preserve this exact artwork as the product shown in the mockup.'),
            $this->client->imagePart($imagePath),
        ];
        foreach ($references as $index => $reference) {
            $imageNumber = $index + 2;
            $title = trim((string)($reference['title'] ?? 'Visual reference'));
            $category = trim((string)($reference['category'] ?? 'Reference'));
            $parts[] = $this->client->textPart(
                "IMAGE {$imageNumber} — VISUAL DNA REFERENCE ({$category}: {$title}). "
                . 'Use only its contribution to the shared visual language; do not reproduce the source image.'
            );
            $parts[] = $this->client->imagePart((string)$reference['local_path']);
        }

        Logger::log(
            'Starting isolated Visual DNA LAB mockup. Context: ' . $contextId
                . ', artwork: ' . basename($imagePath)
                . ', references: ' . count($references),
            'gemini'
        );
        $startedAt = microtime(true);
        $encoded = $this->client->generateImage($parts, null, [
            'GEMINI_OUTPUT_ASPECT_RATIO' => self::OUTPUT_ASPECT_RATIO,
            'MOCKUP_PROMPT_FIRST_MODE' => 'false',
            'MOCKUP_USE_PRECOMPOSITION' => 'false',
            'MOCKUP_USE_BACKGROUND_EDIT' => 'false',
            'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => 'false',
        ]);
        $imageData = base64_decode($encoded, true);
        if ($imageData === false || $imageData === '') {
            throw new RuntimeException('Gemini did not return a valid image for the Visual DNA LAB.');
        }

        $stamp = date('Ymd_His') . '_' . random_int(1000, 9999);
        $outputName = 'visual_dna_lab_' . $stamp . '.png';
        $promptName = 'visual_dna_lab_' . $stamp . '.txt';
        $outputPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $outputName;
        $promptPath = PROMPTS_DIR . DIRECTORY_SEPARATOR . $promptName;
        if (file_put_contents($outputPath, $imageData) === false
            || file_put_contents($promptPath, $submittedPrompt) === false) {
            throw new RuntimeException('The Visual DNA LAB result could not be stored.');
        }

        Logger::log(
            'Visual DNA LAB mockup generated in ' . round(microtime(true) - $startedAt, 2) . 's. File: ' . $outputName,
            'gemini'
        );

        return [
            'file' => $outputName,
            'path' => $outputPath,
            'prompt_file' => $promptName,
            'mock' => false,
            'gemini_mockup' => true,
            'visual_dna_lab' => true,
        ];
    }
}
