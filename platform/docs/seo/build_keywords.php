<?php
declare(strict_types=1);

/**
 * Genera el diccionario de keywords del sitio a partir de la evidencia real del
 * catalogo: series, obras, textos publicados, colores declarados en la propia
 * descripcion, medidas y orientacion.
 *
 * Se hace por script y no a mano para que la asignacion sea AUDITABLE: cada
 * termino que le toca a una obra se puede rastrear hasta la regla que se lo dio
 * y hasta el dato que la justifica. Un listado tipeado a mano no permite eso.
 *
 * NO toca el sitio, ni metadatos, ni contenido. Solo escribe los cuatro
 * archivos de investigacion en esta carpeta.
 *
 *   php docs/seo/build_keywords.php <ruta-a-datos.json>
 */

if (PHP_SAPI !== 'cli') { exit(1); }
$fuente = $argv[1] ?? '';
if (!is_file($fuente)) { fwrite(STDERR, "Falta el volcado de datos.\n"); exit(1); }
$datos = json_decode((string)file_get_contents($fuente), true);
$aqui = __DIR__;

/* ─────────────────────────── EVIDENCIA POR OBRA ───────────────────────────
 * Los colores dominantes NO se detectan por expresion regular: buscar "rojo"
 * en el texto marcaba rojo en las 21 obras, porque casi todas lo mencionan en
 * algun lado. Se leyo la apertura de cada descripcion publicada, que es donde
 * el texto declara su campo dominante, y se anoto aca con la frase que lo
 * respalda. Esa frase queda como sourceData de cada obra.
 */
$dominantes = [
    10039 => ['colores' => ['orange', 'blue', 'green'], 'cita' => 'vibrant orange and light greenish-blue tones', 'confianza' => 'high'],
    10040 => ['colores' => ['blue', 'white', 'yellow'], 'cita' => 'large central field of light blue and white tones, framed by a vibrant yellow column', 'confianza' => 'high'],
    10060 => ['colores' => ['blue', 'red'], 'cita' => 'TAGS: Indigo Blue, Red', 'confianza' => 'medium'],
    10061 => ['colores' => ['green', 'red'], 'cita' => 'a dominant field of deep green', 'confianza' => 'high'],
    10077 => ['colores' => ['blue', 'red'], 'cita' => 'a deep range of dark blues, evoking indigo or ultramarine', 'confianza' => 'high'],
    10078 => ['colores' => ['red'], 'cita' => 'a vast, deep crimson color field that envelops the entire composition', 'confianza' => 'high'],
    10082 => ['colores' => ['brown', 'turquoise'], 'cita' => 'a vibrant turquoise block ... across a vast field of deep earth tones', 'confianza' => 'high'],
    10086 => ['colores' => ['blue', 'red'], 'cita' => 'The deep blue of the sky contrasts with an intense red block', 'confianza' => 'high'],
    10088 => ['colores' => ['orange', 'ochre', 'red'], 'cita' => 'dominated by intense oranges, ocher yellows, and crimson reds', 'confianza' => 'high'],
    10091 => ['colores' => ['orange', 'yellow', 'red'], 'cita' => 'large blocks of orange, yellow, and red', 'confianza' => 'high'],
    10093 => ['colores' => ['multicolor'], 'cita' => 'expansive chromatic fields stretching into horizontal bands', 'confianza' => 'medium'],
    10095 => ['colores' => ['blue', 'brown', 'black'], 'cita' => 'Deep indigo and cyan tones intertwine with earthy browns and intense blacks', 'confianza' => 'high'],
    10096 => ['colores' => ['teal', 'red'], 'cita' => 'a vast teal color field and geometric crimson red forms', 'confianza' => 'high'],
    10097 => ['colores' => ['green', 'red'], 'cita' => 'a vast dark green field', 'confianza' => 'high'],
    10098 => ['colores' => ['red'], 'cita' => 'a field of deep reds that plunge into shadows', 'confianza' => 'high'],
    10099 => ['colores' => ['ochre', 'yellow'], 'cita' => 'deep, earthy ochre tones ... against a vibrant, luminous yellow strip', 'confianza' => 'high'],
    10100 => ['colores' => ['red'], 'cita' => 'A vast field of deep red extends across the canvas', 'confianza' => 'high'],
    10101 => ['colores' => ['blue', 'rust', 'yellow'], 'cita' => 'a vertical block in a rust tone ... the vast surface of cobalt blue', 'confianza' => 'high'],
    10102 => ['colores' => ['blue', 'red', 'brown'], 'cita' => 'a vibrant crimson square on a vast indigo blue field, against a dense dark brown band', 'confianza' => 'high'],
    10103 => ['colores' => ['blue', 'red', 'ochre'], 'cita' => 'light blue geometric blocks upon fields of crimson red, ochre, and earth tones', 'confianza' => 'high'],
    10104 => ['colores' => ['blue', 'black'], 'cita' => 'A dominant void of deep navy blue and carbon black', 'confianza' => 'high'],
];

