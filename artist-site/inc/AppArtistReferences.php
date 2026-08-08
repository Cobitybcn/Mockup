<?php
declare(strict_types=1);

/**
 * Los artistas de referencia que el artista declara, con el fundamento de cada
 * filiacion. Formato: una linea por artista, "Nombre: fundamento".
 *
 * Gemelo de platform/app/Support/ArtistReferences.php. Los dos leen el mismo
 * campo de la misma base pero los codigos se despliegan por separado, asi que
 * cada uno tiene su copia. Ambas suites verifican el MISMO formato de ejemplo:
 * si uno se va de forma, el otro test lo delata.
 *
 * En el sitio esto se publica en la pagina del artista y en ningun otro lado.
 * Nunca en la descripcion de una obra: seria copiar el perfil al texto publico
 * —que el prompt del sistema prohibe— y repetir el mismo parrafo en cada ficha
 * es contenido duplicado.
 */
final class AppArtistReferences
{
    /**
     * El campo admite un bloque por idioma, encabezado por su codigo entre
     * corchetes: [ES] y [EN]. Sin encabezados, todo el texto vale para
     * cualquier idioma.
     *
     * @return array<string,string>
     */
    private static function blocks(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (!preg_match('/^\s*\[[a-z]{2}\]\s*$/mi', $raw)) {
            return ['' => $raw];
        }

        $bloques = [];
        $actual = '';
        foreach (preg_split('/\R/u', $raw) ?: [] as $linea) {
            if (preg_match('/^\s*\[([a-z]{2})\]\s*$/i', $linea, $m)) {
                $actual = mb_strtolower($m[1]);
                $bloques[$actual] = $bloques[$actual] ?? '';
                continue;
            }
            if ($actual !== '') {
                $bloques[$actual] .= $linea . "\n";
            }
        }
        return array_filter(array_map('trim', $bloques), 'strlen');
    }

    /**
     * @return list<array{name:string,rationale:string}>
     */
    public static function parse(string $raw, string $locale = ''): array
    {
        $bloques = self::blocks($raw);
        if ($bloques === []) {
            return [];
        }
        $locale = mb_strtolower(trim($locale));
        $texto = $bloques[$locale] ?? (string)reset($bloques);

        $referencias = [];
        foreach (preg_split('/\R/u', trim($texto)) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            // Se parte en el PRIMER ":" nada mas: el fundamento puede tener los
            // suyos, y tambien comas.
            $corte = mb_strpos($linea, ':');
            $name = $corte === false ? $linea : trim(mb_substr($linea, 0, $corte));
            $rationale = $corte === false ? '' : trim(mb_substr($linea, $corte + 1));
            if ($name === '') {
                continue;
            }
            $referencias[] = ['name' => $name, 'rationale' => $rationale];
        }
        return $referencias;
    }

    /**
     * Solo los nombres, sin fundamento. Gemelo de
     * platform/app/Support/ArtistReferences.php::names() — existe porque un
     * consumidor puede necesitar reconocer a quien se nombra (por ejemplo,
     * para resaltarlo dentro de un texto ya publicado) sin tener autorizacion
     * para publicar el fundamento, que vive solo en la pagina del artista.
     *
     * Salen siempre del PRIMER bloque: el nombre se escribe igual en todos
     * los idiomas, asi que no hay version por idioma que elegir.
     *
     * @return list<string>
     */
    public static function names(string $raw): array
    {
        return array_values(array_map(
            static fn (array $ref): string => $ref['name'],
            self::parse($raw)
        ));
    }
}
