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
     * @param array<int,string> $imagePaths
     * @return array<string,mixed>
     */
    public function analyzeReferences(array $imagePaths, array $metadata = []): array
    {
        $imagePaths = array_values(array_filter(array_map('strval', $imagePaths), static fn (string $path): bool => is_file($path)));
        if (!$imagePaths) {
            throw new RuntimeException('World mother reference images not found.');
        }
        if (count($imagePaths) > 4) {
            throw new RuntimeException('World Mother Studio accepts up to 4 reference images.');
        }
        if (count($imagePaths) === 1) {
            $analysis = $this->analyzeReference($imagePaths[0], $metadata);
            $analysis['reference_paths'] = $imagePaths;
            return $analysis;
        }

        $analysis = ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini'
            ? $this->analyzeReferencesWithGemini($imagePaths, $metadata)
            : $this->fallbackAnalysis($imagePaths[0], $metadata);

        $analysis = $this->normalizeAnalysis($analysis, $imagePaths[0], $metadata);
        $analysis['reference_paths'] = $imagePaths;
        $analysis['reference_files'] = array_map('basename', $imagePaths);
        $analysis['category_candidates'] = $this->rankCategories($analysis);
        $analysis['new_category_suggestion'] = $this->suggestNewCategory($analysis);
        $analysis['analysis_source'] = ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini'
            ? 'gemini_multi_reference'
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
            ], null, $this->precompositionOverride());
            $bytes = base64_decode($b64);
            if ($bytes === false) {
                throw new RuntimeException('Gemini did not return a valid image.');
            }
            file_put_contents($outputPath, $bytes);
        } else {
            $this->drawMockWorldMother($referencePath, $outputPath, $analysis, $categorySlug);
        }
        $this->persistGeneratedImage('storage/world_mothers/' . $categorySlug . '/' . $fileName, $outputPath);

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

        $this->library->rebuildIndex();

        return [
            'category_slug' => $categorySlug,
            'file_name' => $fileName,
            'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
            'absolute_path' => $outputPath,
            'audit_file' => 'analysis/world-mother-generation-audit/' . $auditName,
        ];
    }

    /**
     * @param array<int,string> $referencePaths
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function generateOriginalWorldMotherSet(array $referencePaths, string $categorySlug, array $analysis, array $options = []): array
    {
        $referencePaths = array_values(array_filter(array_map('strval', $referencePaths), static fn (string $path): bool => is_file($path)));
        if (!$referencePaths) {
            throw new RuntimeException('Reference images not found.');
        }
        if (count($referencePaths) > 4) {
            throw new RuntimeException('World Mother Studio accepts up to 4 reference images.');
        }

        $categorySlug = self::safeSlug($categorySlug);
        if ($categorySlug === '') {
            throw new RuntimeException('A category is required.');
        }

        $count = max(1, min(8, (int)($options['count'] ?? 4)));
        $categoryDir = $this->library->basePath() . DIRECTORY_SEPARATOR . $categorySlug;
        if (!is_dir($categoryDir) && !mkdir($categoryDir, 0775, true) && !is_dir($categoryDir)) {
            throw new RuntimeException('Could not create world mother category folder.');
        }

        $stamp = date('Ymd_His') . '_' . random_int(1000, 9999);
        $variantRoles = $this->worldMotherVariantRoles();
        $basePrompt = $this->buildGenerationPrompt($analysis, $categorySlug, $options)
            . "\nGenerate a coherent set of world mother references. Each output belongs to the same world identity but must solve a different mockup use case."
            . "\nThe references are ingredients, not the destination. Build a new environment world that could plausibly generate many different camera views later."
            . "\nDo not make small retouches of the uploaded references. Synthesize them into a richer original environment with stronger architectural, material, lighting, and spatial identity."
            . "\nAcross the set, avoid repeating the same camera, wall, ceiling, window, furniture, and object layout. The variants must feel related, not cloned."
            . "\nMost variants must be spatially enriched by perspective: diagonal views, oblique walls, receding floor lines, visible depth layers, architectural rhythm, foreground/midground/background separation, and clear vanishing direction. Avoid a set dominated by flat frontal rooms.";

        $images = [];
        for ($i = 1; $i <= $count; $i++) {
            $variantRole = $variantRoles[($i - 1) % count($variantRoles)];
            $fileName = 'world_mother_' . $stamp . '_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '.png';
            $outputPath = $categoryDir . DIRECTORY_SEPARATOR . $fileName;
            $prompt = $basePrompt
                . "\n\nVARIANT {$i} OF {$count}: " . $variantRole['label']
                . "\nPurpose: " . $variantRole['purpose']
                . "\nComposition: " . $variantRole['composition']
                . "\nKeep the same world identity, but vary camera position, useful wall/floor geometry, depth, light angle, object arrangement, and architectural emphasis."
                . "\nThis variant must be usable as a future mockup environment reference.";

            if (ProviderSettings::isRealMode() && ProviderSettings::imageProvider() === 'gemini') {
                $parts = [$this->client->textPart($prompt)];
                foreach ($referencePaths as $referencePath) {
                    $parts[] = $this->client->imagePart($referencePath);
                }
                $b64 = $this->client->generateImage($parts, null, $this->precompositionOverride());
                $bytes = base64_decode($b64);
                if ($bytes === false) {
                    throw new RuntimeException('Gemini did not return a valid image.');
                }
                file_put_contents($outputPath, $bytes);
            } else {
                $this->drawMockWorldMother($referencePaths[($i - 1) % count($referencePaths)], $outputPath, $analysis, $categorySlug);
            }
            $this->persistGeneratedImage('storage/world_mothers/' . $categorySlug . '/' . $fileName, $outputPath);

            $images[] = [
                'file_name' => $fileName,
                'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
                'absolute_path' => $outputPath,
                'variant_index' => $i,
                'variant_role' => $variantRole['id'],
            ];
        }

        $auditDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'world-mother-generation-audit';
        if (!is_dir($auditDir)) {
            mkdir($auditDir, 0775, true);
        }
        $auditName = $categorySlug . '_' . $stamp . '.set-generation.json';
        $auditPath = $auditDir . DIRECTORY_SEPARATOR . $auditName;
        $audit = [
            'schema' => 'world_mother_set_generation_audit.v1',
            'generated_at' => date(DATE_ATOM),
            'mode' => ProviderSettings::isRealMode() ? ProviderSettings::imageProvider() : 'mock',
            'category_slug' => $categorySlug,
            'reference_paths' => $referencePaths,
            'images' => $images,
            'analysis' => $analysis,
            'base_prompt' => $basePrompt,
            'options' => $options,
        ];
        file_put_contents($auditPath, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->library->rebuildIndex();

        return [
            'category_slug' => $categorySlug,
            'images' => $images,
            'relative_path' => (string)($images[0]['relative_path'] ?? ''),
            'absolute_path' => (string)($images[0]['absolute_path'] ?? ''),
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
            ], null, $this->precompositionOverride());
            $bytes = base64_decode($b64);
            if ($bytes === false) {
                throw new RuntimeException('Gemini did not return a valid image.');
            }
            file_put_contents($outputPath, $bytes);
        } else {
            $this->drawMockWorldMother(null, $outputPath, $analysis, $categorySlug);
        }
        $this->persistGeneratedImage('storage/world_mothers/' . $categorySlug . '/' . $fileName, $outputPath);

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

        $this->library->rebuildIndex();

        return [
            'category_slug' => $categorySlug,
            'file_name' => $fileName,
            'relative_path' => 'storage/world_mothers/' . $categorySlug . '/' . $fileName,
            'absolute_path' => $outputPath,
            'audit_file' => 'analysis/world-mother-generation-audit/' . $auditName,
            'auto_generated' => true,
        ];
    }

    /**
     * World mother generation prompts always mention "mockups" (they describe the future
     * use case), which makes vertex_bridge.py classify these calls as is_mockup=True. If a
     * call sends a single reference image, that would make the mockup precomposition/fill_ratio
     * block (gated on a single image + is_mockup + MOCKUP_USE_PRECOMPOSITION) structurally
     * reachable, even though it makes no sense here — there is no artwork or "X cm wide x Y cm
     * high" size line in this prompt, so it would just paste the reference onto a grey square
     * before generating. Forced off for every call in this class, independent of the global
     * flag (which is already false today) — see docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md, Fase 6.
     *
     * @return array<string,string>
     */
    private function precompositionOverride(): array
    {
        return ['MOCKUP_USE_PRECOMPOSITION' => 'false'];
    }

    private function persistGeneratedImage(string $relativePath, string $absolutePath): void
    {
        if (StorageService::isGcsActive() && !StorageService::uploadFile($relativePath, $absolutePath)) {
            throw new RuntimeException('The generated scene reference could not be saved to persistent storage.');
        }
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
     * @param array<int,string> $imagePaths
     * @return array<string,mixed>
     */
    private function analyzeReferencesWithGemini(array $imagePaths, array $metadata): array
    {
        $knownCategories = implode(', ', array_map(
            static fn (array $category): string => (string)$category['category_slug'],
            $this->library->categories()
        ));
        $notes = trim((string)($metadata['notes'] ?? ''));
        $prompt = "Analyze these uploaded interior/reference images as one WORLD MOTHER source set for future artwork mockups.\n"
            . "Synthesize their shared usable world identity. Return strict JSON only with keys: scene_type, architecture_language, wall_language, floor_language, ceiling_language, lighting, materials, palette, mood, scale, camera_potential, negative_risks, style_details, category_keywords.\n"
            . "A World Mother must be an environment reference, not a finished artwork mockup. Identify if the images contain paintings, frames, people, animals, logos, text, clutter, or anything that should be removed in clean generated worlds.\n"
            . "Known existing category slugs: {$knownCategories}\n"
            . "User world guidelines: {$notes}";

        $parts = [$this->client->textPart($prompt)];
        foreach ($imagePaths as $imagePath) {
            $parts[] = $this->client->imagePart($imagePath);
        }

        $text = $this->client->generateText($parts, 'gemini-2.5-flash');
        $json = $this->extractJson($text);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('World Mother multi-reference analysis did not return valid JSON.');
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
            'scale' => 'suitable for believable artwork mockups at the supplied artwork dimensions',
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
        $basis = strtolower(implode(' ', array_filter([
            $analysis['user_notes'] ?? '',
            implode(' ', (array)($analysis['category_keywords'] ?? [])),
            $analysis['scene_type'] ?? '',
            $analysis['architecture_language'] ?? '',
            is_array($analysis['mood'] ?? null) ? implode(' ', $analysis['mood']) : '',
        ])));
        $tokens = preg_split('/[^a-z0-9]+/', $basis) ?: [];
        $stopWords = array_flip([
            'a', 'an', 'and', 'area', 'as', 'at', 'for', 'from', 'in', 'is', 'likely', 'of', 'or', 'space', 'the',
            'within', 'with', 'room', 'rooms', 'interior', 'interiors', 'living', 'reception', 'hall', 'house',
            'residence', 'residential', 'grand', 'large', 'small', 'world', 'mother', 'reference', 'image',
        ]);
        $selected = [];
        foreach ($tokens as $token) {
            $token = trim((string)$token);
            if (strlen($token) < 4 || isset($stopWords[$token]) || in_array($token, $selected, true)) {
                continue;
            }
            $selected[] = $token;
            if (count($selected) >= 4) {
                break;
            }
        }

        $slug = self::safeSlug(implode('_', $selected));
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
            : "Use the uploaded reference image set as visual language, mood, material direction, architectural clues, lighting clues, and spatial inspiration. Do not copy it, trace it, lightly clean it, preserve its exact layout, or keep its exact camera angle and object placement.\n";
        return "Create one original WORLD MOTHER reference image for future artwork mockups.\n"
            . "Category: {$categorySlug}\n"
            . $referenceDirective
            . "Your task is to invent a richer and more useful environment world from those inputs. The result must feel like a designed world mother, not an edited version of the reference image.\n"
            . "If the user provides a named style, place, architecture, era, mood, material, artist movement, or cultural reference, expand that world intelligently with coherent architectural vocabulary, surfaces, light behavior, furniture/object language, atmosphere, and spatial depth. Do not reduce the concept to one obvious repeated motif.\n"
            . "If the user prompt is weak, generic, or empty, still create a sophisticated, photorealistic, premium environment with strong world identity, layered materials, believable light, clear scale, and enough spatial variety for multiple future camera slots.\n"
            . "Generate an artwork-ready environment with memorable world identity, strong wall/floor/lighting geometry, depth layers, material contrast, atmosphere, and useful negative space.\n"
            . "Favor environments with real perspective and spatial travel: diagonal room axes, receding floor or ceiling lines, side planes, corridors, mezzanines, openings, stairs, windows, columns, or furniture alignment that create depth. Avoid static flat-on symmetrical room records unless one variant explicitly needs a primary wall.\n"
            . "Always leave at least one credible usable artwork zone: wall, floor-leaning area, easel-adjacent area, or architectural plane where a separate artwork can later be inserted.\n"
            . "Do not include paintings, framed art, posters, logos, readable text, people, animals, or dominant decorative objects. Avoid overfilled clutter, avoid empty showroom sterility unless requested, and avoid furniture or props becoming the subject.\n"
            . "Make the result useful for later camera transformations: frontal, oblique, detail, low angle, aerial, and close-up views.\n"
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
            . "User generation notes: {$notes}\n"
            . "Preserve the world identity more than the exact uploaded composition.";
    }

    /**
     * @return array<int,array{id:string,label:string,purpose:string,composition:string}>
     */
    private function worldMotherVariantRoles(): array
    {
        return [
            [
                'id' => 'primary_wall',
                'label' => 'Primary Artwork Wall',
                'purpose' => 'Main reference for wall-mounted artwork mockups with clear usable wall, believable floor contact, and strong room identity.',
                'composition' => 'Medium-wide premium interior view, clear artwork-ready wall or architectural plane, visible floor contact, coherent light, and enough surrounding objects to define the world without clutter. Even when frontal, include subtle perspective through side planes, floor depth, ceiling rhythm, or diagonal light.',
            ],
            [
                'id' => 'oblique_depth',
                'label' => 'Oblique Depth View',
                'purpose' => 'Reference for side, 3/4, corridor, and depth-based camera slots.',
                'composition' => 'Strong diagonal or side-oriented space with foreground, midground, and background layers, visible wall/floor/ceiling geometry, receding depth lines, side planes, and a useful artwork zone that can be approached from an angle.',
            ],
            [
                'id' => 'light_drama',
                'label' => 'Light Drama View',
                'purpose' => 'Reference for golden-hour, blue-hour, shadow, reflection, and atmospheric light mockups.',
                'composition' => 'Same world identity with distinctive natural or artificial light, shadow pattern, glow, reflection, texture reveal, or atmospheric contrast while preserving an artwork-ready zone. Use light and shadow to describe perspective, depth, diagonals, and spatial planes.',
            ],
            [
                'id' => 'architectural_context',
                'label' => 'Architectural Context View',
                'purpose' => 'Reference for wider environmental cameras that need richer surrounding architecture and spatial identity.',
                'composition' => 'Wider environmental view showing the strongest architectural identity of the world: ceiling, openings, columns, arches, windows, structural rhythm, furniture, spatial transitions, material rhythm, and deep room perspective, while keeping a credible artwork placement area.',
            ],
        ];
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
