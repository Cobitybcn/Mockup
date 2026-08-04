<?php
declare(strict_types=1);

/**
 * Los artistas de referencia que el artista declara, con el fundamento de cada
 * filiacion.
 *
 * El formato es una linea por artista, "Nombre: fundamento". No es un capricho:
 * es como el artista los escribe naturalmente, asi que no hace falta ni un JSON
 * ni un editor especial.
 *
 *   Mark Rothko: los grandes campos cromaticos crean una atmosfera envolvente...
 *   Barnett Newman: intervenciones lineales minimas organizan amplios campos...
 *
 * El fundamento tiene DOS trabajos, y por eso no alcanza con guardar los nombres:
 *
 *   1. Es contenido publicable. Va en la pagina del artista, una vez, donde
 *      alguien que busca "Rothko" encuentra en palabras del artista que toma de
 *      el. Nunca en la descripcion de cada obra: el prompt del sistema prohibe
 *      copiar el perfil al texto publico, y repetirlo en cada ficha seria
 *      contenido duplicado.
 *
 *   2. Es el criterio para elegir a que obra aplica cada afinidad. Cada
 *      fundamento nombra una operacion visual concreta —"intervenciones lineales
 *      minimas organizan amplios campos de color"— que se puede contrastar
 *      contra lo que la obra muestra. Sin eso, la eleccion seria repetir siempre
 *      los mismos nombres en las 21 obras.
 *
 * Que lo declare el artista y no lo deduzca el modelo es la regla de siempre:
 * una filiacion es una afirmacion verificable y EDITORIAL_CORE prohibe inventar
 * validacion externa. Si el campo esta vacio, no se nombra a nadie.
 */
class ArtistReferences
{
    /**
     * El campo admite un bloque por idioma, encabezado por su codigo entre
     * corchetes:
     *
     *   [ES]
     *   Mark Rothko: los grandes campos cromaticos...
     *
     *   [EN]
     *   Mark Rothko: large colour fields...
     *
     * Sin encabezados, todo el texto vale para cualquier idioma — asi lo que ya
     * estaba cargado antes de admitir bloques sigue funcionando.
     *
     * @return array<string,string> idioma => texto del bloque; clave '' si no hay encabezados
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
     * @param string $locale idioma deseado; si no existe se usa el primer bloque
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
            // suyos, y tambien comas, que es por lo que esto no se separa por
            // comas como se hacia antes.
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
     * Solo los nombres. Es lo unico que viaja a un campo de keywords: en Saatchi
     * el tope son 20 caracteres y ningun fundamento entra ahi.
     *
     * Salen siempre del PRIMER bloque: "Barnett Newman" se escribe igual en
     * todos los idiomas, asi que no hay version por idioma que elegir.
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

    /**
     * El bloque que viaja al modelo cuando tiene que elegir afinidades: nombre y
     * fundamento, para que la eleccion se apoye en lo que la obra muestra.
     */
    public static function forPrompt(string $raw, string $locale = ''): string
    {
        $lineas = [];
        foreach (self::parse($raw, $locale) as $ref) {
            $lineas[] = $ref['rationale'] === ''
                ? '- ' . $ref['name']
                : '- ' . $ref['name'] . ' — ' . $ref['rationale'];
        }
        return $lineas === [] ? '' : implode("\n", $lineas);
    }
}
