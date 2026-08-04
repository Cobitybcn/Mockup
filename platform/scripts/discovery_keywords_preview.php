<?php
declare(strict_types=1);

/**
 * Vocabulario de descubrimiento de una obra en un idioma, derivado de su lectura
 * aprobada en ESE idioma. No traduce: deriva.
 *
 *   php scripts/discovery_keywords_preview.php <artwork_id> <locale> [user_id] [--save]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Services/DiscoveryKeywordsService.php';

$artworkId = (int)($argv[1] ?? 0);
$locale = (string)($argv[2] ?? 'es');
$userId = (int)($argv[3] ?? 2);

if ($artworkId <= 0) {
    fwrite(STDERR, "Uso: php scripts/discovery_keywords_preview.php <artwork_id> <locale> [user_id] [--save]\n");
    exit(1);
}

$service = new DiscoveryKeywordsService(Database::connection());
$r = $service->generate($userId, $artworkId, $locale);
$validation = (array)$r['validation'];
$status = (string)$validation['status'];

echo "ESTADO  {$status}" . (!empty($validation['metrics']['repair_attempted']) ? ' (hubo reparacion)' : '') . "\n";
echo "FUENTE  lectura aprobada en " . strtoupper($locale) . " ({$r['source_characters']} caracteres)\n\n";

echo 'KEYWORDS (' . count($r['keywords']) . "/12)\n";
foreach ($r['keywords'] as $i => $k) {
    printf("  %2d. %-34s %2d car.\n", $i + 1, $k, mb_strlen($k));
}

foreach (['errors' => 'ERRORES', 'warnings' => 'AVISOS'] as $clave => $titulo) {
    if (($validation[$clave] ?? []) !== []) {
        echo "\n{$titulo}\n";
        foreach ((array)$validation[$clave] as $m) {
            echo '  ' . ($clave === 'errors' ? 'x' : '!') . " {$m}\n";
        }
    }
}

if (in_array('--save', $argv, true)) {
    if ($status !== 'ok') {
        echo "\nNo se guarda: quedo en {$status}.\n";
        exit(1);
    }
    $escritos = $service->save($userId, $artworkId, $r);
    echo "\nGuardado en la fila " . strtoupper($locale) . ": {$escritos} registros.\n";
}
