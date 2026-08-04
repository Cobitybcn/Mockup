<?php
declare(strict_types=1);

/**
 * Vista previa del listing de Saatchi para una obra. No escribe nada.
 *
 *   php scripts/saatchi_listing_preview.php <artwork_id> [user_id]
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

echo "TITULO  (" . mb_strlen($r['title']) . "/65, presupuesto de subtitulo {$r['budget']})\n";
echo "  {$r['title']}\n\n";

echo "DESCRIPCION (" . mb_strlen($r['description']) . "/1000)
";
echo "  " . wordwrap($r['description'], 92, "
  ") . "

";
echo "KEYWORDS (" . count($r['keywords']) . "/12)\n";
foreach ($r['keywords'] as $i => $k) {
    printf("  %2d. %-22s %2d car.\n", $i + 1, $k, mb_strlen($k));
}

echo "\nPIES (" . count($r['captions']) . ")\n";
foreach ($r['captions'] as $file => $caption) {
    printf("  %-46s %2d car.  %s\n", mb_substr($file, 0, 46), mb_strlen($caption), $caption);
}

if ($r['warnings'] !== []) {
    echo "\nAVISOS\n";
    foreach ($r['warnings'] as $w) {
        echo "  ! {$w}\n";
    }
} else {
    echo "\nSin avisos: todo dentro de los limites de Saatchi.\n";
}

if (in_array('--save', $argv, true)) {
    $escritos = $service->save($userId, $artworkId, $r);
    echo "\nGuardado: {$escritos} registros. Abrí la sección Publicación de la obra para revisarlo.\n";
}