/* Rasgos visibles: estos SI se detectan en el texto, porque son afirmaciones
 * explicitas ("incised lines", "layered", "monolithic") y no adjetivos sueltos. */
$rasgosRe = [
    'incised_lines' => 'incis',
    'layering' => 'layer|strat|sediment',
    'blocks' => 'block|rectangul|square|monolith',
    'horizon' => 'horizon',
    'color_field' => 'color field|colour field|chromatic field',
    'texture' => 'textur|impasto|palette knife|scrape',
    'division' => 'divid|divis|fissur|band|segment',
    'geometric' => 'geometr',
    'void' => 'void|silence|empty|negative space',
];

/* ──────────────────────────── VOCABULARIO MAESTRO ────────────────────────────
 * Cada entrada: [en, es, categoria, prioridad, amplitud, intencion, aplica_a, condicion]
 *
 * La prioridad NO sigue a la amplitud. "abstract art" es amplisima y de baja
 * prioridad: compite con todo el mundo y no distingue nada. "layered abstract
 * painting with incised lines" es estrecha y de prioridad alta porque describe
 * lo que esta obra realmente es y quien la busca asi la quiere.
 */
$V = [];
$add = static function (array $t) use (&$V): void { $V[] = $t; };

// 1. Categoria artistica
foreach ([
    ['contemporary abstract painting', 'pintura abstracta contemporánea', 'high', 'medium', 'discovery', ''],
    ['original abstract painting', 'pintura abstracta original', 'high', 'medium', 'commercial', ''],
    ['contemporary abstract artist', 'artista abstracto contemporáneo', 'high', 'medium', 'discovery', 'artist'],
    ['abstract painter', 'pintor abstracto', 'medium', 'broad', 'discovery', 'artist'],
    ['abstract art on canvas', 'arte abstracto sobre lienzo', 'medium', 'medium', 'discovery', ''],
    ['original abstract art', 'arte abstracto original', 'medium', 'medium', 'commercial', ''],
    ['contemporary painting', 'pintura contemporánea', 'low', 'broad', 'discovery', ''],
    ['abstract art', 'arte abstracto', 'low', 'broad', 'discovery', ''],
    ['contemporary art', 'arte contemporáneo', 'low', 'broad', 'discovery', ''],
    ['painting on canvas', 'pintura sobre lienzo', 'medium', 'broad', 'discovery', ''],
    ['large abstract painting', 'pintura abstracta de gran formato', 'high', 'medium', 'commercial', 'todas miden 80cm o mas de lado mayor'],
    ['original canvas painting', 'pintura original sobre lienzo', 'medium', 'medium', 'commercial', ''],
] as [$en, $es, $p, $b, $i, $c]) { $add([$en, $es, 'artistic_category', $p, $b, $i, ['artwork', 'series', 'artist', 'mockup'], $c]); }

// 2. Estilo — el territorio propio del artista
foreach ([
    ['territorial abstraction', 'abstracción territorial', 'high', 'specific', 'discovery', 'declarado en los tags de casi todas las obras'],
    ['structural abstraction', 'abstracción estructural', 'high', 'specific', 'discovery', ''],
    ['minimalist abstract painting', 'pintura abstracta minimalista', 'high', 'medium', 'discovery', ''],
    ['brutalist abstract painting', 'pintura abstracta brutalista', 'high', 'specific', 'discovery', 'declarado en los tags'],
    ['geometric abstract painting', 'pintura abstracta geométrica', 'high', 'medium', 'discovery', 'solo con formas geometricas visibles'],
    ['color field painting', 'pintura de campos de color', 'high', 'specific', 'discovery', 'solo con campo cromatico dominante'],
    ['textured abstract painting', 'pintura abstracta matérica', 'high', 'medium', 'discovery', ''],
    ['material abstraction', 'abstracción material', 'medium', 'specific', 'discovery', ''],
    ['architectural abstraction', 'abstracción arquitectónica', 'medium', 'specific', 'discovery', 'solo donde el texto nombra estructuras'],
    ['contemplative abstract painting', 'pintura abstracta contemplativa', 'medium', 'specific', 'discovery', ''],
    ['abstract landscape painting', 'pintura de paisaje abstracto', 'medium', 'medium', 'discovery', 'solo con horizonte visible'],
    ['geological abstraction', 'abstracción geológica', 'low', 'specific', 'informational', 'STRATA lo limita: no reducir a metafora geologica'],
] as [$en, $es, $p, $b, $i, $c]) { $add([$en, $es, 'style', $p, $b, $i, ['artwork', 'series', 'mockup'], $c]); }

