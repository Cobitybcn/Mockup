<?php
declare(strict_types=1);

final class PublicSlug
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        $value = strtr($value, [
            'Á'=>'A', 'À'=>'A', 'Â'=>'A', 'Ä'=>'A', 'Ã'=>'A', 'Å'=>'A',
            'á'=>'a', 'à'=>'a', 'â'=>'a', 'ä'=>'a', 'ã'=>'a', 'å'=>'a',
            'É'=>'E', 'È'=>'E', 'Ê'=>'E', 'Ë'=>'E',
            'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
            'Í'=>'I', 'Ì'=>'I', 'Î'=>'I', 'Ï'=>'I',
            'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
            'Ó'=>'O', 'Ò'=>'O', 'Ô'=>'O', 'Ö'=>'O', 'Õ'=>'O',
            'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'ö'=>'o', 'õ'=>'o',
            'Ú'=>'U', 'Ù'=>'U', 'Û'=>'U', 'Ü'=>'U',
            'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
            'Ñ'=>'N', 'ñ'=>'n', 'Ç'=>'C', 'ç'=>'c',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = $ascii;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    public static function universal(string $name, string $fallback = ''): string
    {
        $slug = self::normalize($name);
        return $slug !== '' ? $slug : self::normalize($fallback);
    }

    public static function mockup(string $artworkSlug, string $context): string
    {
        $artworkSlug = self::normalize($artworkSlug);
        $context = self::normalize($context);
        if ($artworkSlug === '') return $context;
        if ($context === '' || $context === $artworkSlug) return $artworkSlug . '-mockup';
        if (str_starts_with($context, $artworkSlug . '-')) {
            $context = substr($context, strlen($artworkSlug) + 1);
        }
        return $artworkSlug . '-' . trim($context, '-');
    }

    /**
     * Extracts the real visual context from bilingual editorial content.
     * `slug_context` is the explicit preferred source; older records fall back
     * to their Pinterest/SEO/title copy without adding synthetic SEO language.
     *
     * @param array<string,mixed> $editorial
     */
    public static function mockupContext(string $artworkTitle, array $editorial, string $fallbackTitle): string
    {
        $social = is_array($editorial['social'] ?? null) ? $editorial['social'] : [];
        $pinterest = is_array($social['pinterest'] ?? null) ? $social['pinterest'] : [];
        $candidate = trim((string)($editorial['slug_context'] ?? ''));
        if ($candidate === '') {
            $seoTitle = trim((string)($editorial['seo_title'] ?? ''));
            $candidate = trim((string)(preg_split('/\s*\|\s*/u', $seoTitle)[0] ?? ''));
        }
        $artworkTitle = trim($artworkTitle);
        $withoutArtworkTitle = static function (string $value) use ($artworkTitle): string {
            if ($artworkTitle !== '') {
                $value = preg_replace('/' . preg_quote($artworkTitle, '/') . '/iu', ' ', $value) ?? $value;
            }
            return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        };
        $candidate = $withoutArtworkTitle($candidate);
        if ($candidate === '') $candidate = $withoutArtworkTitle(trim($fallbackTitle));
        if ($candidate === '') $candidate = $withoutArtworkTitle(trim((string)($pinterest['title'] ?? '')));
        return self::normalize($candidate);
    }

    /** @return array{en:string,es:string} */
    public static function technicalMockupContexts(string $selectorStateJson, string $contextId = '', string $mockupFile = ''): array
    {
        $state = json_decode($selectorStateJson, true);
        $state = is_array($state) ? $state : [];
        $combination = is_array($state['combination'] ?? null) ? $state['combination'] : [];
        $world = trim((string)($combination['world_mother_category'] ?? $state['world_mother_category'] ?? ''));
        $camera = trim((string)($combination['camera_slot_name'] ?? ''));

        $worldEs = [
            'architecture studios' => 'estudio de arquitectura',
            'atelier' => 'taller de artista',
            'bohemian ateliers' => 'taller bohemio',
            'catalan modernisme' => 'interior modernista catalan',
            'contemporary galleries' => 'galeria contemporanea',
            'creative lofts' => 'loft creativo',
            'dark collector' => 'interior oscuro de coleccionista',
            'golden hour' => 'interior con luz dorada',
            'living room ibiza' => 'salon ibicenco',
            'luxury doube height' => 'interior de lujo de doble altura',
            'luxury double height' => 'interior de lujo de doble altura',
            'patina surfaces' => 'interior con superficies patinadas',
        ];
        $cameraEs = [
            '3 4 left view' => 'vista tres cuartos izquierda',
            '3 4 right view' => 'vista tres cuartos derecha',
            'aerial view' => 'vista aerea',
            'canvas close up' => 'primer plano del lienzo',
            'canvas corner close up' => 'detalle de esquina del lienzo',
            'canvas edge close up' => 'detalle del borde del lienzo',
            'floor leaning artwork 3 4 view' => 'obra apoyada en el suelo vista tres cuartos',
            'front view' => 'vista frontal',
            'low angle nadir' => 'vista nadir',
            'low angle wall floor view' => 'vista baja entre pared y suelo',
            'nadir extreme low angle view' => 'vista nadir extrema',
            'vista aerea cenital de obra en suelo con contexto ambiental' => 'vista aerea cenital de obra en suelo',
        ];

        if ($world === '' && $camera === '') {
            $sourceFile = basename((string)($state['input_mockup_file'] ?? $mockupFile));
            $sourceSlug = self::normalize(pathinfo($sourceFile, PATHINFO_FILENAME));
            foreach (array_keys($worldEs) as $worldName) {
                if ($sourceSlug !== '' && str_contains($sourceSlug, self::normalize($worldName))) {
                    $world = $worldName;
                    break;
                }
            }
            foreach (array_keys($cameraEs) as $cameraName) {
                if ($sourceSlug !== '' && str_contains($sourceSlug, self::normalize($cameraName))) {
                    $camera = $cameraName;
                    break;
                }
            }
        }

        if ($world === '' && $camera === '') {
            $fallback = str_replace(['_', '-'], ' ', trim($contextId));
            return ['en' => self::normalize($fallback), 'es' => self::normalize($fallback)];
        }

        $worldSpanish = $worldEs[strtolower($world)] ?? $world;
        $cameraSpanish = $cameraEs[strtolower(self::normalizeAccentsForLookup($camera))] ?? $camera;

        return [
            'en' => self::normalize(trim($world . ' ' . $camera)),
            'es' => self::normalize(trim($worldSpanish . ' ' . $cameraSpanish)),
        ];
    }

    private static function normalizeAccentsForLookup(string $value): string
    {
        $slug = self::normalize($value);
        return str_replace('-', ' ', $slug);
    }

    /**
     * Numeric suffixes are used only when two records have no useful semantic
     * difference after normalization.
     *
     * @param array<string,bool> $used
     */
    public static function uniqueMockup(string $base, array &$used): string
    {
        $base = self::normalize($base);
        $slug = $base;
        $suffix = 2;
        while ($slug === '' || isset($used[$slug])) {
            $slug = ($base !== '' ? $base : 'mockup') . '-' . $suffix++;
        }
        $used[$slug] = true;
        return $slug;
    }
}
