<?php
declare(strict_types=1);

class GeminiMockupGenerator implements MockupGeneratorInterface
{
    private const OUTPUT_ASPECT_RATIO = '4:5';

    private GeminiImageClient $client;

    public function __construct(?GeminiImageClient $client = null)
    {
        $this->client = $client ?: new GeminiImageClient();
    }

    public function generate(string $imagePath, string $contextId, string $prompt, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontro la imagen raiz para el mockup.');
        }

        $resultsDir = RESULTS_DIR;
        $promptsDir = PROMPTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }
        if (!is_dir($promptsDir)) {
            mkdir($promptsDir, 0775, true);
        }

        $t0 = microtime(true);
        Logger::log("Iniciando generacion de mockup Gemini. Contexto: {$contextId}, Obra: " . basename($imagePath), 'gemini');

        $slotFullPromptMode = !empty($metadata['slot_full_prompt_mode']);
        $finalPrompt = $this->finalPrompt($contextId, $prompt, $metadata);
        $cameraSlotId = $this->cameraSlotId($finalPrompt, $metadata);
        $usesGraphicPerspectiveSlot = $this->usesGraphicPerspectiveSlot($cameraSlotId);
        $usesGraphicPerspectiveGeminiDirect = $usesGraphicPerspectiveSlot && $this->graphicPerspectiveMode($cameraSlotId) === 'gemini_direct';
        $roleContract = "IMAGE ROLE CONTRACT:\n"
            . "- AUTHORITY 1 - ROOT ARTWORK: IMAGE 1 is the product authority. Preserve its exact artwork content, colors, marks, composition, texture, proportions, format, and visual identity.\n"
            . "- The final mockup must contain the same recognizable artwork from IMAGE 1, not a similar painting, not a newly painted abstract surface, not a style transfer, not a botanical/gestural reinterpretation, and not artwork invented from the room or world mother.\n"
            . "- Treat IMAGE 1 as visual evidence, not as a text prompt. Do not reconstruct the artwork from descriptive memory. Copy the visible arrangement: exact color-field placement, empty areas, mark count, mark location, mark scale, edge relationships, texture density, and surface rhythm.\n"
            . "- For uploaded root artwork, any mismatch in composition, texture density, mark placement, color balance, orientation, or physical scale is a failed mockup. If fidelity conflicts with a dramatic room or camera idea, simplify the room/camera and keep the artwork exact.\n"
            . "- If IMAGE 1 is abstract, minimal, sparse, or contains simple marks, preserve that exact sparse composition. Do not fill empty areas with extra brushstrokes, leaves, decorative marks, figures, patterns, symbols, or painterly noise.\n"
            . "- AUTHORITY 2 - CAMERA SLOT: the selected camera slot is the photographic authority for the final image. It controls viewpoint, lens behavior, camera height, distance, crop, angle, perspective, and composition.\n"
            . "- AUTHORITY 3 - WORLD MOTHER: IMAGE 2, when present, is not the environment to reproduce and not a camera reference. It is visual evidence for building a new compatible environment in the same material, lighting, palette, architectural mood, and atmospheric family.\n"
            . "- Transform the world mother visual language through the selected camera slot. You may move, replace, or reinvent windows, walls, furniture, objects, depth, and room geometry whenever needed to obey the camera slot and serve IMAGE 1.\n"
            . "- Do not copy IMAGE 2's camera angle, crop, object positions, wall choice, window placement, room geometry, furniture placement, source-photo composition, or room layout.\n"
            . "- Keep IMAGE 2 out of the artwork identity. The environment may provide atmosphere only; it must never donate new brushwork, canvas texture, colors, symbols, objects, or composition to the ROOT ARTWORK.\n"
            . "- Never replace the ROOT ARTWORK with a blank wall, empty canvas, decorative panel or object from the WORLD MOTHER.\n"
            . "- All written dimensions and measurements are hidden instructions only. Never render visible text, captions, labels, measurement callouts, arrows, rulers, scale bars, unit labels, or numeric size annotations in the generated image.\n"
            . "- If the camera or environment conflicts with the artwork, preserve IMAGE 1 and adapt the environment around it.";
        $outputAspectRatio = self::OUTPUT_ASPECT_RATIO;
        $outputFrameContract = "GLOBAL OUTPUT FRAME RULE:\n"
            . "- The final generated image must use an exact {$outputAspectRatio} portrait aspect ratio.\n"
            . "- Compose the complete photographic scene inside this standard catalog and social-feed frame. Do not inherit the aspect ratio of any reference image.\n"
            . "- Do not output square, landscape, panoramic, 3:4, 2:3, 9:16, or other frame proportions.\n"
            . "- Keep the artwork fully inside the {$outputAspectRatio} composition unless the selected close-up camera explicitly requires a detail crop.";
        $submittedPrompt = ($slotFullPromptMode || $usesGraphicPerspectiveGeminiDirect) ? $finalPrompt : $roleContract . "\n\n" . $finalPrompt;
        $submittedPrompt = $outputFrameContract . "\n\n" . $submittedPrompt;
        $worldMotherReferencePath = (string)($metadata['world_mother_reference_path'] ?? '');
        if ($worldMotherReferencePath !== '' && is_file($worldMotherReferencePath)) {
            $submittedPrompt = WorldMotherCameraAuthorityPolicy::applyToPrompt($submittedPrompt, $cameraSlotId);
        }
        $parts = [$this->client->textPart($submittedPrompt)];
        if ($usesGraphicPerspectiveGeminiDirect) {
            $platePath = $this->graphicPerspectivePlatePath($cameraSlotId);
            if ($platePath === '' || !is_file($platePath)) {
                throw new RuntimeException('No se encontro la placa grafica de perspectiva para el slot: ' . $cameraSlotId);
            }
            $parts[] = $this->client->textPart("IMAGE 1 - PERSPECTIVE MASK: green area is the exact artwork plane; black area is ignored.");
            $parts[] = $this->client->imagePart($platePath);
            $parts[] = $this->client->textPart("IMAGE 2 - ROOT ARTWORK: place this artwork into the green plane.");
            $parts[] = $this->client->imagePart($imagePath);
        } elseif ($usesGraphicPerspectiveSlot) {
            $platePath = $this->graphicPerspectivePlatePath($cameraSlotId);
            if ($platePath === '' || !is_file($platePath)) {
                throw new RuntimeException('No se encontro la placa grafica de perspectiva para el slot: ' . $cameraSlotId);
            }
            $parts[] = $this->client->imagePart($imagePath);
        } else {
            $parts[] = $this->client->imagePart($imagePath);
        }
        if (!$slotFullPromptMode && !$usesGraphicPerspectiveSlot) {
            array_splice($parts, 1, 0, [
                $this->client->textPart("IMAGE 1 - ROOT ARTWORK: exact artwork to preserve inside the mockup."),
            ]);
        }
        $rootReferencePath = (string)($metadata['root_reference_path'] ?? '');
        if (!$usesGraphicPerspectiveSlot && $rootReferencePath !== '' && is_file($rootReferencePath) && realpath($rootReferencePath) !== realpath($imagePath)) {
            if (!$slotFullPromptMode) {
                $parts[] = $this->client->textPart("ADDITIONAL ROOT ARTWORK REFERENCE: same artwork identity, still authoritative for the artwork only.");
            }
            $parts[] = $this->client->imagePart($rootReferencePath);
        }
        if ($worldMotherReferencePath !== '' && is_file($worldMotherReferencePath)) {
            if ($usesGraphicPerspectiveGeminiDirect) {
                $parts[] = $this->client->textPart("IMAGE 3 - STYLE REFERENCE: atmosphere, materials, lighting, and color temperature only.");
            } elseif ($usesGraphicPerspectiveSlot) {
                $parts[] = $this->client->textPart("WORLD MOTHER: environment mood reference only.");
            } else {
                $parts[] = $this->client->textPart("IMAGE 2 - WORLD MOTHER: environmental inspiration only. Use materiality, light, palette, surface language, architectural mood, and atmosphere to build a new environment through the selected camera slot. Do not copy this image's layout, camera angle, crop, room geometry, wall choice, window placement, object positions, or furniture placement. Do not use this image as artwork content.");
            }
            $parts[] = $this->client->imagePart($worldMotherReferencePath);
        }

        try {
            // Forces MOCKUP_USE_PRECOMPOSITION=false for this specific call regardless of
            // the global config, for callers that send a single reference image (where the
            // precomposition/fill_ratio block in vertex_bridge.py would otherwise be reachable
            // if that global flag were ever re-enabled — see docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md,
            // Fase 5). Today MOCKUP_USE_PRECOMPOSITION is already false everywhere, so this is a
            // no-op in practice; it removes the dependency on that flag staying off by accident.
            $usesInpaintingCamera = $this->usesInpaintingCamera($cameraSlotId);
            $envOverrides = [
                'GEMINI_OUTPUT_ASPECT_RATIO' => self::OUTPUT_ASPECT_RATIO,
            ];
            $modelOverride = null;

            if ($usesInpaintingCamera) {
                $envOverrides = [
                    'GEMINI_OUTPUT_ASPECT_RATIO' => self::OUTPUT_ASPECT_RATIO,
                    'MOCKUP_PROMPT_FIRST_MODE' => 'false',
                    'MOCKUP_USE_PRECOMPOSITION' => 'true',
                    'MOCKUP_USE_BACKGROUND_EDIT' => 'false',
                    'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => 'false',
                    // Camera 15 nadir precomposition uses orientation-specific inpainting
                    // plates for portrait, square, and landscape artwork.
                    'MOCKUP_USE_NADIR_POLYGON' => 'true',
                ];
                $cam15Scale = app_env('MOCKUP_SCALE_CAMARA_15', '');
                if ($cam15Scale !== '') {
                    $envOverrides['MOCKUP_SCALE_CAMARA_15'] = $cam15Scale;
                }
                $worldMotherScale = trim((string)($metadata['world_mother_scale'] ?? ''));
                if ($worldMotherScale !== '') {
                    $envOverrides['MOCKUP_WORLD_MOTHER_SCALE'] = $worldMotherScale;
                }
                $modelOverride = (string)($metadata['image_model'] ?? 'imagen-3.0-capability-001');
            } elseif ($usesGraphicPerspectiveGeminiDirect) {
                $envOverrides = [
                    'GEMINI_OUTPUT_ASPECT_RATIO' => self::OUTPUT_ASPECT_RATIO,
                    'MOCKUP_USE_PRECOMPOSITION' => 'false',
                    'MOCKUP_USE_BACKGROUND_EDIT' => 'false',
                    'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => 'false',
                ];
                $modelOverride = $this->graphicPerspectiveImageModel($cameraSlotId);
            } elseif ($usesGraphicPerspectiveSlot) {
                $envOverrides = [
                    'GEMINI_OUTPUT_ASPECT_RATIO' => self::OUTPUT_ASPECT_RATIO,
                    'MOCKUP_PROMPT_FIRST_MODE' => 'false',
                    'MOCKUP_USE_PRECOMPOSITION' => 'true',
                    'MOCKUP_USE_BACKGROUND_EDIT' => 'false',
                    'MOCKUP_PROMPT_FIRST_NO_MASK_MODE' => 'false',
                    'MOCKUP_GRAPHIC_PERSPECTIVE_PLATE' => $this->graphicPerspectivePlatePath($cameraSlotId),
                    'MOCKUP_MASK_DILATION' => '0',
                ];
                $modelOverride = $this->graphicPerspectiveImageModel($cameraSlotId);
            } elseif (!empty($metadata['force_disable_precomposition'])) {
                $envOverrides = [
                    'GEMINI_OUTPUT_ASPECT_RATIO' => self::OUTPUT_ASPECT_RATIO,
                    'MOCKUP_USE_PRECOMPOSITION' => 'false',
                ];
            }

            $b64 = $this->client->generateImage($parts, $modelOverride, $envOverrides);
            $imageData = base64_decode($b64);

            if ($imageData === false) {
                throw new RuntimeException('Gemini no devolvio una imagen base64 valida para el mockup.');
            }

            $seoParams = $metadata['seo_params'] ?? null;
            if ($seoParams) {
                $outputName = Display::generateSeoImageFilename($seoParams, $resultsDir);
                if (pathinfo($outputName, PATHINFO_EXTENSION) === 'jpg') {
                    $imageData = Display::convertPngToJpg($imageData);
                }
            } else {
                $stamp = time() . '_' . random_int(1000, 9999);
                $outputName = 'mockup_gemini_' . $stamp . '.png';
            }
            $promptName = pathinfo($outputName, PATHINFO_FILENAME) . '.txt';

            $promptPath = $promptsDir . DIRECTORY_SEPARATOR . $promptName;
            $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;

            file_put_contents($promptPath, $submittedPrompt);
            file_put_contents($outputPath, $imageData);

            if (StorageService::isGcsActive()) {
                StorageService::uploadFile('results/' . $outputName, $outputPath);
                StorageService::uploadFile('mockup-prompts/' . $promptName, $promptPath);
            }

            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Mockup Gemini generado en resolucion nativa en {$elapsed}s. Archivo: {$outputName}", 'gemini');
        } catch (Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Error generando mockup Gemini despues de {$elapsed}s. Error: " . $e->getMessage(), 'error');
            throw $e;
        }

        return [
            'file' => $outputName,
            'path' => $resultsDir . DIRECTORY_SEPARATOR . $outputName,
            'prompt_file' => $promptName,
            'mock' => false,
            'gemini_mockup' => true,
            'message' => 'Mockup generated from the root image and the selected context.',
        ];
    }

    private function finalPrompt(string $contextId, string $contextPrompt, array $metadata = []): string
    {
        if (!empty($metadata['slot_full_prompt_mode'])) {
            return $contextPrompt;
        }
        if (!empty($metadata['skip_world_visual_enhancer'])) {
            return (string)($metadata['prompt_passthrough_mode'] ?? $contextPrompt);
        }
        if (isset($metadata['prompt_passthrough_mode']) && is_string($metadata['prompt_passthrough_mode'])) {
            return (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId(
                $metadata['prompt_passthrough_mode'],
                $contextId
            );
        }
        if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && defined('MOCKUP_PROMPT_FIRST_NO_MASK_MODE') && MOCKUP_PROMPT_FIRST_NO_MASK_MODE) {
            $contextPrompt .= "\n\nARTWORK PRESERVATION DIRECTIVES:\n"
                . "- The provided artwork image is the authoritative visual reference for the artwork. Recreate the same artwork faithfully inside the mockup scene. Preserve its composition, colors, marks, texture, proportions and visual identity. Do not repaint, redesign, simplify, crop, mirror, recolor or reinterpret the artwork. The artwork may only undergo natural geometric perspective caused by the requested camera view.";
        }

        return (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId($contextPrompt, $contextId);
    }

    private function cameraSlotId(string $prompt, array $metadata): string
    {
        $combination = $metadata['mockup_combination'] ?? [];
        if (is_array($combination)) {
            $slotId = trim((string)($combination['selected_camera_slot_id'] ?? ''));
            if ($slotId !== '') {
                return $slotId;
            }
        }

        $seoParams = $metadata['seo_params'] ?? [];
        if (is_array($seoParams)) {
            $slotId = trim((string)($seoParams['cameraAngle'] ?? ''));
            if ($slotId !== '') {
                return $slotId;
            }
        }

        if (preg_match('/Camera Slot ID:\s*([a-z0-9_\\-]+)/i', $prompt, $matches)) {
            return strtolower(trim((string)$matches[1]));
        }

        return '';
    }

    private function usesInpaintingCamera(string $cameraSlotId): bool
    {
        if ($cameraSlotId === 'camara_15_contrapicado_inpainting') {
            return true;
        }
        $slot = $this->cameraSlotConfig($cameraSlotId);
        return ($slot['generation_strategy'] ?? '') === 'inpainting_precomposition';
    }

    private function usesGraphicPerspectiveSlot(string $cameraSlotId): bool
    {
        $slot = $this->cameraSlotConfig($cameraSlotId);
        return trim((string)($slot['graphic_perspective_plate_path'] ?? '')) !== '';
    }

    private function graphicPerspectivePlatePath(string $cameraSlotId): string
    {
        $slot = $this->cameraSlotConfig($cameraSlotId);
        $path = trim((string)($slot['graphic_perspective_plate_path'] ?? ''));
        if ($path === '') {
            return '';
        }
        if (is_file($path)) {
            return $path;
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function graphicPerspectiveImageModel(string $cameraSlotId): string
    {
        $slot = $this->cameraSlotConfig($cameraSlotId);
        $model = trim((string)($slot['force_image_model'] ?? ''));
        return $model !== '' ? $model : 'gemini-3.1-flash-image';
    }

    private function graphicPerspectiveMode(string $cameraSlotId): string
    {
        $slot = $this->cameraSlotConfig($cameraSlotId);
        $mode = strtolower(trim((string)($slot['graphic_perspective_mode'] ?? 'precomposition')));
        return $mode !== '' ? $mode : 'precomposition';
    }

    /**
     * @return array<string,mixed>
     */
    private function cameraSlotConfig(string $cameraSlotId): array
    {
        $cameraSlotId = trim($cameraSlotId);
        if ($cameraSlotId === '') {
            return [];
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'mockup_camera_slots.php';
        $config = is_file($path) ? require $path : [];
        $slot = $config['slots'][$cameraSlotId] ?? [];
        if (!is_array($slot) || $slot === []) {
            foreach (($config['slots'] ?? []) as $id => $candidate) {
                if (strtolower((string)$id) === strtolower($cameraSlotId) && is_array($candidate)) {
                    return $candidate;
                }
            }
        }
        return is_array($slot) ? $slot : [];
    }
}