// 3. Caracteristicas visuales
foreach ([
    ['layered abstract painting', 'pintura abstracta en capas', 'high', 'specific', 'discovery', 'layering'],
    ['stratified surface', 'superficie estratificada', 'high', 'specific', 'discovery', 'layering'],
    ['incised lines', 'líneas incisas', 'high', 'specific', 'discovery', 'incised_lines'],
    ['abstract painting with incised lines', 'pintura abstracta con líneas incisas', 'high', 'long-tail', 'discovery', 'incised_lines'],
    ['geometric blocks', 'bloques geométricos', 'medium', 'specific', 'discovery', 'blocks'],
    ['monolithic forms', 'formas monolíticas', 'medium', 'specific', 'discovery', 'blocks'],
    ['horizon lines', 'líneas de horizonte', 'medium', 'specific', 'discovery', 'horizon'],
    ['chromatic fields', 'campos cromáticos', 'medium', 'specific', 'discovery', 'color_field'],
    ['textured surface', 'superficie texturada', 'medium', 'medium', 'discovery', 'texture'],
    ['impasto painting', 'pintura con empaste', 'medium', 'specific', 'discovery', 'texture'],
    ['spatial tension', 'tensión espacial', 'low', 'specific', 'informational', ''],
    ['divided composition', 'composición dividida', 'medium', 'specific', 'discovery', 'division'],
    ['negative space painting', 'pintura de espacio negativo', 'medium', 'specific', 'discovery', 'void'],
] as [$en, $es, $p, $b, $i, $rasgo]) { $add([$en, $es, 'visual_characteristic', $p, $b, $i, ['artwork', 'mockup'], $rasgo]); }

// 4. Tecnica y materiales — confirmados por el texto de las obras
foreach ([
    ['acrylic and oil on canvas', 'acrílico y óleo sobre lienzo', 'high', 'medium', 'discovery', ''],
    ['acrylic and oil painting', 'pintura de acrílico y óleo', 'medium', 'medium', 'discovery', ''],
    ['palette knife painting', 'pintura a espátula', 'medium', 'specific', 'discovery', 'solo donde el texto lo nombra'],
    ['layered paint', 'pintura en capas', 'medium', 'specific', 'discovery', ''],
    ['textured canvas', 'lienzo texturado', 'medium', 'medium', 'discovery', ''],
    ['glazed surface', 'superficie con veladuras', 'low', 'specific', 'informational', 'solo donde el texto nombra veladuras'],
] as [$en, $es, $p, $b, $i, $c]) { $add([$en, $es, 'technique_material', $p, $b, $i, ['artwork', 'mockup'], $c]); }

// 5. Color — solo el dominante declarado en la descripcion
$paleta = [
    'red' => ['red abstract painting', 'pintura abstracta roja'],
    'blue' => ['blue abstract painting', 'pintura abstracta azul'],
    'green' => ['green abstract painting', 'pintura abstracta verde'],
    'ochre' => ['ochre abstract painting', 'pintura abstracta ocre'],
    'yellow' => ['yellow abstract painting', 'pintura abstracta amarilla'],
    'orange' => ['orange abstract painting', 'pintura abstracta naranja'],
    'black' => ['black abstract painting', 'pintura abstracta negra'],
    'white' => ['white abstract painting', 'pintura abstracta blanca'],
    'brown' => ['earth tone abstract painting', 'pintura abstracta en tonos tierra'],
    'teal' => ['teal abstract painting', 'pintura abstracta turquesa'],
    'turquoise' => ['turquoise abstract painting', 'pintura abstracta turquesa'],
    'rust' => ['rust abstract painting', 'pintura abstracta en tono óxido'],
    'multicolor' => ['multicolor abstract painting', 'pintura abstracta multicolor'],
];
foreach ($paleta as $clave => [$en, $es]) {
    $add([$en, $es, 'color', 'high', 'medium', 'commercial', ['artwork', 'mockup'], 'color:' . $clave]);
}

// 6. Formato, tamaño y orientacion
foreach ([
    ['vertical abstract painting', 'pintura abstracta vertical', 'high', 'medium', 'commercial', 'orient:vertical'],
    ['horizontal abstract painting', 'pintura abstracta horizontal', 'high', 'medium', 'commercial', 'orient:horizontal'],
    ['large original painting', 'pintura original de gran formato', 'high', 'medium', 'commercial', 'size:large'],
    ['large vertical canvas', 'lienzo vertical de gran formato', 'medium', 'specific', 'commercial', 'orient:vertical'],
    ['large horizontal canvas', 'lienzo horizontal de gran formato', 'medium', 'specific', 'commercial', 'orient:horizontal'],
] as [$en, $es, $p, $b, $i, $c]) { $add([$en, $es, 'format_size_orientation', $p, $b, $i, ['artwork', 'mockup'], $c]); }

