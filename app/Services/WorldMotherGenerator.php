<?php
declare(strict_types=1);

final class WorldMotherGenerator
{
    private WorldMotherLibrary $library;
    private GeminiImageClient $client;

    public function __construct(?WorldMotherLibrary $library = null, ?GeminiImageClient $client = null)
    {
        $this->library = $library ?: new WorldMotherLibrary();
        $this->client = $client ?: new GeminiImageClient();
    }

    /**
     * @return array<string,mixed>
     */
    public function analyzeReference(string $imagePath, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('World mother reference image not found.');
        }

        $analysis = ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini'
            ? $this->analyzeWithGemini($imagePath, $metadata)
            : $this->fallbackAnalysis($imagePath, $metadata);

        $analysis = $this->normalizeAnalysis($analysis, $imagePath, $metadata);
        $analysis['category_candidates'] = $this->rankCategories($analysis);
        $analysis['new_category_suggestion'] = $this->suggestNewCategory($analysis);
        $analysis['analysis_source'] = ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini'
            ? 'gemini'
            : 'local_fallback';

        return $analysis;
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string,mixed>
     */
    public function generateOriginalWorldMother(string $referencePath, string $categorySlug, array $analysis, array $options = []): array
    {
        if (!is_file($referencePath)) {
            throw new RuntimeException('Reference image not found.');
        }

        $categorySlug = self::safeSlug($categorySlug);
        if ($categorySlug === '') {
            throw new RuntimeException('A category is required.');
        }

        $categoryDir = $this->library->basePath() . DIRECTORY_SEPARATOR . $categorySlug;
        if (!is_dir($categoryDir) && !mkdir($categoryDir, 0775, true) && !is_dir($categoryDir)) {
            throw new RuntimeException('Could not create world mother category folder.');
        }

        $stamp = date('Ymd_His') . '_' . random_int(1000, 9999);
        $fileName = 'world_mother_' . $stamp . '.png';
        $outputPath = $categoryDir . DIRECTORY_SEPARATOR . $fileName;
        $prompt = $this->buildGenerationPrompt($analysis, $categorySlug, $options);

        if (ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini') {
            $b64 = $this->client->generateImage([
                $this->client->textPart($prompt),
                $this->client->imagePart($referencePath),
            ]);
            $bytes = base64_decode($b64);
            if ($bytes === false) {
                throw new RuntimeException('Gemini did not return a valid image.');
            }
            file_put_contents($outputPath, $bytes);
        } else {
            $this->drawMockWorldMother($referencePath, $outputPath, $analysis, $categorySlug);
        }

        $auditDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'world-mother-generation-audit';
        if (!is_dir($auditDir)) {
            mkdir($auditDir, 0775, true);
        }
        $auditName = $categorySlug . '_' . $stamp . '.generation.json';
        $auditPath = $auditDir . DIRECTORY_SEPARATOR . $auditName;
        $audit = [
            'schema' => 'world_mother_generation_audit.v1',
            'generated_at' => date(DATE_ATOM),
            'mode' => ProviderSettings::isRealMode() ? ProviderSettings::imageProvider() : 'mock',
            'category_slug' => $categorySlug,
            'reference_path' => $referencePath,
            'output_path' => $outputPath,
            'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
            'analysis' => $analysis,
            'prompt' => $prompt,
            'options' => $options,
        ];
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'category_slug' => $categorySlug,
            'file_name' => $fileName,
            'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
            'absolute_path' => $outputPath,
            'audit_file' => 'analysis/world-mother-generation-audit/' . $auditName,
        ];
    }

    /**
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function generateOriginalWorldMotherForCategory(string $categorySlug, array $analysis = [], array $options = []): array
    {
        $categorySlug = self::safeSlug($categorySlug);
        if ($categorySlug === '') {
            throw new RuntimeException('A category is required.');
        }

        $analysis = $this->normalizeCategoryOnlyAnalysis($categorySlug, $analysis, $options);

        $categoryDir = $this->library->basePath() . DIRECTORY_SEPARATOR . $categorySlug;
        if (!is_dir($categoryDir) && !mkdir($categoryDir, 0775, true) && !is_dir($categoryDir)) {
            throw new RuntimeException('Could not create world mother category folder.');
        }

        $stamp = date('Ymd_His') . '_' . random_int(1000, 9999);
        $fileName = 'world_mother_auto_' . $stamp . '.png';
        $outputPath = $categoryDir . DIRECTORY_SEPARATOR . $fileName;
        $prompt = $this->buildGenerationPrompt($analysis, $categorySlug, $options);

        if (ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini') {
            $b64 = $this->client->generateImage([
                $this->client->textPart($prompt),
            ]);
            $bytes = base64_decode($b64);
            if ($bytes === false) {
                throw new RuntimeException('Gemini did not return a valid image.');
            }
            file_put_contents($outputPath, $bytes);
        } else {
            $this->drawMockWorldMother(null, $outputPath, $analysis, $categorySlug);
        }

        $auditDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'world-mother-generation-audit';
        if (!is_dir($auditDir)) {
            mkdir($auditDir, 0775, true);
        }
        $auditName = $categorySlug . '_' . $stamp . '.auto-generation.json';
        $auditPath = $auditDir . DIRECTORY_SEPARATOR . $auditName;
        $audit = [
            'schema' => 'world_mother_auto_generation_audit.v1',
            'generated_at' => date(DATE_ATOM),
            'mode' => ProviderSettings::isRealMode() ? ProviderSettings::imageProvider() : 'mock',
            'category_slug' => $categorySlug,
            'reference_path' => null,
            'output_path' => $outputPath,
            'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
            'analysis' => $analysis,
            'prompt' => $prompt,
            'options' => $options,
        ];
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'category_slug' => $categorySlug,
            'file_name' => $fileName,
            'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
            'absolute_path' => $outputPath,
            'audit_file' => 'analysis/world-mother-generation-audit/' . $auditName,
            'auto_generated' => true,
        ];
    }

    public static function safeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        $value = trim($value, '_');
        return substr($value, 0, 80);
    }

    /**
     * @return array<string,mixed>
     */
    private function analyzeWithGemini(string $imagePath, array $metadata): array
    {
        $knownCategories = implode(', ', array_map(
            static fn (array $category): string => (string)$category['category_slug'],
            $this->library->categories()
        ));
        $notes = trim((string)($metadata['notes'] ?? ''));
        $prompt = "Analyze this uploaded interior/reference image as a WORLD MOTHER source for future artwork mockups.\n"
            . "Return strict JSON only with keys: scene_type, architecture_language, wall_language, floor_language, ceiling_language, lighting, materials, palette, mood, scale, camera_potential, negative_risks, style_details, category_keywords.\n"
            . "A World Mother must be an environment reference, not a finished artwork mockup. Identify if the image contains paintings, frames, people, animals, logos, text, clutter, or anything that should be removed in a clean generated world.\n"
            . "Known existing category slugs: {$knownCategories}\n"
            . "User notes: {$notes}";

        $text = $this->client->generateText([
            $this->client->textPart($prompt),
            $this->client->imagePart($imagePath),
        ], 'gemini-2.5-flash');

        $json = $this->extractJson($text);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('World Mother analysis did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackAnalysis(string $imagePath, array $metadata): array
    {
        $name = strtolower(str_replace(['-', '_'], ' ', pathinfo($imagePath, PATHINFO_FILENAME)));
        $notes = strtolower((string)($metadata['notes'] ?? ''));
        $text = $name . ' ' . $notes;

        $scene = 'collector interior';
        if (str_contains($text, 'loft') || str_contains($text, 'industrial')) {
            $scene = 'industrial loft';
        } elseif (str_contains($text, 'gallery') || str_contains($text, 'museum')) {
            $scene = 'gallery or museum space';
        } elseif (str_contains($text, 'atelier') || str_contains($text, 'studio')) {
            $scene = 'artist atelier';
        } elseif (str_contains($text, 'palazzo') || str_contains($text, 'belle') || str_contains($text, 'historic')) {
            $scene = 'historic collector room';
        }

        return [
            'scene_type' => $scene,
            'architecture_language' => $scene,
            'wall_language' => 'clean artwork-ready wall surfaces inferred from the uploaded reference',
            'floor_language' => 'floor plane suitable for realistic scale and camera grounding',
            'ceiling_language' => 'ceiling and overhead geometry inferred from the uploaded reference',
            'lighting' => 'natural or architectural light inferred from the reference',
            'materials' => ['plaster', 'concrete', 'wood', 'stone'],
            'palette' => ['neutral', 'warm', 'material'],
            'mood' => ['quiet', 'collector', 'artwork-first'],
            'scale' => 'suitable for XL artwork mockups',
            'camera_potential' => ['frontal', 'three-quarter', 'low angle', 'high angle'],
            'negative_risks' => ['remove existing artwork', 'remove logos/text', 'avoid clutter', 'avoid people and animals'],
            'style_details' => [
                'spatial_signature' => 'reference-led interior with clean wall/floor geometry',
                'commercial_use' => 'world mother background for future artwork mockups',
            ],
            'category_keywords' => preg_split('/\s+/', trim($text)) ?: [],
        ];
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string,mixed>
     */
    private function normalizeAnalysis(array $analysis, string $imagePath, array $metadata): array
    {
        foreach (['materials', 'palette', 'mood', 'camera_potential', 'negative_risks', 'category_keywords'] as $key) {
            $analysis[$key] = is_array($analysis[$key] ?? null)
                ? array_values(array_filter(array_map('strval', $analysis[$key])))
                : array_filter(array_map('trim', explode(',', (string)($analysis[$key] ?? ''))));
        }
        $analysis['reference_file'] = basename($imagePath);
        $analysis['user_notes'] = trim((string)($metadata['notes'] ?? ''));
        return $analysis;
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<int,array<string,mixed>>
     */
    private function rankCategories(array $analysis): array
    {
        $text = strtolower($this->analysisText($analysis));
        $ranked = [];
        foreach ($this->library->categories() as $category) {
            $slug = (string)$category['category_slug'];
            $score = 0;
            $matched = [];
            foreach (preg_split('/[_\-\s]+/', $slug) ?: [] as $token) {
                $token = strtolower(trim($token));
                if (strlen($token) >= 4 && str_contains($text, $token)) {
                    $score += 4;
                    $matched[] = $token;
                }
            }
            foreach ((array)($analysis['category_keywords'] ?? []) as $keyword) {
                $keyword = strtolower(trim((string)$keyword));
                if (strlen($keyword) >= 4 && str_contains(str_replace('_', ' ', $slug), $keyword)) {
                    $score += 3;
                    $matched[] = $keyword;
                }
            }
            if ((int)($category['image_count'] ?? 0) === 0) {
                $score += 1;
            }
            $ranked[] = [
                'category_slug' => $slug,
                'category_name' => $category['category_name'],
                'image_count' => (int)$category['image_count'],
                'score' => $score,
                'matched_terms' => array_values(array_unique($matched)),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => ((int)$b['score'] <=> (int)$a['score']) ?: strcmp((string)$a['category_slug'], (string)$b['category_slug']));
        return array_slice($ranked, 0, 8);
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function suggestNewCategory(array $analysis): string
    {
        $basis = implode(' ', array_filter([
            $analysis['scene_type'] ?? '',
            $analysis['architecture_language'] ?? '',
            is_array($analysis['mood'] ?? null) ? implode(' ', $analysis['mood']) : '',
        ]));
        $slug = self::safeSlug($basis);
        return $slug !== '' ? $slug : 'new_world_mother';
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function buildGenerationPrompt(array $analysis, string $categorySlug, array $options): string
    {
        $notes = trim((string)($options['notes'] ?? ''));
        $referenceDirective = !empty($options['reference_free'])
            ? "No reference image is provided. Build the scene from the category, artwork analysis, and context metadata only.\n"
            : "Use the uploaded reference image only as architectural/style guidance. Do not copy it exactly.\n";
        return "Create one original WORLD MOTHER reference image for future artwork mockups.\n"
            . "Category: {$categorySlug}\n"
            . $referenceDirective
            . "Generate a clean, realistic, artwork-ready environment with strong wall/floor/lighting geometry, suitable for inserting a separate artwork later.\n"
            . "Do not include paintings, framed art, posters, logos, readable text, people, animals, or dominant decorative objects. Leave usable wall space.\n"
            . "Scene type: " . (string)($analysis['scene_type'] ?? '') . "\n"
            . "Architecture: " . (string)($analysis['architecture_language'] ?? '') . "\n"
            . "Walls: " . (string)($analysis['wall_language'] ?? '') . "\n"
            . "Floor: " . (string)($analysis['floor_language'] ?? '') . "\n"
            . "Ceiling: " . (string)($analysis['ceiling_language'] ?? '') . "\n"
            . "Lighting: " . (string)($analysis['lighting'] ?? '') . "\n"
            . "Materials: " . implode(', ', (array)($analysis['materials'] ?? [])) . "\n"
            . "Palette: " . implode(', ', (array)($analysis['palette'] ?? [])) . "\n"
            . "Mood: " . implode(', ', (array)($analysis['mood'] ?? [])) . "\n"
            . "Camera potential: " . implode(', ', (array)($analysis['camera_potential'] ?? [])) . "\n"
            . "Avoid: " . implode(', ', (array)($analysis['negative_risks'] ?? [])) . "\n"
            . "User generation notes: {$notes}";
    }

    /**
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function normalizeCategoryOnlyAnalysis(string $categorySlug, array $analysis, array $options): array
    {
        $categoryTitle = ucwords(str_replace('_', ' ', $categorySlug));
        $contextTitle = trim((string)($options['context_title'] ?? ''));
        $contextDescription = trim((string)($options['context_description'] ?? ''));
        $artworkSummary = trim((string)($options['artwork_analysis_text'] ?? ''));

        return array_replace([
            'scene_type' => $contextTitle !== '' ? $contextTitle : $categoryTitle,
            'architecture_language' => $categoryTitle . ' architecture interpreted as a premium artwork-ready environment',
            'wall_language' => 'clean generous wall planes suitable for hanging a separate artwork',
            'floor_language' => 'credible floor plane with realistic perspective and scale grounding',
            'ceiling_language' => 'architectural ceiling geometry that supports the selected camera slot',
            'lighting' => 'refined natural or architectural light that keeps artwork placement readable',
            'materials' => ['plaster', 'wood', 'stone', 'concrete', 'metal'],
            'palette' => ['neutral', 'collector-grade', 'material-led'],
            'mood' => ['quiet', 'premium', 'artwork-first'],
            'scale' => 'suitable for believable artwork mockups',
            'camera_potential' => ['frontal', 'three-quarter', 'low angle', 'high angle'],
            'negative_risks' => ['no existing artwork', 'no logos', 'no readable text', 'no people', 'no clutter'],
            'style_details' => [
                'source' => 'auto-generated from category because the world mother folder had no usable image',
                'context_description' => $contextDescription,
                'artwork_analysis_summary' => $artworkSummary,
            ],
            'category_keywords' => preg_split('/[_\-\s]+/', $categorySlug) ?: [],
        ], $analysis);
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function drawMockWorldMother(?string $referencePath, string $outputPath, array $analysis, string $categorySlug): void
    {
        $im = imagecreatetruecolor(1536, 1024);
        $bg = imagecolorallocate($im, 238, 235, 229);
        $wall = imagecolorallocate($im, 224, 220, 211);
        $floor = imagecolorallocate($im, 198, 190, 178);
        $line = imagecolorallocate($im, 128, 119, 104);
        $ink = imagecolorallocate($im, 28, 28, 28);
        imagefill($im, 0, 0, $bg);
        imagefilledrectangle($im, 0, 0, 1536, 640, $wall);
        imagefilledpolygon($im, [0, 640, 1536, 640, 1536, 1024, 0, 1024], 4, $floor);
        imageline($im, 0, 640, 1536, 640, $line);
        imageline($im, 260, 160, 260, 640, $line);
        imageline($im, 1276, 160, 1276, 640, $line);
        imagerectangle($im, 520, 250, 1016, 570, $line);
        imagestring($im, 5, 560, 690, 'WORLD MOTHER MOCK REFERENCE', $ink);
        imagestring($im, 4, 560, 725, 'Category: ' . substr($categorySlug, 0, 60), $ink);
        imagestring($im, 3, 560, 755, 'Source: ' . ($referencePath ? substr(basename($referencePath), 0, 70) : 'category auto-generation'), $ink);
        imagestring($im, 3, 560, 785, substr((string)($analysis['scene_type'] ?? ''), 0, 90), $ink);
        imagepng($im, $outputPath, 6);
        imagedestroy($im);
    }

    private function extractJson(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', (string)$text);
        $start = strpos((string)$text, '{');
        $end = strrpos((string)$text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr((string)$text, $start, $end - $start + 1);
        }
        return (string)$text;
    }

    /**
     * @param array<string,mixed> $analysis
     */
    private function analysisText(array $analysis): string
    {
        $parts = [];
        array_walk_recursive($analysis, static function ($value) use (&$parts): void {
            if (is_scalar($value)) {
                $parts[] = (string)$value;
            }
        });
        return implode(' ', $parts);
    }
}
