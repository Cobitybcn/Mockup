<?php
declare(strict_types=1);

class MockPromptBuilder
{
    public function build(array $context, array $profile, array $imageMeta): string
    {
        $humanRule = $this->humanRule($context);

        $placement = ($context['placement'] ?? '') === 'detail'
            ? 'Create a detail-focused presentation while preserving the artwork identity.'
            : (($context['placement'] ?? '') === 'leaning'
                ? 'Place the artwork leaning naturally against the wall.'
                : 'Hang the artwork naturally on the wall at a believable height.');

        $curatorial = $profile['one_line_curatorial_read'] ?? 'Local mock analysis of the artwork.';
        $summary = $profile['style_summary'] ?? 'Prototype artwork profile.';
        $orientation = $imageMeta['orientation'] ?? 'unknown';
        $scaleText = $this->scaleText($imageMeta, $context);
        $season = $profile['seasonal_strategy']['primary_season'] ?? 'neutral';
        $audience = $profile['audience_profile']['primary'] ?? 'collector audience';
        $timeOfDay = $context['time_of_day'] ?? 'day';
        $artistProfileBlock = $this->artistProfileBlock($profile);
        $scaleRules = PromptSettings::mockupScaleRules();
        $negativeRules = PromptSettings::mockupNegativeRules();
        $qualityRules = PromptSettings::mockupQualityRules();

        return <<<PROMPT
Create a premium art mockup using the approved root artwork image.

PROMPT_RULESET_VERSION: admin_editable_v1

CRITICAL PRESERVATION RULES:
- Preserve the artwork exactly.
- Do not repaint, redesign, recolor, crop, stretch, symmetrize or reinterpret the artwork.
- Do not improve the painting as a new artwork.
- Preserve composition, colors, traces, brushwork, palette knife marks, incisions, texture and material vibration.
- The artwork is {$orientation} and must keep its original proportions.

SCALE RULES:
- {$scaleText}
{$scaleRules}

ART DIRECTION:
- Context name: {$context['name']}
- Purpose: {$context['purpose']}
- Scene: {$context['scene']}
- Lighting: {$context['lighting']}
- Camera: {$context['camera']}
- Time of day: {$timeOfDay}
- {$placement}
- {$humanRule}

CURATORIAL / COMMERCIAL READING:
- {$curatorial}
- Visual summary: {$summary}
- Target audience: {$audience}
- Seasonal direction: {$season}
- The environment must feel original, collector-grade, emotionally persuasive and specific to this artwork.
- Use sophisticated European or American collector interiors, private galleries, design homes or editorial art contexts.
{$artistProfileBlock}

NEGATIVE RULES:
{$negativeRules}

MOCKUP QUALITY RULES:
{$qualityRules}

The result should feel realistic, premium, faithful and commercially useful.
PROMPT;
    }

    private function artistProfileBlock(array $profile): string
    {
        $text = trim((string)($profile['_artist_profile_prompt'] ?? ''));

        if ($text === '') {
            return '';
        }

        return "\nARTIST PROFILE CONTEXT:\n"
            . "- Use this profile only to choose context, audience, region, atmosphere and commercial positioning.\n"
            . "- Do not modify, reinterpret, redraw or stylize the artwork because of this profile.\n"
            . $text;
    }

    private function scaleText(array $imageMeta, array $context): string
    {
        $physical = $imageMeta['physical_size'] ?? [];
        $width = $physical['width_cm'] ?? null;
        $height = $physical['height_cm'] ?? null;
        $depth = $physical['depth_cm'] ?? null;

        if ($width && $height) {
            $text = "The physical artwork measures {$width} cm wide x {$height} cm high.";

            if ($depth) {
                $text .= " Stretcher/support depth is {$depth} cm.";
            }

            $text .= ' ' . $this->scaleCategoryText((float)$width, (float)$height);
            $text .= ' ' . $this->scaleAnchorText((float)$width, (float)$height, $context);

            return $text;
        }

        return 'No physical size was provided; use a believable collector-home scale based on the artwork proportions.';
    }