// 7. Conceptos — nunca solos: se combinan con la categoria
foreach ([
    ['territory', 'territorio'], ['memory', 'memoria'], ['erosion', 'erosión'],
    ['sediment', 'sedimento'], ['materiality', 'materialidad'], ['perception', 'percepción'],
    ['transformation', 'transformación'], ['silence', 'silencio'], ['inner landscape', 'paisaje interior'],
    ['threshold', 'umbral'], ['passage', 'tránsito'], ['boundary', 'límite'],
    ['emergence', 'emergencia'], ['distance', 'distancia'], ['presence', 'presencia'],
] as [$en, $es]) {
    $add([$en, $es, 'concept', 'low', 'broad', 'informational', ['series', 'artist'],
        'nunca como termino comercial suelto: combinar con abstract painting / pintura abstracta']);
}

// 8. Intencion de compra
foreach ([
    ['original abstract paintings for sale', 'pinturas abstractas originales en venta', 'high', 'medium', 'transactional'],
    ['buy original abstract painting', 'comprar pintura abstracta original', 'high', 'medium', 'transactional'],
    ['abstract art for sale', 'arte abstracto en venta', 'medium', 'broad', 'transactional'],
    ['buy art directly from the artist', 'comprar arte directamente al artista', 'high', 'specific', 'transactional'],
    ['contemporary art for collectors', 'arte contemporáneo para coleccionistas', 'medium', 'medium', 'commercial'],
    ['large original painting for sale', 'pintura original de gran formato en venta', 'high', 'long-tail', 'transactional'],
    ['original painting on canvas for sale', 'pintura original sobre lienzo en venta', 'medium', 'medium', 'transactional'],
] as [$en, $es, $p, $b, $i]) { $add([$en, $es, 'purchase_intent', $p, $b, $i, ['artwork', 'series', 'artist'], '']); }

// 9. Navegacional
foreach ([
    ['Maurizio Valch', 'Maurizio Valch', 'high', 'specific', 'navigational', ''],
    ['Maurizio Valch abstract painting', 'pintura abstracta de Maurizio Valch', 'high', 'specific', 'navigational', ''],
    ['Maurizio Valch artist', 'Maurizio Valch artista', 'high', 'specific', 'navigational', ''],
    ['Maurizio Valch original artwork', 'obra original de Maurizio Valch', 'high', 'specific', 'transactional', ''],
] as [$en, $es, $p, $b, $i, $c]) { $add([$en, $es, 'navigational', $p, $b, $i, ['artwork', 'series', 'artist', 'mockup'], $c]); }

/* ─────────── LONG TAIL: se COMPONEN de la evidencia, no se tipean ───────────
 * Cada combinacion nace de rasgos que la obra tiene documentados. Si una obra no
 * tiene el rasgo, la combinacion no existe para ella.
 */
$colorEn = ['red' => 'red', 'blue' => 'blue', 'green' => 'green', 'ochre' => 'ochre', 'yellow' => 'yellow',
    'orange' => 'orange', 'black' => 'black', 'white' => 'white', 'brown' => 'earth tone', 'teal' => 'teal',
    'turquoise' => 'turquoise', 'rust' => 'rust', 'multicolor' => 'multicolor'];
$colorEs = ['red' => 'roja', 'blue' => 'azul', 'green' => 'verde', 'ochre' => 'ocre', 'yellow' => 'amarilla',
    'orange' => 'naranja', 'black' => 'negra', 'white' => 'blanca', 'brown' => 'en tonos tierra', 'teal' => 'turquesa',
    'turquoise' => 'turquesa', 'rust' => 'en tono óxido', 'multicolor' => 'multicolor'];
/* Cada rasgo sabe si va ANTES o DESPUES del nucleo. Sin esto salian frases como
 * "large red with incised linesabstract painting": una union mecanica de
 * palabras, que es exactamente lo que no debe hacerse. */
$rasgoEn = [
    'incised_lines' => ['post', 'with incised lines'],
    'layering' => ['pre', 'layered'],
    'blocks' => ['post', 'with geometric blocks'],
    'horizon' => ['post', 'with horizon lines'],
    'color_field' => ['pre', 'color field'],
    'texture' => ['pre', 'textured'],
    'void' => ['post', 'with negative space'],
];
$rasgoEs = [
    'incised_lines' => ['post', 'con líneas incisas'],
    'layering' => ['post', 'en capas'],
    'blocks' => ['post', 'con bloques geométricos'],
    'horizon' => ['post', 'con líneas de horizonte'],
    'color_field' => ['post', 'de campos de color'],
    'texture' => ['post', 'matérica'],
    'void' => ['post', 'con espacio negativo'],
];

