<?php
declare(strict_types=1);

/**
 * Quita las citas de medidas del texto editorial ya generado.
 *
 * Uso:
 *   php scripts/strip_measurement_citations.php <email>            (pasada en seco)
 *   php scripts/strip_measurement_citations.php <email> --apply    (escribe)
 *
 * En seco por defecto: muestra cada texto antes y despues y no toca nada.
 * Con --apply guarda el original en storage/measurement-citations-backup-*.json
 * antes de escribir, para poder volver atras.
 *
 * Limpia las tres capas, porque limpiar solo una las deja volver:
 *   - artwork_sheets y mockup_sheets: lo que el sitio publica hoy.
 *   - bilingual_editorial_content: la memoria editorial que repuebla las fichas
 *     cuando se republica una obra.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$email = trim((string)($argv[1] ?? ''));
$apply = in_array('--apply', $argv, true);

if ($email === '') {
    fwrite(STDERR, "Falta el email del artista.\n");
    exit(1);
}

$pdo = Database::connection();
$userStatement = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
$userStatement->execute([$email]);
$userId = (int)$userStatement->fetchColumn();

if ($userId <= 0) {
    fwrite(STDERR, "No existe ese artista: {$email}\n");
    exit(1);
}

/** @var list<array{tabla:string,id:int,columna:string,antes:string,despues:string,quitado:string}> $cambios */
$cambios = [];
$pendientes = [];

// ————— Capa 1 y 2: las fichas que el sitio publica —————
$fichas = [
    'artwork_sheets' => ['subtitle', 'description', 'short_description', 'alt_text', 'caption'],
    'mockup_sheets' => ['description', 'alt_text', 'caption'],
];

foreach ($fichas as $tabla => $columnas) {
    $lista = implode(', ', $columnas);
    $filas = $pdo->prepare("SELECT id, {$lista} FROM {$tabla} WHERE user_id = ?");
    $filas->execute([$userId]);

    foreach ($filas->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        foreach ($columnas as $columna) {
            $antes = (string)($fila[$columna] ?? '');
            if ($antes === '') {
                continue;
            }
            $resultado = MeasurementCitations::strip($antes);
            if ($resultado['removed'] === []) {
                continue;
            }
            $cambios[] = [
                'tabla' => $tabla,
                'id' => (int)$fila['id'],
                'columna' => $columna,
                'antes' => $antes,
                'despues' => $resultado['text'],
                'quitado' => implode(' | ', $resultado['removed']),
            ];
            foreach (MeasurementCitations::leftovers($resultado['text']) as $resto) {
                $pendientes[] = "{$tabla}#{$fila['id']}.{$columna}: {$resto}";
            }
        }
    }
}

// ————— Capa 3: la memoria editorial que repuebla las fichas —————
$editorial = $pdo->prepare('SELECT id, locale, content_json, published_content_json FROM bilingual_editorial_content WHERE user_id = ?');
$editorial->execute([$userId]);

foreach ($editorial->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    foreach (['content_json', 'published_content_json'] as $columna) {
        $crudo = (string)($fila[$columna] ?? '');
        if (trim($crudo) === '') {
            continue;
        }
        $contenido = json_decode($crudo, true);
        if (!is_array($contenido)) {
            continue;
        }

        $quitado = [];
        $limpio = strip_citations_deep($contenido, $quitado);
        if ($quitado === []) {
            continue;
        }

        $cambios[] = [
            'tabla' => 'bilingual_editorial_content',
            'id' => (int)$fila['id'],
            'columna' => $columna . ' (' . (string)$fila['locale'] . ')',
            'antes' => $crudo,
            'despues' => (string)json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'quitado' => implode(' | ', $quitado),
        ];
    }
}

/**
 * @param list<string> $quitado
 */
function strip_citations_deep(mixed $valor, array &$quitado): mixed
{
    if (is_string($valor)) {
        $resultado = MeasurementCitations::strip($valor);
        foreach ($resultado['removed'] as $item) {
            $quitado[] = $item;
        }
        return $resultado['text'];
    }
    if (is_array($valor)) {
        foreach ($valor as $clave => $item) {
            $valor[$clave] = strip_citations_deep($item, $quitado);
        }
    }
    return $valor;
}

// ————— Informe —————
foreach ($cambios as $cambio) {
    echo "--- {$cambio['tabla']}#{$cambio['id']} · {$cambio['columna']}\n";
    if ($cambio['tabla'] !== 'bilingual_editorial_content') {
        echo "  ANTES:   " . mb_substr($cambio['antes'], 0, 150) . "\n";
        echo "  DESPUÉS: " . mb_substr($cambio['despues'], 0, 150) . "\n";
    }
    echo "  quitado: {$cambio['quitado']}\n\n";
}

echo "———————————————————————————————\n";
echo 'textos a corregir: ' . count($cambios) . "\n";
echo 'sin resolver:      ' . count($pendientes) . "\n";
foreach (array_unique($pendientes) as $pendiente) {
    echo "   ? {$pendiente}\n";
}

if (!$apply) {
    echo "\nPasada en seco: no se escribio nada. Agregá --apply para aplicarlo.\n";
    exit(0);
}

if ($cambios === []) {
    echo "\nNada que aplicar.\n";
    exit(0);
}

// ————— Respaldo antes de escribir —————
$respaldo = dirname(__DIR__) . '/storage/measurement-citations-backup-' . $userId . '-' . date('Ymd-His') . '.json';
if (file_put_contents($respaldo, (string)json_encode($cambios, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "No se pudo escribir el respaldo. No se aplico nada.\n");
    exit(1);
}
echo "\nRespaldo: {$respaldo}\n";

$pdo->beginTransaction();
try {
    foreach ($cambios as $cambio) {
        $columna = (string)preg_replace('/\s+\(.*\)$/', '', $cambio['columna']);
        $statement = $pdo->prepare("UPDATE {$cambio['tabla']} SET {$columna} = :valor WHERE id = :id AND user_id = :user_id");
        $statement->execute([
            'valor' => $cambio['despues'],
            'id' => $cambio['id'],
            'user_id' => $userId,
        ]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    fwrite(STDERR, 'Falló al aplicar, no se cambió nada: ' . $error->getMessage() . "\n");
    exit(1);
}

echo 'Aplicado: ' . count($cambios) . " textos.\n";
