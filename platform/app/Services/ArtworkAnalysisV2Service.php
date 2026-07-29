<?php
declare(strict_types=1);

/**
 * No es `final` a proposito: el worker editorial la recibe inyectada y las
 * pruebas sustituyen `generateDraft` para cubrir la generacion de obra sin
 * gastar una llamada real con imagen.
 */
class ArtworkAnalysisV2Service
{
    public function __construct(
        private GeminiImageClient $client,
        private readonly ?PDO $pdo = null
    ) {}

    public function generateDraft(array $artwork, array $artistProfile, string $imagePath, string $notes = '', string $analysisLocale = 'es', array $currentReading = [], string $privateMemo = ''): array
    {
        if (!is_file($imagePath)) throw new RuntimeException('Root artwork image not found for v2 analysis.');
        $artworkId = (int)($artwork['id'] ?? 0);
        if ($artworkId <= 0) throw new RuntimeException('Invalid artwork id for v2 analysis.');
        $this->assertIntegralContext($artwork);
        $excludeBase = pathinfo($imagePath, PATHINFO_FILENAME);
        $draftDir = __DIR__ . '/../../storage/artwork_analysis_v2_drafts';
        $strategy = DescriptionDiversityEngine::select(
            array_merge($artwork, ['artist_profile'=>$artistProfile]),
            [__DIR__ . '/../../analysis', $draftDir, __DIR__ . '/../../tmp/drafts'],
            basename($imagePath)
        );
        // El analisis se piensa y redacta en el idioma de trabajo del artista.
        $analysisLocale = $analysisLocale === 'en' ? 'en' : 'es';
        $analysisLanguageName = $analysisLocale === 'en' ? 'English' : 'Spanish';
        $analysisStyle = $analysisLocale === 'en' ? 'natural international English' : 'natural Spanish';
        $analysisCounterpart = $analysisLocale === 'en' ? 'Spanish' : 'English';
        $analysisLanguageInstruction = "Think, analyze and formulate the editorial reading directly in {$analysisStyle}. Do not draft in {$analysisCounterpart} and translate afterward.";
        $prompt = strtr(ArtworkAnalysisV2::prompt(), [
            '{analysis_language_instruction}'=>$analysisLanguageInstruction,
            '{analysis_language_name}'=>$analysisLanguageName,
            '{analysis_language}'=>$analysisLocale,
            '{artist_profile_prompt}'=>ArtistProfile::hasContent($artistProfile) ? ArtistProfile::forPrompt($artistProfile) : '',
            '{series_context}'=>$this->seriesContext($artwork),
            '{current_reading_block}'=>$this->currentReadingBlock($currentReading, $privateMemo),
            '{keyword_research_block}'=>$this->keywordResearchBlock($artwork),
            '{description_opening_type}'=>(string)$strategy['description_opening_type'],
            '{description_opening_rhythm}'=>(string)$strategy['description_opening_rhythm'],
            '{description_structure_type}'=>(string)$strategy['description_structure_type'],
            '{recent_opening_types_to_avoid}'=>implode(', ', (array)$strategy['recent_opening_types_to_avoid']) ?: 'none recorded',
            '{editorial_integrity_rules}'=>EditorialIntegrityPolicy::promptRules('artwork'),
            '{search_intent_rules}'=>SearchIntentPrompt::forEntity('artwork'),
            '{artwork_id}'=>(string)$artworkId,
            '{title}'=>(string)($artwork['final_title']??''),
            '{artist}'=>(string)($artistProfile['artist_name']??''),
            '{year}'=>(string)($artwork['artwork_year']??''),
            '{series}'=>(string)($artwork['series']??''),
            '{medium}'=>(string)($artwork['medium']??''),
            '{materials}'=>(string)($artwork['medium']??''),
            '{width_cm}'=>(string)($artwork['width']??''),
            '{height_cm}'=>(string)($artwork['height']??''),
            '{depth_cm}'=>(string)($artwork['depth']??''),
            '{orientation}'=>$this->orientation($artwork),
            '{notes}'=>$notes,
        ]);

        $attempts = 0;
        $draft = [];
        $errors = [];
        while ($attempts < 3) {
            $attempts++;
            $draft = $this->request($prompt, $imagePath);
            $this->finalize($draft, $artwork, $imagePath, $strategy, $excludeBase, $attempts, $analysisLocale);
            $errors = ArtworkAnalysisV2::validate($draft, false);
            if (!$errors) break;

            $reason = implode('; ', $errors);
            $specificCorrection = str_contains($reason, 'Generic AI opening')
                ? "\nBoth canonical_editorial.short_description and canonical_editorial.master_description must begin with concrete visible evidence. Their first words must not be This, In this, The, A, or An. Begin directly with a specific color relationship, form, edge, interval, surface, direction, or spatial tension visible in this image."
                : '';
            $prompt .= "\n\nREVISION REQUIRED — ATTEMPT {$attempts}\n{$reason}.{$specificCorrection}\nReturn the complete JSON again. Correct every listed issue without weakening the artwork-specific analysis or merely substituting synonyms.";
        }
        if ($errors) {
            throw new RuntimeException('V2 analysis failed validation after three attempts: ' . implode(' ', array_unique($errors)));
        }

        if (!is_dir($draftDir) && !mkdir($draftDir, 0775, true) && !is_dir($draftDir)) throw new RuntimeException('Could not create the v2 draft directory.');
        $output = $draftDir . DIRECTORY_SEPARATOR . 'artwork-' . $artworkId . '.json';
        file_put_contents($output, json_encode($draft, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL);
        file_put_contents($output . '.prompt.txt', $prompt);
        return ['draft'=>$draft, 'file'=>$output];
    }

    private function seriesContext(array $artwork): string
    {
        $fallbackTitle = trim((string)($artwork['series'] ?? ''));
        $seriesId = (int)($artwork['series_id'] ?? 0);
        $userId = (int)($artwork['user_id'] ?? 0);
        if (!$this->pdo || $seriesId <= 0 || $userId <= 0) {
            return $fallbackTitle !== ''
                ? "Series title: {$fallbackTitle}\nNo additional artist-authored series context was supplied."
                : 'This artwork has no supplied series context.';
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT title,subtitle,description,long_description,conceptual_core,interpretive_limits
                 FROM artwork_series WHERE id=? AND user_id=? LIMIT 1'
            );
            $statement->execute([$seriesId, $userId]);
            $series = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $series = false;
        }
        if (!is_array($series)) {
            return $fallbackTitle !== ''
                ? "Series title: {$fallbackTitle}\nNo additional artist-authored series context was found."
                : 'This artwork has no supplied series context.';
        }

        $labels = [
            'title' => 'Series title',
            'subtitle' => 'Series subtitle',
            'description' => 'Series presentation',
            'long_description' => 'Series curatorial text',
            'conceptual_core' => 'Artist direction',
            'interpretive_limits' => 'Interpretive limits',
        ];
        $lines = [];
        foreach ($labels as $field => $label) {
            $value = trim((string)($series[$field] ?? ''));
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }
        return $lines !== [] ? implode("\n", $lines) : 'The linked series has no artist-authored context yet.';
    }