/** Compone una frase natural en vez de pegar palabras. */
$componer = static function (string $color, ?string $rasgo, bool $grande, array $tabla, string $idioma): string {
    if ($idioma === 'en') {
        $pre = $grande ? 'large ' : '';
        $nucleo = $color . ' abstract painting';
        if ($rasgo !== null && isset($tabla[$rasgo])) {
            [$donde, $palabra] = $tabla[$rasgo];
            $nucleo = $donde === 'pre' ? $palabra . ' ' . $nucleo : $nucleo . ' ' . $palabra;
        }
        return trim(preg_replace('/\s+/u', ' ', $pre . $nucleo) ?? '');
    }
    $frase = 'pintura abstracta ' . $color . ($grande ? ' de gran formato' : '');
    if ($rasgo !== null && isset($tabla[$rasgo])) {
        $frase .= ' ' . $tabla[$rasgo][1];
    }
    return trim(preg_replace('/\s+/u', ' ', $frase) ?? '');
};

/* ───────────────────────────── ASIGNACION ───────────────────────────── */
$obras = [];
$usoTermino = [];
$sinDatos = [];

foreach ($datos['obras'] as $o) {
    $id = (int)$o['id'];
    $ev = $dominantes[$id] ?? null;
    $texto = mb_strtolower(($o['contenido']['en']['description'] ?? '') . ' ' . ($o['contenido']['en']['tags'] ?? ''));
    $rasgos = [];
    foreach ($rasgosRe as $nombre => $re) { if (preg_match('/' . $re . '/iu', $texto)) $rasgos[] = $nombre; }

    $w = (float)$o['width']; $h = (float)$o['height'];
    $orient = $h > $w ? 'vertical' : ($w > $h ? 'horizontal' : 'square');
    $grande = max($w, $h) >= 100;
    $colores = $ev['colores'] ?? [];

    $aplica = static function (string $cond) use ($colores, $rasgos, $orient, $grande): bool {
        if ($cond === '') return true;
        if (str_starts_with($cond, 'color:')) return in_array(substr($cond, 6), $colores, true);
        if (str_starts_with($cond, 'orient:')) return substr($cond, 7) === $orient;
        if ($cond === 'size:large') return $grande;
        if (isset($GLOBALS['__rasgos_validos'][$cond])) return in_array($cond, $rasgos, true);
        return true;
    };
    $GLOBALS['__rasgos_validos'] = array_flip(array_keys($rasgosRe));

    $bucket = ['color' => [], 'format' => [], 'technique' => [], 'visual' => [], 'concept' => [], 'commercial' => [], 'secondary' => []];
    foreach ($V as [$en, $es, $cat, $prio, $amp, $int, $paginas, $cond]) {
        if (!in_array('artwork', (array)$paginas, true)) continue;
        if (in_array($cond, array_keys($rasgosRe), true) && !in_array($cond, $rasgos, true)) continue;
        if (!$aplica($cond)) continue;
        $par = ['en' => $en, 'es' => $es, 'priority' => $prio, 'breadth' => $amp, 'intent' => $int];
        $destino = match ($cat) {
            'color' => 'color', 'format_size_orientation' => 'format', 'technique_material' => 'technique',
            'visual_characteristic' => 'visual', 'concept' => 'concept',
            'purchase_intent', 'navigational' => 'commercial', default => 'secondary',
        };
        $bucket[$destino][] = $par;
        $usoTermino[$en][] = (string)$o['final_title'];
    }

    // Long tail compuesto: color dominante + rasgo + orientacion.
    $long = [];
    $c1 = $colores[0] ?? null;
    if ($c1 !== null) {
        foreach (array_slice($rasgos, 0, 3) as $r) {
            if (!isset($rasgoEn[$r])) continue;
            $long[] = [
                'en' => $componer($colorEn[$c1], $r, $grande, $rasgoEn, 'en'),
                'es' => $componer($colorEs[$c1], $r, $grande, $rasgoEs, 'es'),
            ];
        }
        $long[] = [
            'en' => $orient . ' ' . $colorEn[$c1] . ' abstract painting on canvas',
            'es' => 'pintura abstracta ' . $colorEs[$c1] . ' ' . ($orient === 'vertical' ? 'vertical' : 'horizontal') . ' sobre lienzo',
        ];
    }

    // La principal se guarda con sus ingredientes para poder desempatarla
    // despues: varias obras rojas darian la misma frase, y una keyword principal
    // repetida no distingue nada.
    $obraPrimaria = ['color' => $c1, 'colores' => $colores, 'rasgos' => $rasgos, 'grande' => $grande, 'orient' => $orient];
    $primaria = $c1 !== null
        ? ['en' => $componer($colorEn[$c1], $rasgos[0] ?? null, $grande, $rasgoEn, 'en'),
           'es' => $componer($colorEs[$c1], $rasgos[0] ?? null, $grande, $rasgoEs, 'es')]
        : ['en' => 'contemporary abstract painting', 'es' => 'pintura abstracta contemporánea'];

    if ($ev === null) { $sinDatos[] = ['artworkId' => $id, 'title' => $o['final_title'], 'falta' => 'color dominante sin verificar']; }

    $obras[] = [
        'artworkId' => (string)$id,
        'title' => (string)$o['final_title'],
        'series' => (string)$o['series'],
        'slug' => (string)($o['publicacion']['slug'] ?? ''),
        'orientation' => $orient,
        'dimensions_cm' => $w . 'x' . $h,
        'primaryKeyword' => $primaria,
        'longTailKeywords' => $long,
        'colorKeywords' => $bucket['color'],
        'formatKeywords' => $bucket['format'],
        'techniqueKeywords' => $bucket['technique'],
        'visualKeywords' => $bucket['visual'],
        'commercialKeywords' => $bucket['commercial'],
        'secondaryKeywords' => $bucket['secondary'],
        'excludedKeywords' => ['investment art', 'museum quality', 'masterpiece', 'highly collectible',
            'Rothko style painting', 'painting like Rothko', 'inspired by Newman'],
        '_primaria' => $obraPrimaria,
        'confidence' => $ev['confianza'] ?? 'low',
        'sourceData' => array_values(array_filter([
            $ev ? 'descripcion publicada: "' . $ev['cita'] . '"' : null,
            'medidas de la ficha: ' . $w . 'x' . $h . ' cm (' . $orient . ')',
            'rasgos detectados en el texto publicado: ' . (implode(', ', $rasgos) ?: 'ninguno'),
            'tags del catalogo: ' . mb_substr((string)($o['contenido']['en']['tags'] ?? ''), 0, 160),
        ])),
        'notes' => (string)$o['medium'] === ''
            ? 'El campo tecnica de la ficha esta VACIO en las 21 obras: la tecnica se tomo del texto, no de un dato estructurado.'
            : '',
        'mockupCount' => count($datos['mockups_por_obra'][$id] ?? []),
    ];
}

