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
     * @return list<array{name:string,rationale:string}>
     */
    public static function parse(string $raw): array
    {
        $referencias = [];
        foreach (preg_split('/\R/u', trim($raw)) ?: [] as $linea) {
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
}
