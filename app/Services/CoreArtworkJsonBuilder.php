<?php
declare(strict_types=1);

class CoreArtworkJsonBuilder
{
    /**
     * Builds and saves the CORE JSON 1.0 for the specified artwork.
     *
     * @param int $artworkId The ID of the artwork.
     * @return array The generated CORE JSON as an array.
     */
    public function buildForArtwork(int $artworkId): array
    {
        Logger::log("CORE_JSON_BUILD_START for artwork_id: {$artworkId}", 'info');

        try {
            $db = Database::connection();

            // 1. Fetch artwork details from the database
            $stmt = $db->prepare("SELECT * FROM artworks WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $artworkId]);
            $artwork = $stmt->fetch();

            if (!$artwork) {
                throw new RuntimeException("Artwork with ID {$artworkId} not found in database.");
            }

            $jobId = $artwork['job_id'] ?? null;

            // 2. Fetch analysis JSON from database or disk
            $analysisJson = null;
            $sourceAnalysisFile = null;

            // Try from DB first
            $stmtAnalysis = $db->prepare("SELECT * FROM artwork_analysis WHERE artwork_id = :id ORDER BY created_at DESC LIMIT 1");
            $stmtAnalysis->execute(['id' => $artworkId]);
            $analysisRow = $stmtAnalysis->fetch();

            if ($analysisRow && !empty($analysisRow['analysis_json'])) {
                $analysisJson = (string)$analysisRow['analysis_json'];
                $sourceAnalysisFile = "DB: artwork_analysis (id={$analysisRow['id']})";
            }

            // Fallback/Override from disk if physical selected root file exists
            if (!empty($artwork['root_file'])) {
                $filenameWithoutExt = pathinfo($artwork['root_file'], PATHINFO_FILENAME);
                $diskPath = ANALYSIS_DIR . DIRECTORY_SEPARATOR . $filenameWithoutExt . '.analysis.json';
                if (is_file($diskPath)) {
                    $diskContent = file_get_contents($diskPath);
                    if ($diskContent !== false && trim($diskContent) !== '') {
                        $analysisJson = $diskContent;
                        $sourceAnalysisFile = $filenameWithoutExt . '.analysis.json';
                    }
                }
            }

            $rawAnalysis = [];
            $sourceHash = null;
            if ($analysisJson !== null) {
                $sourceHash = md5($analysisJson);
                $decoded = json_decode($analysisJson, true);
                if (is_array($decoded)) {
                    $rawAnalysis = $decoded;
                }
            }

            // Log analysis origin
            if ($sourceAnalysisFile !== null) {
                if (str_starts_with($sourceAnalysisFile, 'DB:')) {
                    Logger::log("CORE_JSON_SOURCE_ANALYSIS_DB_FALLBACK for artwork_id: {$artworkId}", 'info');
                } else {
                    Logger::log("CORE_JSON_SOURCE_ANALYSIS_FOUND for artwork_id: {$artworkId} in {$sourceAnalysisFile}", 'info');
                }
            }

            // 3. Dimensions normalization and aspect ratio calculation
            $width = $artwork['width'] !== null ? (float)str_replace(',', '.', (string)$artwork['width']) : null;
            $height = $artwork['height'] !== null ? (float)str_replace(',', '.', (string)$artwork['height']) : null;
            $depth = $artwork['depth'] !== null ? (float)str_replace(',', '.', (string)$artwork['depth']) : null;

            $orientation = null;
            $aspectRatio = null;

            if ($width !== null && $height !== null && $width > 0 && $height > 0) {
                $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');
                $aspectRatio = round($width / $height, 4);
            } else {
                // Fallback to analysis JSON properties
                $rawOrient = $this->resolveFallback($rawAnalysis, ['orientation', 'artwork_analysis.format_and_scale.orientation', 'artwork_profile.image.orientation']);
                $orientation = $this->normalizeOrientation($rawOrient !== null ? (string)$rawOrient : null);
                
                $rawAspect = $this->resolveFallback($rawAnalysis, ['aspect_ratio', 'artwork_analysis.format_and_scale.aspect_ratio', 'artwork_profile.image.aspect_ratio']);
                $aspectRatio = $rawAspect !== null ? (float)$rawAspect : null;
            }

            // 4. Resolve candidate views by scanning status.json and/or scanning results folder
            $threeQuarterLeftFile = null;
            $frontalFile = null;
            $threeQuarterRightFile = null;

            // Step 4.1: Read from status.json candidates array
            if ($jobId !== null) {
                $statusFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . 'status.json';
                if (is_file($statusFile)) {
                    $statusData = json_decode(file_get_contents($statusFile), true);
                    if (is_array($statusData) && isset($statusData['candidates']) && is_array($statusData['candidates'])) {
                        foreach ($statusData['candidates'] as $candidate) {
                            $candBase = basename((string)$candidate);
                            $filenameNoExt = pathinfo($candBase, PATHINFO_FILENAME);
                            if (preg_match('/_v1\b/i', $filenameNoExt)) {
                                $frontalFile = $candBase;
                            } elseif (preg_match('/_v2\b/i', $filenameNoExt)) {
                                $threeQuarterLeftFile = $candBase;
                            } elseif (preg_match('/_v3\b/i', $filenameNoExt)) {
                                $threeQuarterRightFile = $candBase;
                            }
                        }
                    }
                }
            }

            // Step 4.2: Scan results folder directly if missing
            if ($jobId !== null && (!$frontalFile || !$threeQuarterLeftFile || !$threeQuarterRightFile)) {
                $resultsDir = RESULTS_DIR;
                if (is_dir($resultsDir)) {
                    $files = glob($resultsDir . DIRECTORY_SEPARATOR . '*_' . $jobId . '_v*.*');
                    if (is_array($files)) {
                        foreach ($files as $file) {
                            $candBase = basename($file);
                            $filenameNoExt = pathinfo($candBase, PATHINFO_FILENAME);
                            if (str_ends_with($filenameNoExt, '_v1')) {
                                $frontalFile = $candBase;
                            } elseif (str_ends_with($filenameNoExt, '_v2')) {
                                $threeQuarterLeftFile = $candBase;
                            } elseif (str_ends_with($filenameNoExt, '_v3')) {
                                $threeQuarterRightFile = $candBase;
                            }
                        }
                    }
                }
            }

            // 5. Selected view matching
            $selectedRootFile = $artwork['root_file'] !== null ? basename((string)$artwork['root_file']) : null;
            $selectedView = null;
            if ($selectedRootFile !== null && $selectedRootFile !== '') {
                if ($selectedRootFile === $frontalFile) {
                    $selectedView = 'frontal';
                } elseif ($selectedRootFile === $threeQuarterLeftFile) {
                    $selectedView = 'three_quarter_left';
                } elseif ($selectedRootFile === $threeQuarterRightFile) {
                    $selectedView = 'three_quarter_right';
                }
            }

            // 6. Visual Analysis formatting with complete fallbacks
            $rawVisLang = $this->resolveFallback($rawAnalysis, [
                'visual_language',
                'artwork_analysis.visual_language',
                'visual_analysis.visual_language',
                'formal_analysis.visual_language',
                'style_analysis.visual_language',
                'composition_analysis.visual_language',
                'descriptive_analysis.visual_language',
                'artwork_profile.style_tags',
                'artwork_profile.style_interpretation.dominant_language'
            ]);
            $visualLanguage = is_array($rawVisLang) ? implode(', ', $this->cleanArray($rawVisLang)) : $this->cleanString((string)$rawVisLang);

            $rawComp = $this->resolveFallback($rawAnalysis, [
                'composition',
                'artwork_analysis.composition',
                'visual_analysis.composition',
                'formal_analysis.composition',
                'composition_analysis',
                'composition_analysis.description',
                'composition_type',
                'artwork_analysis.composition_type',
                'artwork_profile.structure_tags'
            ]);
            $composition = is_array($rawComp) ? implode(', ', $this->cleanArray($rawComp)) : $this->cleanString((string)$rawComp);

            $dominantColors = $this->normalizeToArray($this->resolveFallback($rawAnalysis, [
                'dominant_colors',
                'artwork_analysis.dominant_colors',
                'visual_analysis.dominant_colors',
                'color_palette.dominant_colors',
                'palette.dominant',
                'colors.dominant',
                'chromatic_analysis.dominant_colors',
                'artwork_profile.palette',
                'artwork_profile.palette_family'
            ]));

            $secondaryColors = $this->normalizeToArray($this->resolveFallback($rawAnalysis, [
                'secondary_colors',
                'artwork_analysis.secondary_colors',
                'visual_analysis.secondary_colors',
                'color_palette.secondary_colors',
                'palette.secondary',
                'colors.secondary',
                'chromatic_analysis.secondary_colors'
            ]));

            $rawMat = $this->resolveFallback($rawAnalysis, [
                'materials_or_surface',
                'artwork_analysis.materials_or_surface',
                'surface',
                'artwork_analysis.surface',
                'surface_quality',
                'visual_analysis.materials_or_surface',
                'material_analysis.surface',
                'technique.surface',
                'materials',
                'artwork_analysis.materials',
                'artwork_profile.materiality_strategy',
                'artwork_profile.structure_tags'
            ]);
            $materialsOrSurface = is_array($rawMat) ? implode(', ', $this->cleanArray($rawMat)) : $this->cleanString((string)$rawMat);

            $rawTextur = $this->resolveFallback($rawAnalysis, [
                'texture',
                'artwork_analysis.texture',
                'visual_analysis.texture',
                'surface_texture',
                'material_analysis.texture',
                'artwork_profile.texture_visibility',
                'artwork_profile.materiality_strategy.show'
            ]);
            $texture = is_array($rawTextur) ? implode(', ', $this->cleanArray($rawTextur)) : $this->cleanString((string)$rawTextur);

            $rawDepth = $this->resolveFallback($rawAnalysis, [
                'spatial_depth',
                'artwork_analysis.spatial_depth',
                'depth',
                'artwork_analysis.depth',
                'visual_analysis.spatial_depth',
                'composition.spatial_depth',
                'spatial_analysis.depth',
                'spatial_presence',
                'artwork_analysis.spatial_presence'
            ]);
            $spatialDepth = is_array($rawDepth) ? implode(', ', $this->cleanArray($rawDepth)) : $this->cleanString((string)$rawDepth);

            $rawLight = $this->resolveFallback($rawAnalysis, [
                'light_behavior',
                'artwork_analysis.light_behavior',
                'lighting',
                'artwork_analysis.lighting',
                'visual_analysis.light_behavior',
                'chromatic_analysis.light_behavior',
                'color_temperature',
                'artwork_analysis.color_temperature',
                'artwork_profile.luminosity',
                'artwork_profile.emotional_palette.temperature'
            ]);
            $lightBehavior = is_array($rawLight) ? implode(', ', $this->cleanArray($rawLight)) : $this->cleanString((string)$rawLight);

            $rawGesture = $this->resolveFallback($rawAnalysis, [
                'gesture_or_mark_making',
                'artwork_analysis.gesture_or_mark_making',
                'mark_making',
                'marks',
                'visual_analysis.gesture_or_mark_making',
                'technique.mark_making',
                'material_analysis.mark_making',
                'gesture',
                'artwork_analysis.gesture',
                'brushstroke',
                'artwork_analysis.brushstroke',
                'artwork_profile.materiality_strategy.show'
            ]);
            $gestureOrMarkMaking = is_array($rawGesture) ? implode(', ', $this->cleanArray($rawGesture)) : $this->cleanString((string)$rawGesture);

            $rawEnergy = $this->resolveFallback($rawAnalysis, [
                'emotional_energy',
                'artwork_analysis.emotional_energy',
                'mood',
                'artwork_analysis.mood',
                'atmosphere',
                'artwork_analysis.atmosphere',
                'affective_quality',
                'visual_analysis.emotional_energy',
                'emotional_analysis.energy',
                'artwork_profile.mood_tags',
                'artwork_profile.emotional_palette.psychological_associations'
            ]);
            $emotionalEnergy = is_array($rawEnergy) ? implode(', ', $this->cleanArray($rawEnergy)) : $this->cleanString((string)$rawEnergy);

            $symbolicElements = $this->normalizeToArray($this->resolveFallback($rawAnalysis, [
                'symbolic_elements',
                'artwork_analysis.symbolic_elements',
                'symbols',
                'artwork_analysis.symbols',
                'motifs',
                'recurring_elements',
                'visual_analysis.symbolic_elements',
                'iconography.symbolic_elements',
                'visible_symbols',
                'artwork_analysis.visible_symbols',
                'visible_elements',
                'artwork_analysis.visible_elements'
            ]));

            $rawStyle = $this->resolveFallback($rawAnalysis, [
                'style_family',
                'artwork_analysis.style_family',
                'style',
                'visual_style',
                'artistic_style',
                'visual_analysis.style_family',
                'artwork_profile.style_summary',
                'style_summary',
                'artwork_analysis.style_summary'
            ]);
            $styleFamily = is_array($rawStyle) ? implode(', ', $this->cleanArray($rawStyle)) : $this->cleanString((string)$rawStyle);

            // 7. Identity details
            $rawShortId = $this->resolveFallback($rawAnalysis, [
                'artwork_identity.short_identity',
                'short_identity',
                'artwork_analysis.one_line_curatorial_read',
                'artwork_profile.one_line_curatorial_read',
                'one_line_curatorial_read'
            ]);
            // Fallback chain for short_identity
            if ($rawShortId === null || trim((string)$rawShortId) === '') {
                $rawShortId = $visualLanguage ?? $styleFamily ?? $composition ?? null;
            }
            $shortIdentity = $this->cleanString((string)$rawShortId);

            $rawExpId = $this->resolveFallback($rawAnalysis, [
                'artwork_identity.expanded_identity',
                'expanded_identity',
                'visual_analysis.summary',
                'analysis_summary',
                'artwork_profile.style_summary',
                'style_summary',
                'artwork_analysis.style_summary'
            ]);
            $expandedIdentity = $this->cleanString((string)$rawExpId);

            $keywords = $this->normalizeToArray($this->resolveFallback($rawAnalysis, [
                'keywords',
                'tags',
                'artwork_keywords',
                'visual_keywords',
                'publishing_metadata.keywords',
                'artwork_profile.style_tags'
            ]));

            // 8. Publishing titles formatting (exactly 3 objects with description fallbacks)
            $titlesSource = $this->resolveFallback($rawAnalysis, [
                'suggested_titles',
                'publishing_metadata.suggested_titles',
                'artwork_analysis.publishing_metadata.suggested_titles'
            ]) ?? [];

            if (!is_array($titlesSource)) {
                $titlesSource = [];
            }
            $suggestedTitles = [];
            for ($i = 0; $i < 3; $i++) {
                if (isset($titlesSource[$i]) && is_array($titlesSource[$i])) {
                    $item = $titlesSource[$i];
                    $titleVal = $this->resolveFallback($item, ['title', 'name']);
                    $subVal = $this->resolveFallback($item, ['subtitle', 'short_subtitle', 'tagline', 'short_description']);
                    $descVal = $this->resolveFallback($item, ['description', 'curatorial_description', 'expanded_description', 'long_description', 'short_description', 'commercial_description']);
                    
                    $suggestedTitles[] = [
                        'title' => $this->cleanString((string)($titleVal ?? '')),
                        'subtitle' => $this->cleanString((string)($subVal ?? '')),
                        'description' => $this->cleanString((string)($descVal ?? '')),
                    ];
                } else {
                    $suggestedTitles[] = [
                        'title' => null,
                        'subtitle' => null,
                        'description' => null,
                    ];
                }
            }

            // Fallback for short_identity and expanded_identity if still null
            if ($shortIdentity === null && isset($suggestedTitles[0]['subtitle'])) {
                $shortIdentity = $suggestedTitles[0]['subtitle'];
            }
            if ($expandedIdentity === null && isset($suggestedTitles[0]['description'])) {
                $expandedIdentity = $suggestedTitles[0]['description'];
            }

            // 8.5 Resolve physical artwork reference (Nivel 1 Observables)
            $rawPaintContinuity = $this->resolveFallback($rawAnalysis, [
                'paint_continues_on_edges',
                'artwork_analysis.paint_continues_on_edges',
                'painted_edges',
                'artwork_analysis.painted_edges',
                'edge_continuity',
                'artwork_analysis.edge_continuity',
                'canvas_edge_continuity',
                'artwork_analysis.canvas_edge_continuity',
                'painted_side_edges',
                'artwork_analysis.painted_side_edges',
                'side_painting',
                'artwork_analysis.side_painting',
                'edge_finish',
                'artwork_analysis.edge_finish'
            ]);
            $paintContinuesOnEdges = $this->normalizePaintContinuesOnEdges($rawPaintContinuity);
            $edgeFinish = $this->normalizeEdgeFinish($rawPaintContinuity);

            $objectType = null;
            if (($depth !== null && $depth > 0) || ($width !== null && $width > 0 && $height !== null && $height > 0)) {
                $objectType = 'stretched_canvas';
            }

            $isPhysicalObject = null;
            if (($width !== null && $width > 0 && $height !== null && $height > 0) || $frontalFile || $threeQuarterLeftFile || $threeQuarterRightFile) {
                $isPhysicalObject = true;
            }

            $hasVisibleEdges = ($threeQuarterLeftFile !== null || $threeQuarterRightFile !== null) ? true : null;

            $physicalArtworkReference = [
                'object_type' => $objectType,
                'is_physical_object' => $isPhysicalObject,
                'depth_cm' => $depth !== null ? (float)$depth : null,
                'has_visible_edges' => $hasVisibleEdges,
                'paint_continues_on_edges' => $paintContinuesOnEdges,
                'edge_finish' => $edgeFinish,
                'view_observations' => [
                    'frontal' => [
                        'file' => $frontalFile,
                        'visible_edges' => [],
                        'canvas_depth_visible' => false,
                        'paint_continuity_visible' => $paintContinuesOnEdges,
                        'best_for' => [
                            'primary_composition_reference',
                            'frontal_mockup_reference'
                        ]
                    ],
                    'three_quarter_left' => [
                        'file' => $threeQuarterLeftFile,
                        'visible_edges' => $threeQuarterLeftFile !== null ? ['left_edge'] : [],
                        'canvas_depth_visible' => $threeQuarterLeftFile !== null ? true : null,
                        'paint_continuity_visible' => ($paintContinuesOnEdges !== null && $threeQuarterLeftFile !== null) ? $paintContinuesOnEdges : null,
                        'best_for' => [
                            'left_oblique_reference',
                            'canvas_depth_reference'
                        ]
                    ],
                    'three_quarter_right' => [
                        'file' => $threeQuarterRightFile,
                        'visible_edges' => $threeQuarterRightFile !== null ? ['right_edge'] : [],
                        'canvas_depth_visible' => $threeQuarterRightFile !== null ? true : null,
                        'paint_continuity_visible' => ($paintContinuesOnEdges !== null && $threeQuarterRightFile !== null) ? $paintContinuesOnEdges : null,
                        'best_for' => [
                            'right_oblique_reference',
                            'canvas_depth_reference'
                        ]
                    ]
                ]
            ];

            // 9. Build final structured CORE JSON
            $coreJson = [
                'core_schema_version' => '1.1',
                'artwork' => [
                    'artwork_id' => (int)$artworkId,
                    'job_id' => $jobId,
                    'source_image' => $artwork['main_file'] !== null ? basename((string)$artwork['main_file']) : null,
                    'selected_root_file' => $selectedRootFile,
                    'dimensions' => [
                        'width_cm' => $width,
                        'height_cm' => $height,
                        'depth_cm' => $depth,
                        'orientation' => $orientation,
                        'aspect_ratio' => $aspectRatio,
                    ]
                ],
                'root_artwork_views' => [
                    'three_quarter_left' => [
                        'file' => $threeQuarterLeftFile,
                        'role' => 'three-quarter left root view',
                    ],
                    'frontal' => [
                        'file' => $frontalFile,
                        'role' => 'frontal root view',
                    ],
                    'three_quarter_right' => [
                        'file' => $threeQuarterRightFile,
                        'role' => 'three-quarter right root view',
                    ],
                    'selected_view' => $selectedView,
                ],
                'physical_artwork_reference' => $physicalArtworkReference,
                'visual_analysis' => [
                    'visual_language' => $visualLanguage,
                    'composition' => $composition,
                    'dominant_colors' => $dominantColors,
                    'secondary_colors' => $secondaryColors,
                    'materials_or_surface' => $materialsOrSurface,
                    'texture' => $texture,
                    'spatial_depth' => $spatialDepth,
                    'light_behavior' => $lightBehavior,
                    'gesture_or_mark_making' => $gestureOrMarkMaking,
                    'emotional_energy' => $emotionalEnergy,
                    'symbolic_elements' => $symbolicElements,
                    'style_family' => $styleFamily,
                ],
                'artwork_identity' => [
                    'short_identity' => $shortIdentity,
                    'expanded_identity' => $expandedIdentity,
                    'keywords' => $keywords,
                    'do_not_alter_rules' => [
                        'Do not repaint the artwork.',
                        'Do not redesign the artwork.',
                        'Do not crop the artwork.',
                        'Do not mirror the artwork.',
                        'Do not recolor the artwork.',
                        'Do not reinterpret the artwork.',
                        'Preserve composition, proportions, colors, marks, texture and visual identity.',
                    ]
                ],
                'publishing_texts' => [
                    'suggested_titles' => $suggestedTitles,
                ],
                'technical_metadata' => [
                    'created_at' => date('c'),
                    'source_analysis_file' => $sourceAnalysisFile,
                    'source_analysis_hash' => $sourceHash,
                ]
            ];

            // 10. Check semantic warnings
            $warnings = [];
            if ($coreJson['visual_analysis']['visual_language'] === null) {
                $warnings[] = 'visual_analysis.visual_language is null';
            }
            if ($coreJson['visual_analysis']['composition'] === null) {
                $warnings[] = 'visual_analysis.composition is null';
            }
            if (empty($coreJson['visual_analysis']['dominant_colors'])) {
                $warnings[] = 'visual_analysis.dominant_colors is empty';
            }
            if ($coreJson['visual_analysis']['emotional_energy'] === null) {
                $warnings[] = 'visual_analysis.emotional_energy is null';
            }
            if ($coreJson['artwork_identity']['short_identity'] === null) {
                $warnings[] = 'artwork_identity.short_identity is null';
            }
            $firstTitle = $coreJson['publishing_texts']['suggested_titles'][0]['title'] ?? null;
            if ($firstTitle === null || $firstTitle === '') {
                $warnings[] = 'publishing_texts.suggested_titles[0].title is empty';
            }

            if (!empty($warnings)) {
                Logger::log("CORE_JSON_SEMANTIC_WARNINGS for artwork_id: {$artworkId}: " . implode(' | ', $warnings), 'warning');
            }

            // 11. Write file to analysis/core/{artwork_id}.core.json
            $coreDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'analysis' . DIRECTORY_SEPARATOR . 'core';
            if (!is_dir($coreDir)) {
                if (!mkdir($coreDir, 0775, true) && !is_dir($coreDir)) {
                    throw new RuntimeException("Failed to create folder analysis/core.");
                }
            }

            $outputPath = $coreDir . DIRECTORY_SEPARATOR . $artworkId . '.core.json';
            $jsonString = json_encode($coreJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonString === false) {
                throw new RuntimeException("Failed to encode core JSON array.");
            }

            if (file_put_contents($outputPath, $jsonString) === false) {
                throw new RuntimeException("Failed to write core JSON to disk at {$outputPath}.");
            }

            Logger::log("CORE_JSON_BUILD_SUCCESS for artwork_id: {$artworkId}. Written to {$outputPath}", 'info');
            return $coreJson;

        } catch (Throwable $e) {
            Logger::log("CORE_JSON_BUILD_ERROR for artwork_id: {$artworkId}. Error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Resolves equivalent keys in raw analysis array using nested path strings (e.g. 'artwork_analysis.visual_language').
     */
    private function resolveFallback(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $val = $this->getValueByPath($data, $path);
            if ($val !== null && $val !== '' && (!is_array($val) || !empty($val))) {
                return $val;
            }
        }
        return null;
    }

    /**
     * Resolves dot notation path traversal for associative arrays.
     */
    private function getValueByPath(array $data, string $path)
    {
        $parts = explode('.', $path);
        $current = $data;
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        return $current;
    }

    /**
     * Normalizes inputs of string list (comma/newline separated), flat array, or array of objects.
     */
    private function normalizeToArray($input): array
    {
        if ($input === null) {
            return [];
        }

        if (is_string($input)) {
            if (str_contains($input, "\n") || str_contains($input, ',')) {
                $parts = preg_split('/[\n,\r]+/', $input);
                $arr = [];
                foreach ($parts as $part) {
                    $trimmed = trim($part);
                    if ($trimmed !== '') {
                        $arr[] = $trimmed;
                    }
                }
                return $arr;
            } else {
                $trimmed = trim($input);
                return $trimmed !== '' ? [$trimmed] : [];
            }
        }

        if (is_array($input)) {
            $arr = [];
            foreach ($input as $item) {
                if (is_array($item)) {
                    foreach (['name', 'value', 'label', 'color', 'description'] as $k) {
                        if (isset($item[$k]) && is_string($item[$k])) {
                            $trimmed = trim($item[$k]);
                            if ($trimmed !== '') {
                                $arr[] = $trimmed;
                                break;
                            }
                        }
                    }
                } elseif (is_string($item) || is_numeric($item)) {
                    $trimmed = trim((string)$item);
                    if ($trimmed !== '') {
                        $arr[] = $trimmed;
                    }
                }
            }
            return $arr;
        }

        return [];
    }

    /**
     * String normalization helper.
     */
    private function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Remove duplicate line breaks
        $value = (string)preg_replace('/[\r\n]+/', "\n", $value);
        return $value;
    }

    /**
     * Array filter and normalization helper.
     */
    private function cleanArray($arr): array
    {
        if (!is_array($arr)) {
            return [];
        }
        $cleaned = [];
        foreach ($arr as $item) {
            $val = $this->cleanString((string)$item);
            if ($val !== null) {
                $cleaned[] = $val;
            }
        }
        return $cleaned;
    }

    /**
     * Normalize orientation to square, vertical, or horizontal.
     */
    private function normalizeOrientation(?string $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $val = strtolower(trim($val));
        if (str_contains($val, 'horizontal') || str_contains($val, 'landscape')) {
            return 'horizontal';
        }
        if (str_contains($val, 'vertical') || str_contains($val, 'portrait')) {
            return 'vertical';
        }
        if (str_contains($val, 'square')) {
            return 'square';
        }
        return null;
     }

    /**
     * Normalize edge paint continuity based on legacy indicators.
     */
    private function normalizePaintContinuesOnEdges($val): ?bool
    {
        if ($val === null) {
            return null;
        }
        if (is_bool($val)) {
            return $val;
        }
        $str = strtolower(trim((string)$val));
        if (in_array($str, ['true', 'yes', 'si', '1', 'continues', 'painted', 'painted_continuation', 'painted continuation'])) {
            return true;
        }
        if (in_array($str, ['false', 'no', '0', 'white', 'raw', 'white_canvas_edge', 'raw_canvas_edge', 'white canvas edge', 'raw canvas edge'])) {
            return false;
        }
        return null;
    }

    /**
     * Normalize edge finish label.
     */
    private function normalizeEdgeFinish($val): ?string
    {
        if ($val === null) {
            return null;
        }
        if (is_bool($val)) {
            return $val ? 'painted_continuation' : null;
        }
        $str = strtolower(trim((string)$val));
        if (in_array($str, ['painted_continuation', 'painted continuation', 'continues', 'painted', 'yes', 'true', 'si'])) {
            return 'painted_continuation';
        }
        if (in_array($str, ['white_canvas_edge', 'white canvas edge', 'white'])) {
            return 'white_canvas_edge';
        }
        if (in_array($str, ['raw_canvas_edge', 'raw canvas edge', 'raw'])) {
            return 'raw_canvas_edge';
        }
        if (in_array($str, ['framed_edge', 'framed'])) {
            return 'framed_edge';
        }
        return null;
    }
}