/* ────────── DESEMPATE: ninguna obra repite su keyword principal ──────────
 * Tres obras rojas de STRATA daban las tres "large red abstract painting with
 * incised lines". Una principal repetida no distingue nada, asi que la que
 * colisiona se profundiza con su segundo rasgo, y si aun colisiona, con su
 * orientacion.
 */
// Forma corta del color para las combinaciones de dos: "en tonos tierra" no se
// puede encadenar, "tierra" si.
$colorEsCorto = ['red' => 'rojo', 'blue' => 'azul', 'green' => 'verde', 'ochre' => 'ocre', 'yellow' => 'amarillo',
    'orange' => 'naranja', 'black' => 'negro', 'white' => 'blanco', 'brown' => 'tierra', 'teal' => 'turquesa',
    'turquoise' => 'turquesa', 'rust' => 'óxido', 'multicolor' => 'multicolor'];

$tomadas = [];
foreach ($obras as &$o) {
    $p = $o['_primaria'];
    unset($o['_primaria']);
    if ($p['color'] === null) { continue; }

    // Escalera de candidatas, de la mas simple a la mas especifica. Se toma la
    // primera libre: asi ninguna obra repite la principal de otra, y la que se
    // parece a otra termina describiendose con mas detalle, que es lo correcto.
    $candidatas = [];
    foreach (array_slice($p['rasgos'], 0, 4) as $r) {
        $candidatas[] = ['en' => $componer($colorEn[$p['color']], $r, $p['grande'], $rasgoEn, 'en'),
                         'es' => $componer($colorEs[$p['color']], $r, $p['grande'], $rasgoEs, 'es')];
    }
    foreach (array_slice($p['colores'], 1, 3) as $c2) {
        $dosEn = $colorEn[$p['color']] . ' and ' . $colorEn[$c2];
        $dosEs = $colorEsCorto[$p['color']] . ' y ' . $colorEsCorto[$c2];
        foreach (array_slice($p['rasgos'], 0, 2) as $r) {
            $candidatas[] = [
                'en' => $componer($dosEn, $r, $p['grande'], $rasgoEn, 'en'),
                'es' => str_replace('pintura abstracta ' . $dosEs, 'pintura abstracta en ' . $dosEs,
                    $componer($dosEs, $r, $p['grande'], $rasgoEs, 'es')),
            ];
        }
    }
    $candidatas[] = ['en' => trim(($p['grande'] ? 'large ' : '') . $p['orient'] . ' ' . $colorEn[$p['color']] . ' abstract painting'),
                     'es' => 'pintura abstracta ' . $colorEs[$p['color']] . ' ' . ($p['orient'] === 'vertical' ? 'vertical' : 'horizontal')];

    $elegida = $o['primaryKeyword'];
    foreach (array_merge([$elegida], $candidatas) as $cand) {
        if (!isset($tomadas[mb_strtolower($cand['en'])])) { $elegida = $cand; break; }
    }
    $tomadas[mb_strtolower($elegida['en'])] = $o['title'];
    $o['primaryKeyword'] = $elegida;
}
unset($o);