    private function scaleAnchorText(float $width, float $height, array $context): string
    {
        $doorRatio = round(($height / 205) * 100);
        $consoleRatio = round($height / 80, 1);
        $baseboardUnits = round($height / 8, 1);
        $maleHeightRatio = round(($height / 180) * 100);
        $maleWidthRatio = round(($width / 180) * 100);
        $femaleHeightRatio = round(($height / 155) * 100);
        $femaleWidthRatio = round(($width / 155) * 100);
        $text = "Scale calibration anchors: against a common 205 cm interior door, the artwork height should be about {$doorRatio}% of the door height; against an 80 cm console table, the artwork height is about {$consoleRatio} times the table height; compared with an 8 cm floor baseboard, the artwork height is about {$baseboardUnits} baseboard heights. The room or gallery in this scene must have a realistic ceiling height of approximately 3.0 meters (10 feet) to anchor the scale of the room, preventing the space from looking like a giant cavernous hall.";

        if (!empty($context['with_human'])) {
            if (($context['human_profile'] ?? '') === 'male_180') {
                $text .= " The 1.80 m man must stand immediately next to the artwork (within 1 meter of the artwork's side edge) on the exact same depth and floor plane as the artwork. He must not stand in the background or foreground. Visually, the artwork height must be about {$maleHeightRatio}% of the man's standing height, and the artwork width must be about {$maleWidthRatio}% of the man's standing height.";
            } elseif (($context['human_profile'] ?? '') === 'female_155') {
                $text .= " The 1.55 m woman must stand immediately next to the artwork (within 1 meter of the artwork's side edge) on the exact same depth and floor plane as the artwork. She must not stand in the background or foreground. Visually, the artwork height must be about {$femaleHeightRatio}% of the woman's standing height, and the artwork width must be about {$femaleWidthRatio}% of the woman's standing height.";
            } else {
                $text .= " The human scale figure must stand immediately next to the artwork (within 1 meter) on the exact same depth plane and floor plane. They must not stand in the background or foreground.";
            }
            $text .= " Use only architecture, furniture and full standing human height as scale references. Do not introduce shoes or footwear as visual scale references.";
        }

        return $text;
    }

    private function humanRule(array $context): string
    {
        if (empty($context['with_human'])) {
            return 'Do not include any human figure.';
        }

        return match ($context['human_profile'] ?? '') {
            'male_180' => 'Include exactly one discreet standing adult man, 1.80 meters tall, only as a scale reference. He must stand immediately next to the artwork (within 1 meter of the artwork\'s side edge) on the exact same depth plane and floor plane as the wall where the painting is hung (not far away or in the background). He must be secondary, elegant, not posing, and must not distract from the artwork.',
            'female_155' => 'Include exactly one discreet standing adult woman, 1.55 meters tall, only as a scale reference. She must stand immediately next to the artwork (within 1 meter of the artwork\'s side edge) on the exact same depth plane and floor plane as the wall where the painting is hung (not far away or in the background). She must be secondary, elegant, not posing, and must not distract from the artwork.',
            default => 'Include exactly one discreet standing human figure only as a scale reference. The person must stand immediately next to the artwork (within 1 meter) on the exact same depth plane and floor plane as the wall where the painting is hung (not far away or in the background).',
        };
    }

    private function scaleCategoryText(float $width, float $height): string
    {
        $longSide = max($width, $height);
        $shortSide = min($width, $height);
        $area = $width * $height;
        $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');

        if ($longSide <= 50 || $area <= 2200) {
            return "This is a small {$orientation} artwork. It should feel intimate, clearly smaller than an armchair back, much smaller than a console table, and must not dominate the wall.";
        }

        if ($longSide <= 90 || $area <= 6000) {
            return "This is a modest medium {$orientation} artwork. It should read as a contained piece: narrower than an armchair or small cabinet if vertical, below door height, and not visually dominant on a large wall.";
        }

        if ($longSide <= 140 || $area <= 12000) {
            return "This is a medium {$orientation} artwork, not a monumental piece. It should read at real collector-home scale, comparable to a console table width when horizontal, clearly smaller than a large sofa, below door height, and never as a wall-filling exhibition panel.";
        }

        if ($longSide <= 220 || $area <= 28000) {
            return "This is a large statement {$orientation} artwork. It may command the wall, but must still keep believable clearance from furniture, floor, ceiling, doors and nearby architectural elements.";
        }

        return "This is a monumental {$orientation} artwork. It can dominate a collector wall, but scale must remain architecturally believable with correct clearance, hanging height and room proportions.";
    }
}