    private function request(string $prompt, string $imagePath): array
    {
        $raw = $this->client->generateText([$this->client->textPart($prompt), $this->client->imagePart($imagePath)], 'gemini-2.5-flash');
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw)) ?? trim($raw);
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $start = strpos($clean, '{'); $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end >= $start) $clean = substr($clean, $start, $end-$start+1);
        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) throw new RuntimeException('Gemini did not return valid v2 JSON.');
        return $decoded;
    }

    private function finalize(array &$draft, array $artwork, string $imagePath, array $strategy, string $excludeBase, int $attempts, string $analysisLocale): void
    {
        $draft['schema_version'] = ArtworkAnalysisV2::SCHEMA_VERSION;
        $draft['artwork_id'] = (int)($artwork['id'] ?? 0);
        $draft['analysis_language'] = $analysisLocale;
        $draft['source']['image_file'] = basename($imagePath);
        $draft['source']['analysis_prompt_version'] = 'v2';
        $draft['source']['analyzed_at'] = date(DATE_ATOM);
        $draft['originality_check'] = ArtworkOriginalityChecker::check($draft, __DIR__ . '/../../analysis', $excludeBase);
        // EDITORIAL_CORE.md Libro I Cap. 6: el titulo lo decide el artista.
        // La aplicacion lo escribe en el borrador (el modelo ya no lo produce)
        // y el guardian reescribe cualquier mencion divergente en la prosa.
        // Ningun caller de generateDraft() puede recibir identidad contaminada.
        $draft = EditorialIdentityGuard::forceIdentity(
            $draft,
            trim((string)($artwork['final_title'] ?? '')),
            trim((string)($artwork['subtitle'] ?? ''))
        );
        $draft['originality_check']['title_unique'] = true;
        $draft['originality_check']['closest_title'] = null;
        $draft['originality_check']['title_similarity'] = null;
        $functions = (array)($draft['editorial_strategy']['paragraph_functions']??[]);
        $paragraphs = preg_split('/\R\s*\R/', trim((string)($draft['canonical_editorial']['master_description']??''))) ?: [];
        $draft['editorial_strategy'] = $strategy;
        $draft['editorial_strategy']['paragraph_functions'] = $functions;
        $draft['editorial_strategy']['opening_paragraph'] = trim((string)($paragraphs[0]??''));
        $draft['review'] = ['analysis_status'=>'draft','editorial_status'=>'draft','reviewed_by'=>null,'reviewed_at'=>null,'notes'=>"Generation attempts: {$attempts}."];
    }

    /**
     * EDITORIAL_CORE.md Libro VI Cap. 1: sin titulo del artista, serie
     * asignada y direccion de serie escrita no se genera lectura. El gate
     * vive aqui — el unico punto por el que pasan todos los callers — para
     * que ninguna pantalla o worker pueda olvidarlo.
     */
    private function assertIntegralContext(array $artwork): void
    {
        if (trim((string)($artwork['final_title'] ?? '')) === '') {
            throw new DomainException('Poné título a la obra antes de generar su contenido. Titular es decisión del artista.');
        }
        $seriesId = (int)($artwork['series_id'] ?? 0);
        if ($seriesId <= 0) {
            throw new DomainException('Asigná la obra a una serie antes de generar su contenido.');
        }
        if (!$this->pdo) return;
        $userId = (int)($artwork['user_id'] ?? 0);
        try {
            $stmt = $this->pdo->prepare('SELECT conceptual_core FROM artwork_series WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$seriesId, $userId]);
            $core = trim((string)$stmt->fetchColumn());
        } catch (Throwable) {
            return;
        }
        if ($core === '') {
            throw new DomainException('Escribí la dirección de la serie (núcleo conceptual) antes de generar contenido de sus obras.');
        }
    }

    /**
     * Libro VI Cap. 2: regenerar = refinar sobre la lectura vigente, nunca
     * empezar de cero. Cuando existe, entra al prompt como base a construir.
     */
    private function currentReadingBlock(array $currentReading, string $privateMemo): string
    {
        $reading = array_filter($currentReading, static fn($value): bool => is_string($value) && trim($value) !== '');
        $memo = trim($privateMemo);
        if ($reading === [] && $memo === '') return '';
        $lines = ["\nCURRENT EDITORIAL READING (refine, do not discard)"];
        $lines[] = 'A current artist-approved reading of this artwork already exists. Your task is to deepen and refine it in light of the image and the context above — preserve its central insights unless visible evidence contradicts them. Do not start from zero and do not discard what remains true.';
        if ($reading !== []) {
            $lines[] = json_encode($reading, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($memo !== '') {
            $lines[] = "ARTIST PRIVATE MEMO (direction for this revision, never public text):\n" . $memo;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Libro III Cap. 4: la investigacion de keywords es refuerzo opcional del
     * sistema — se inyecta sola cuando existe; su ausencia jamas bloquea ni
     * le exige nada al artista.
     */
    private function keywordResearchBlock(array $artwork): string
    {
        if (!$this->pdo || !class_exists('SeriesKeywordResearchService')) return '';
        $seriesId = (int)($artwork['series_id'] ?? 0);
        $userId = (int)($artwork['user_id'] ?? 0);
        if ($seriesId <= 0 || $userId <= 0) return '';
        try {
            $research = (new SeriesKeywordResearchService($this->pdo))->promptContext($userId, $seriesId);
        } catch (Throwable) {
            return '';
        }
        if (($research['status'] ?? '') === 'not_validated' && ($research['candidates'] ?? []) === []) return '';
        return "\nKEYWORD RESEARCH EVIDENCE\n"
            . (string)($research['instruction'] ?? '')
            . "\n" . json_encode(
                ['status' => $research['status'] ?? '', 'selected' => $research['selected'] ?? [], 'candidates' => array_slice((array)($research['candidates'] ?? []), 0, 40)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n";
    }

    private function orientation(array $artwork): string
    {
        $w=(float)($artwork['width']??0); $h=(float)($artwork['height']??0);
        return $w>0&&$h>0 ? ($w>$h?'horizontal':($h>$w?'vertical':'square')) : '';
    }
}
