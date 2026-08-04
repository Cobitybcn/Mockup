<?php
declare(strict_types=1);

/**
 * Quita las citas de medidas del texto editorial ya generado.
 *
 * No reemplaza ni reescribe: elimina el segmento y deja la frase corriendo, que
 * es posible porque el generador fue consistente y siempre las delimito con coma
 * y punto. La medida vive en su campo propio (tabla artworks) y el sitio la
 * publica ahi; repetirla en la prosa la duplica y la deja vencida al primer
 * cambio de medidas.
 */
class MeasurementCitations
{
    /**
     * @return array{text:string,removed:list<string>}
     */
    public static function strip(string $text): array
    {
        $removed = [];
        $working = $text;

        // "..., 120 x 160 x 3 cm. ..." — segmento entre coma y cierre: se va entero.
        $working = self::apply(
            '/,\s*\d+(?:[.,]\d+)?\s*(?:[x×X]\s*\d+(?:[.,]\d+)?\s*){1,2}(?:cm|centimeters?|centímetros?)\b\s*(?=[.,])/u',
            $working,
            $removed
        );

        // "the 3 cm deep canvas" -> "the canvas"
        $working = self::apply(
            '/\s*\d+(?:[.,]\d+)?\s*(?:cm|centimeters?|centímetros?)\s+(?:deep|wide|tall|high|de\s+(?:profundidad|ancho|alto))\b/iu',
            $working,
            $removed
        );

        // ", 80 cm." — medida suelta sin segunda dimension.
        $working = self::apply(
            '/,\s*\d+(?:[.,]\d+)?\s*(?:cm|centimeters?|centímetros?)\b(?=\s*[,.])/u',
            $working,
            $removed
        );

        if ($removed === []) {
            return ['text' => $text, 'removed' => []];
        }

        $working = (string)preg_replace('/\s+([.,])/u', '$1', $working);
        $working = (string)preg_replace('/([.,])\1+/u', '$1', $working);
        $working = (string)preg_replace('/\s{2,}/u', ' ', $working);

        return ['text' => trim($working), 'removed' => $removed];
    }

    /**
     * Fragmentos que siguen teniendo una medida y que esta clase no supo tratar.
     * Se reportan para decidir a mano en vez de inventar una regla nueva.
     *
     * @return list<string>
     */
    public static function leftovers(string $text): array
    {
        if (!preg_match_all('/[^.]{0,60}\d+(?:[.,]\d+)?\s*(?:cm|centimeters?|centímetros?)\b[^.]{0,40}/iu', $text, $matches)) {
            return [];
        }

        return array_values(array_map('trim', $matches[0]));
    }

    /**
     * @param list<string> $removed
     */
    private static function apply(string $pattern, string $text, array &$removed): string
    {
        return (string)preg_replace_callback(
            $pattern,
            static function (array $match) use (&$removed): string {
                $removed[] = trim($match[0]);
                return '';
            },
            $text
        );
    }
}
