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

        $orientation = $imageMeta['orientation'] ?? 'unknown';
        $scaleText = $this->scaleText($imageMeta, $context);
        $timeOfDay = $context['time_of_day'] ?? 'day';
        
        $scaleRules = PromptSettings::mockupScaleRules();
        $negativeRules = PromptSettings::mockupNegativeRules();
        $qualityRules = PromptSettings::mockupQualityRules();
        $cameraRules = PromptSettings::mockupCameraRules();

        $physical = $imageMeta['physical_size'] ?? [];
        $width = $physical['width_cm'] ?? null;
        $height = $physical['height_cm'] ?? null;
        $depth = $physical['depth_cm'] ?? null;

        $widthText = "{$orientation} canvas";
        if ($width && $height) {
            $widthText .= ", {$width} cm wide × {$height} cm high";
            if ($depth) {
                $widthText .= " × {$depth} cm deep";
            }
        } else {
            $widthText = "dimensions not provided";
        }

        return <<<PROMPT
ARTWORK TECHNICAL DATA:
- Artwork size: {$widthText}.

MOCKUP ART DIRECTION:
- Scene Name: {$context['name']}
- Purpose: {$context['purpose']}
- Scene Description: {$context['scene']}
- Lighting: {$context['lighting']}
- Time of Day: {$timeOfDay}
- Placement: {$placement}
- Human Figure: {$humanRule}

CAMERA SELECTION RULES:
{$cameraRules}

NEGATIVE RULES:
{$negativeRules}
PROMPT;
    }

    public function scaleText(array $imageMeta, array $context): string
    {
        $physical = $imageMeta['physical_size'] ?? [];
        $width = $physical['width_cm'] ?? null;
        $height = $physical['height_cm'] ?? null;

        if ($width && $height) {
            return $this->scaleCategoryText((float)$width, (float)$height) . ' ' . $this->scaleAnchorText((float)$width, (float)$height, $context);
        }

        return 'Show it at believable real-world scale based on the artwork proportions.';
    }

    public function scaleAnchorText(float $width, float $height, array $context): string
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

    public function humanRule(array $context): string
    {
        if (empty($context['with_human'])) {
            return 'Do not include any human figure.';
        }

        $ethnicities = ['black', 'white', 'asian', 'hispanic', 'middle eastern'];
        $ethnicity = $ethnicities[array_rand($ethnicities)];

        return match ($context['human_profile'] ?? '') {
            'male_180' => "Include one standing adult {$ethnicity} man, 1.80 meters tall.",
            'female_155' => "Include one standing adult {$ethnicity} woman, 1.55 meters tall.",
            default => "Include one standing adult {$ethnicity} person.",
        };
    }

    public function scaleCategoryText(float $width, float $height): string
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
