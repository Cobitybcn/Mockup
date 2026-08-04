<?php
declare(strict_types=1);

/**
 * Vista previa del listing de Saatchi derivado de la lectura aprobada.
 * No escribe nada salvo que se pase --save, y solo si quedo en ok.
 *
 *   php scripts/saatchi_listing_preview.php <artwork_id> [user_id] [--save]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Services/SaatchiListingService.php';

$artworkId = (int)($argv[1] ?? 0);
$userId = (int)($argv[2] ?? 2);

if ($artworkId <= 0) {
    fwrite(STDERR, "Falta el id de la obra.\n");
    exit(1);
}

$service = new SaatchiListingService(Database::connection());
$r = $service->generate($userId, $artworkId);
$validation = (array)$r['validation'];
$status = (string)$validation['status'];
$metrics = (array)($validation['metrics'] ?? []);

echo "ESTADO  {$status}" . (!empty($metrics['repair_attempted']) ? ' (hubo pasada de reparacion)' : '') . "\n";
// El rotulo dice si la fuente esta aprobada o es un borrador. Antes decia
// "lectura aprobada" siempre, incluso cuando leia un borrador sin publicar.
echo 'FUENTE  ' . (($r['source_approved'] ?? false) ? 'lectura APROBADA' : 'BORRADOR sin aprobar')
    . ' en ' . strtoupper((string)($r['source_locale'] ?? '?'))
    . ' (' . (int)($metrics['source_reading_characters'] ?? 0) . " caracteres)\n";
echo 'DESTINO fila ' . strtoupper((string)$r['locale']) . " (el listing de Saatchi va en ingles)\n";

if ($status === 'requires_input') {
    foreach ((array)($validation['warnings'] ?? []) as $w) {
        echo "  ! {$w}\n";
    }
    exit(0);
}

echo "\nTITULO  (" . mb_strlen((string)$r['title']) . "/65, presupuesto de subtitulo "
    . (int)($metrics['subtitle_budget'] ?? 0) . ")\n  {$r['title']}\n";

echo "\nDESCRIPCION (" . mb_strlen((string)$r['description']) . "/1000, objetivo 850)\n";
echo '  ' . wordwrap((string)$r['description'], 92, "\n  ") . "\n";

echo "\nKEYWORDS (" . count((array)$r['keywords']) . "/12)\n";
foreach ((array)$r['keywords'] as $i => $k) {
    printf("  %2d. %-24s %2d car.\n", $i + 1, (string)$k, mb_strlen((string)$k));
}

echo "\nPIES (" . count((array)$r['captions']) . '/' . (int)($metrics['captions_expected'] ?? 0) . ')'
    . (!empty($metrics['caption_repair_attempted']) ? ' — hubo reparacion' : '') . "\n";
foreach ((array)$r['captions'] as $file => $caption) {
    printf("  %-46s %2d car.  %s\n", mb_substr((string)$file, 0, 46), mb_strlen((string)$caption), (string)$caption ?: '(vacio)');
}

if (($validation['errors'] ?? []) !== []) {
    echo "\nERRORES (la validacion sigue fallando tras la reparacion: no se publica solo)\n";
    foreach ((array)$validation['errors'] as $e) {
        echo "  x {$e}\n";
    }
}
if (($validation['warnings'] ?? []) !== []) {
    echo "\nAVISOS\n";
    foreach ((array)$validation['warnings'] as $w) {
        echo "  ! {$w}\n";
    }
}
if (($validation['errors'] ?? []) === [] && ($validation['warnings'] ?? []) === []) {
    echo "\nSin avisos: todo dentro de los limites de Saatchi.\n";
}

if (in_array('--save', $argv, true)) {
    if ($status !== 'ok') {
        echo "\nNo se guarda: el paquete quedo en {$status} y nunca se publica solo.\n";
        exit(1);
    }
    $service->save($userId, $artworkId, $r);
    echo "\nGuardado en el BORRADOR de la fila " . strtoupper((string)$r['locale']) . ".\n"
        . "No esta publicado: revisalo y aprobalo vos con la accion de publicar de la obra.\n";
}
