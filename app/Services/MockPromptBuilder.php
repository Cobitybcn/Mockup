<?php
declare(strict_types=1);

class MockPromptBuilder
{
    public function build(array $context, array $profile, array $imageMeta): string
    {
        $orientation = $imageMeta['orientation'] ?? 'unknown';
        $width = $imageMeta['physical_size']['width_cm'] ?? null;
        $height = $imageMeta['physical_size']['height_cm'] ?? null;
        $depth = $imageMeta['physical_size']['depth_cm'] ?? null;

        $widthText = "{$orientation} canvas";
        if ($width && $height) {
            $widthText .= ", {$width} cm wide × {$height} cm high";
            if ($depth) {
                $widthText .= " × {$depth} cm deep";
            }
        } else {
            $widthText = "dimensions not provided";
        }

        $cameraView = trim((string)($context['camera_view'] ?? ''));
        $cameraDistance = trim((string)($context['camera_distance'] ?? ''));
        $cameraNotes = trim((string)($context['camera_angle_notes'] ?? ''));
        $mockupPrompt = trim((string)($context['mockup_prompt'] ?? ''));
        $lighting = trim((string)($context['lighting'] ?? ''));
        
        $humanProfile = $context['human_profile'] ?? null;
        
        $depthText = $depth ? " × {$depth} cm deep" : " × 4 cm deep";
        $humanHeightStr = ($humanProfile === 'male_180') ? "1.80m" : "1.55m";
        $humanHeightCm = ($humanProfile === 'male_180') ? 180 : 155;
        $genderNoun = ($humanProfile === 'male_180') ? "male" : "female";
        $genderSubject = ($humanProfile === 'male_180') ? "man" : "woman";
        $genderPronoun = ($humanProfile === 'male_180') ? "him" : "her";
        $genderPossessive = ($humanProfile === 'male_180') ? "his" : "her";

        $scaleDirective = "";
        if ($height && $width) {
            $pct = (int)round(($height / $humanHeightCm) * 100);
            $scaleDirective = " The artwork is {$width} cm wide × {$height} cm high{$depthText}. The human figure is {$humanHeightStr} tall. The artwork height is {$height} cm, so it must appear as approximately {$pct}% of the {$genderNoun} figure's full standing height.";
            if ($height < $humanHeightCm) {
                $scaleDirective .= " The artwork must appear clearly shorter than the {$genderSubject}, not equal to {$genderPossessive} full height and not taller than {$genderPronoun}.";
            } else if ($height > $humanHeightCm) {
                $scaleDirective .= " The artwork must appear taller than the {$genderSubject}'s full standing height.";
            }
        }

        if (isset($context['with_human']) && $context['with_human'] === false) {
            $humanRule = 'Do not include any human figure.';
        } else {
            if ($humanProfile === 'female_155') {
                $humanRule = 'Include exactly one elegant standing female figure (1.55m tall) for scale reference. The full-body figure must remain completely visible from head to shoes, standing on the exact same floor plane as the artwork, positioned at a comparable depth relative to the camera (not blocking or overlapping the artwork), and placed close enough to the artwork to serve as a reliable visual scale reference to verify and audit the physical scale of the artwork.' . $scaleDirective;
            } else if ($humanProfile === 'male_180') {
                $humanRule = 'Include exactly one elegant standing male figure (1.80m tall) for scale reference. The full-body figure must remain completely visible from head to shoes, standing on the exact same floor plane as the artwork, positioned at a comparable depth relative to the camera (not blocking or overlapping the artwork), and placed close enough to the artwork to serve as a reliable visual scale reference to verify and audit the physical scale of the artwork.' . $scaleDirective;
            } else {
                $humanRule = 'Include exactly one standing human figure for scale reference. The full-body figure must remain completely visible from head to shoes, standing on the exact same floor plane as the artwork, positioned at a comparable depth relative to the camera (not blocking or overlapping the artwork), and placed close enough to the artwork to serve as a reliable visual scale reference to verify and audit the physical scale of the artwork.' . $scaleDirective;
            }
        }
        
        $creativePromptBlock = $mockupPrompt !== ''
            ? "\n\nAI PROPOSED MOCKUP DIRECTION:\n{$mockupPrompt}"
            : '';

        $sceneName = $context['name'] ?? 'not specified';
        $scenePurpose = $context['purpose'] ?? 'not specified';
        $sceneDescription = $context['scene'] ?? 'not specified';
        $placement = $context['placement'] ?? 'hanging';

        $finalPrompt = <<<PROMPT
ARTWORK TECHNICAL DATA:
- Artwork size: {$widthText}.

MOCKUP ART DIRECTION:
- Scene Name: {$sceneName}
- Purpose: {$scenePurpose}
- Scene Description: {$sceneDescription}
- Lighting: {$lighting}
- Placement: {$placement}
- Human Figure: {$humanRule}{$creativePromptBlock}
PROMPT;

        // Convert fraction ratios like 120/155 or 80/180 into explicit percentages with height context
        $finalPrompt = preg_replace_callback(
            '#\b(\d+)\s*/\s*(155|180)(?:\s+of\s+the\s+1\.(?:55|80)m\s+(?:female\s+|male\s+)?figure\'s\s+(?:full\s+)?(?:standing\s+)?height)?\b#i',
            function ($matches) use ($height) {
                $denom = (int)$matches[2];
                $num = ($height !== null) ? (int)$height : (int)$matches[1];
                $pct = (int)round(($num / $denom) * 100);
                if ($denom === 155) {
                    return "{$pct}% of the 1.55m female figure's full standing height";
                } else {
                    return "{$pct}% of the 1.80m male figure's full standing height";
                }
            },
            $finalPrompt
        );

        return $finalPrompt;
    }
}
