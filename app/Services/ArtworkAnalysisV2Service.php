<?php
declare(strict_types=1);

final class ArtworkAnalysisV2Service
{
    public function __construct(private GeminiImageClient $client) {}

    public function generateDraft(array $artwork, array $artistProfile, string $imagePath, string $notes = ''): array
    {
        if (!is_file($imagePath)) throw new RuntimeException('Root artwork image not found for v2 analysis.');
        $artworkId = (int)($artwork['id'] ?? 0);
        if ($artworkId <= 0) throw new RuntimeException('Invalid artwork id for v2 analysis.');
        $excludeBase = pathinfo($imagePath, PATHINFO_FILENAME);
        $draftDir = __DIR__ . '/../../storage/artwork_analysis_v2_drafts';
        $strategy = DescriptionDiversityEngine::select(
            array_merge($artwork, ['artist_profile'=>$artistProfile]),
            [__DIR__ . '/../../analysis', $draftDir, __DIR__ . '/../../tmp/drafts'],
            basename($imagePath)
        );
        $titles = ArtworkOriginalityChecker::catalogueTitles(__DIR__ . '/../../analysis', $excludeBase);
        $prompt = strtr(ArtworkAnalysisV2::prompt(), [
            '{artist_profile_prompt}'=>ArtistProfile::hasContent($artistProfile) ? ArtistProfile::forPrompt($artistProfile) : '',
            '{catalogue_title_constraints}'=>$titles ? implode("\n", array_map(static fn(string $v): string=>'- '.$v, $titles)) : '- No existing titles were available.',
            '{description_opening_type}'=>(string)$strategy['description_opening_type'],
            '{description_opening_rhythm}'=>(string)$strategy['description_opening_rhythm'],
            '{description_structure_type}'=>(string)$strategy['description_structure_type'],
            '{recent_opening_types_to_avoid}'=>implode(', ', (array)$strategy['recent_opening_types_to_avoid']) ?: 'none recorded',
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
        $titleRejected = false;
        while ($attempts < 3) {
            $attempts++;
            $draft = $this->request($prompt, $imagePath);
            $this->finalize($draft, $artworkId, $imagePath, $strategy, $excludeBase, $attempts);
            $errors = ArtworkAnalysisV2::validate($draft, false);
            $titleRejected = ($draft['originality_check']['title_unique'] ?? false) !== true;
            if (!$titleRejected && !$errors) break;

            $reason = $titleRejected
                ? 'The title is too close to: ' . (string)($draft['originality_check']['closest_title'] ?? 'an existing title')
                : implode('; ', $errors);
            $specificCorrection = str_contains($reason, 'Generic AI opening')
                ? "\nBoth canonical_editorial.short_description and canonical_editorial.master_description must begin with concrete visible evidence. Their first words must not be This, In this, The, A, or An. Begin directly with a specific color relationship, form, edge, interval, surface, direction, or spatial tension visible in this image."
                : '';
            $prompt .= "\n\nREVISION REQUIRED — ATTEMPT {$attempts}\n{$reason}.{$specificCorrection}\nReturn the complete JSON again. Correct every listed issue without weakening the artwork-specific analysis or merely substituting synonyms.";
        }
        if ($titleRejected || $errors) {
            $problems = $errors;
            if ($titleRejected) $problems[] = 'Canonical title did not pass the catalogue originality check.';
            throw new RuntimeException('V2 analysis failed validation after three attempts: ' . implode(' ', array_unique($problems)));
        }

        if (!is_dir($draftDir) && !mkdir($draftDir, 0775, true) && !is_dir($draftDir)) throw new RuntimeException('Could not create the v2 draft directory.');
        $output = $draftDir . DIRECTORY_SEPARATOR . 'artwork-' . $artworkId . '.json';
        file_put_contents($output, json_encode($draft, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL);
        file_put_contents($output . '.prompt.txt', $prompt);
        return ['draft'=>$draft, 'file'=>$output];
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

    private function finalize(array &$draft, int $artworkId, string $imagePath, array $strategy, string $excludeBase, int $attempts): void
    {
        $draft['schema_version'] = ArtworkAnalysisV2::SCHEMA_VERSION;
        $draft['artwork_id'] = $artworkId;
        $draft['source']['image_file'] = basename($imagePath);
        $draft['source']['analysis_prompt_version'] = 'v2';
        $draft['source']['analyzed_at'] = date(DATE_ATOM);
        $draft['originality_check'] = ArtworkOriginalityChecker::check($draft, __DIR__ . '/../../analysis', $excludeBase);
        $functions = (array)($draft['editorial_strategy']['paragraph_functions']??[]);
        $paragraphs = preg_split('/\R\s*\R/', trim((string)($draft['canonical_editorial']['master_description']??''))) ?: [];
        $draft['editorial_strategy'] = $strategy;
        $draft['editorial_strategy']['paragraph_functions'] = $functions;
        $draft['editorial_strategy']['opening_paragraph'] = trim((string)($paragraphs[0]??''));
        $draft['review'] = ['analysis_status'=>'draft','editorial_status'=>'draft','reviewed_by'=>null,'reviewed_at'=>null,'notes'=>"Generation attempts: {$attempts}."];
    }

    private function orientation(array $artwork): string
    {
        $w=(float)($artwork['width']??0); $h=(float)($artwork['height']??0);
        return $w>0&&$h>0 ? ($w>$h?'horizontal':($h>$w?'vertical':'square')) : '';
    }
}