/* ─────────────────────────── CONFLICTOS ─────────────────────────── */
$conflictos = [];
$totalObras = count($obras);
foreach ($usoTermino as $termino => $enQue) {
    $n = count(array_unique($enQue));
    if ($n >= $totalObras * 0.85 && $n > 1) {
        $conflictos[] = [
            'keyword' => $termino,
            'affected' => $n . ' de ' . $totalObras . ' obras',
            'type' => 'sin poder de diferenciacion',
            'recommendation' => 'Sirve en la pagina de artista o de serie, no como keyword principal de una obra: no distingue una de otra.',
        ];
    }
}
$conflictos[] = [
    'keyword' => 'turquoise / teal',
    'affected' => 'FISSURA, TRIA, NEXUS',
    'type' => 'traduccion solapada',
    'recommendation' => 'En castellano las dos caen en "turquesa". Usar "turquesa" para teal y "azul turquesa" para turquoise, o unificar.',
];
$conflictos[] = [
    'keyword' => 'geological abstraction',
    'affected' => 'serie STRATA',
    'type' => 'contradice los limites interpretativos de la serie',
    'recommendation' => 'STRATA declara: "No reducir a una metafora geologica". Mantener en baja prioridad y solo en textos informativos.',
];

/* ─────────────────────────── SERIES ─────────────────────────── */
$porSerie = [];
foreach ($obras as $o) { $porSerie[$o['series']][] = $o; }

$territorio = [
    'STRATA' => ['en' => 'stratified abstract painting', 'es' => 'pintura abstracta estratificada',
        'rasgos' => 'capas, incisiones, campos cromaticos densos, bloques endurecidos, erosion',
        'excluir' => ['staircase painting', 'geological illustration', 'landscape painting'],
        'nota' => 'STRATA no usa escaleras. Sus limites prohiben reducirla a metafora geologica.'],
    'SITUS' => ['en' => 'minimalist abstract painting with negative space', 'es' => 'pintura abstracta minimalista con espacio negativo',
        'rasgos' => 'posicion, distancia, formas elementales situadas, vacio activo',
        'excluir' => ['house painting', 'architecture painting', 'map painting'],
        'nota' => 'Sus limites prohiben leer los rectangulos como casas, puertas o monolitos.'],
    'LIMINA' => ['en' => 'abstract painting of thresholds and passage', 'es' => 'pintura abstracta de umbrales y tránsito',
        'rasgos' => 'planos superpuestos, horizontes, recintos, escaleras, bloques suspendidos',
        'excluir' => ['cityscape', 'urban landscape painting'],
        'nota' => 'A diferencia de STRATA, LIMINA si incluye escaleras entre sus elementos.'],
    'MARE SOMNIORUM' => ['en' => 'oneiric abstract landscape painting', 'es' => 'pintura de paisaje abstracto onírico',
        'rasgos' => 'horizontes amplios, luz, planos abiertos, bloques aislados',
        'excluir' => ['seascape', 'mediterranean painting', 'surrealist painting'],
        'nota' => 'Sus limites prohiben la lectura literal de mar o costa y la surrealista automatica.'],
    'EMERSIO' => ['en' => 'abstract painting of emerging forms', 'es' => 'pintura abstracta de formas emergentes',
        'rasgos' => 'aparicion de la forma, separacion del territorio, presencia incipiente',
        'excluir' => ['creation painting', 'religious art'],
        'nota' => 'Sus limites prohiben el relato biblico de la creacion.'],
    'PRIMORDIUM' => ['en' => 'primordial abstract painting', 'es' => 'pintura abstracta primordial',
        'rasgos' => 'suelo originario, campos cromaticos, horizontes, monolitos, estructuras elementales',
        'excluir' => ['mythological painting', 'cosmogony art'],
        'nota' => 'Sus limites prohiben la lectura religiosa o mitologica del origen.'],
    'VORTEX' => ['en' => 'abstract painting with concentric forms', 'es' => 'pintura abstracta de formas concéntricas',
        'rasgos' => 'torsion, circulacion, dinamica centrifuga y centripeta',
        'excluir' => ['chaos art', 'psychological art'],
        'nota' => 'Sin obras publicadas hoy: 2017-2019.'],
    'FACIES' => ['en' => 'abstract face painting', 'es' => 'pintura abstracta de rostros',
        'rasgos' => 'rostro como superficie construida y fragmentada, planos, divisiones',
        'excluir' => ['portrait painting', 'psychological portrait'],
        'nota' => 'Sin obras publicadas hoy: 2019-2023.'],
];

