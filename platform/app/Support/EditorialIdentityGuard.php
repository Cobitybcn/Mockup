<?php
declare(strict_types=1);

/**
 * EDITORIAL_CORE.md — red de seguridad de identidad.
 *
 * La identidad de obra y serie la decide el artista (Libro I). El titulo real
 * es entrada de todo generador, jamas salida; este guardian corrige cualquier
 * mencion divergente que un modelo cuele en la prosa, en el origen (analisis),
 * al guardar y al publicar. Es la UNICA implementacion de esta reescritura:
 * cualquier otra copia de la logica es una regresion del contrato.
 */
final class EditorialIdentityGuard
{
    /**
     * Fuerza la identidad confirmada por el artista sobre un borrador de
     * analisis: titulo/subtitulo reales en el nivel superior y dentro de
     * canonical_editorial, y reescritura de cualquier mencion del nombre
     * divergente que el modelo haya dejado en el resto del texto.
     *
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    public static function forceIdentity(array $draft, string $realTitle, string $realSubtitle = ''): array
    {
        $realTitle = trim($realTitle);
        if ($realTitle === '') {
            return $draft;
        }

        $inventedTitles = [];
        foreach ([
            $draft['title'] ?? null,
            $draft['canonical_editorial']['title'] ?? null,
        ] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && strcasecmp($candidate, $realTitle) !== 0) {
                $inventedTitles[] = $candidate;
            }
        }

        if (array_key_exists('title', $draft)) {
            $draft['title'] = $realTitle;
        }
        if ($realSubtitle !== '' && array_key_exists('subtitle', $draft)) {
            $draft['subtitle'] = $realSubtitle;
        }
        if (isset($draft['canonical_editorial']) && is_array($draft['canonical_editorial'])) {
            $draft['canonical_editorial']['title'] = $realTitle;
            if ($realSubtitle !== '') {
                $draft['canonical_editorial']['subtitle'] = $realSubtitle;
            }
        }

        if ($inventedTitles !== []) {
            $draft = self::rewriteAliases($draft, $realTitle, $inventedTitles, '', []);
        }

        return $draft;
    }

    /**
     * Reescritura recursiva de alias historicos de obra y serie hacia los
     * nombres vigentes, sobre cualquier estructura de contenido.
     *
     * Con limites de palabra: un alias corto no corrompe palabras que lo
     * contienen (p.ej. "Sol" dentro de "Solitude").
     *
     * @param array<string,mixed> $content
     * @param list<string> $historicalArtworkTitles
     * @param list<string> $historicalSeriesTitles
     * @return array<string,mixed>
     */
    public static function rewriteAliases(
        array $content,
        string $currentArtworkTitle,
        array $historicalArtworkTitles,
        string $currentSeriesTitle,
        array $historicalSeriesTitles
    ): array {
        $rewrite = function (mixed $value) use (
            &$rewrite,
            $currentArtworkTitle,
            $historicalArtworkTitles,
            $currentSeriesTitle,
            $historicalSeriesTitles
        ): mixed {
            if (is_array($value)) {
                foreach ($value as $key => $nested) {
                    $value[$key] = $rewrite($nested);
                }
                return $value;
            }
            if (!is_string($value) || $value === '') return $value;

            if ($currentArtworkTitle !== '') {
                foreach ($historicalArtworkTitles as $historicalTitle) {
                    $historicalTitle = trim((string)$historicalTitle);
                    if ($historicalTitle !== '' && strcasecmp($historicalTitle, $currentArtworkTitle) !== 0) {
                        $value = self::replaceBounded($historicalTitle, $currentArtworkTitle, $value);
                    }
                }
            }
            if ($currentSeriesTitle !== '') {
                foreach ($historicalSeriesTitles as $historicalSeries) {
                    $historicalSeries = trim((string)$historicalSeries);
                    if ($historicalSeries === '' || strcasecmp($historicalSeries, $currentSeriesTitle) === 0) continue;
                    $compactHistorical = (string)preg_replace('/\s+/u', '', $historicalSeries);
                    $compactCurrent = (string)preg_replace('/\s+/u', '', $currentSeriesTitle);
                    $pairs = [
                        $historicalSeries . ' Series' => $currentSeriesTitle . ' series',
                        'Series ' . $historicalSeries => $currentSeriesTitle . ' series',
                        'Serie ' . $historicalSeries => 'serie ' . $currentSeriesTitle,
                        'series ' . $historicalSeries => $currentSeriesTitle . ' series',
                        'serie ' . $historicalSeries => 'serie ' . $currentSeriesTitle,
                        '#' . $compactHistorical . 'Series' => '#' . $compactCurrent,
                    ];
                    foreach ($pairs as $from => $to) {
                        $value = self::replaceBounded($from, $to, $value);
                    }
                }
            }
            return $value;
        };

        return $rewrite($content);
    }

    /**
     * Reemplazo case-insensitive respetando limites de palabra en los
     * extremos alfanumericos del alias (los no alfanumericos, como "#",
     * definen su propio limite).
     */
    private static function replaceBounded(string $needle, string $replacement, string $haystack): string
    {
        $pattern = preg_quote($needle, '/');
        $prefix = preg_match('/^[\p{L}\p{N}]/u', $needle) ? '(?<![\p{L}\p{N}])' : '';
        $suffix = preg_match('/[\p{L}\p{N}]$/u', $needle) ? '(?![\p{L}\p{N}])' : '';
        $result = preg_replace(
            '/' . $prefix . $pattern . $suffix . '/iu',
            str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement),
            $haystack
        );
        return is_string($result) ? $result : $haystack;
    }
}