$series = [];
foreach ($datos['series'] as $s) {
    $nombre = (string)$s['title'];
    $t = $territorio[$nombre] ?? null;
    $suyas = $porSerie[$nombre] ?? [];
    $colores = [];
    foreach ($suyas as $o) { foreach ($o['colorKeywords'] as $k) { $colores[$k['en']] = true; } }
    $series[] = [
        'series' => $nombre,
        'slug' => (string)$s['slug'],
        'years' => trim((string)($s['year_start'] ?? '') . '-' . (string)($s['year_end'] ?? '')),
        'publishedArtworks' => count($suyas),
        'primaryKeyword' => ['en' => $t['en'] ?? '', 'es' => $t['es'] ?? ''],
        'visualKeywords' => $t ? explode(', ', $t['rasgos']) : [],
        'colorKeywords' => array_keys($colores),
        'conceptualKeywords' => array_values(array_filter(array_map(
            static fn (array $v): ?string => $v[2] === 'concept' ? $v[0] : null, $V))),
        'commercialKeywords' => ['original abstract paintings for sale', 'buy art directly from the artist'],
        'excludedKeywords' => $t['excluir'] ?? [],
        'notes' => $t['nota'] ?? 'Serie sin territorio semantico asignado: falta revisarla.',
    ];
}

/* ─────────────────────────── SALIDA ─────────────────────────── */
$maestro = ['generated' => date('c'), 'source' => 'catalogo real de produccion, solo lectura', 'keywords' => [], 'keywordConflicts' => $conflictos];
foreach ($V as [$en, $es, $cat, $prio, $amp, $int, $paginas, $cond]) {
    $maestro['keywords'][] = ['keyword' => $en, 'language' => 'en', 'translation' => $es, 'category' => $cat,
        'priority' => $prio, 'breadth' => $amp, 'intent' => $int, 'appliesTo' => $paginas, 'condition' => $cond];
    $maestro['keywords'][] = ['keyword' => $es, 'language' => 'es', 'translation' => $en, 'category' => $cat,
        'priority' => $prio, 'breadth' => $amp, 'intent' => $int, 'appliesTo' => $paginas, 'condition' => $cond];
}
foreach ($obras as $o) {
    foreach ($o['longTailKeywords'] as $lt) {
        $maestro['keywords'][] = ['keyword' => $lt['en'], 'language' => 'en', 'translation' => $lt['es'],
            'category' => 'long_tail', 'priority' => 'high', 'breadth' => 'long-tail', 'intent' => 'commercial',
            'appliesTo' => ['artwork'], 'condition' => 'compuesto de los rasgos documentados de ' . $o['title']];
        $maestro['keywords'][] = ['keyword' => $lt['es'], 'language' => 'es', 'translation' => $lt['en'],
            'category' => 'long_tail', 'priority' => 'high', 'breadth' => 'long-tail', 'intent' => 'commercial',
            'appliesTo' => ['artwork'], 'condition' => 'compuesto de los rasgos documentados de ' . $o['title']];
    }
}
// Dedupe conservando el primero
$vistas = [];
$maestro['keywords'] = array_values(array_filter($maestro['keywords'], static function (array $k) use (&$vistas): bool {
    $clave = mb_strtolower($k['keyword']) . '|' . $k['language'];
    if (isset($vistas[$clave])) return false;
    $vistas[$clave] = true;
    return true;
}));
$maestro['total'] = count($maestro['keywords']);

$j = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents($aqui . '/keywords-master.json', json_encode($maestro, $j));
file_put_contents($aqui . '/keywords-by-series.json', json_encode(['generated' => date('c'), 'series' => $series], $j));
file_put_contents($aqui . '/keywords-by-artwork.json', json_encode([
    'generated' => date('c'), 'artworks' => $obras, 'insufficientData' => $sinDatos,
], $j));

printf("keywords-master.json      %d terminos, %d conflictos\n", $maestro['total'], count($conflictos));
printf("keywords-by-series.json   %d series\n", count($series));
printf("keywords-by-artwork.json  %d obras, %d sin datos suficientes\n", count($obras), count($sinDatos));
