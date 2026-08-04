<?php
declare(strict_types=1);

/**
 * Relleno del paquete de Saatchi en todas las obras que ya tienen su lectura
 * aprobada y todavia no lo tienen.
 *
 * Es una herramienta de una sola pasada, no una funcion del producto: para una
 * obra nueva el paso vive en la ficha, dentro del paquete editorial. Esto existe
 * para el catalogo que quedo atras, y se retira cuando ya no haga falta.
 *
 * Escribe SOLO borradores, por el dueno de la tabla. No publica nada.
 *
 *   php scripts/saatchi_backfill.php [user_id] [--apply] [--limit=N]
 *
 * Sin --apply solo lista que haria.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Services/SaatchiListingService.php';

$userId = 0;
$apply = false;
$limit = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $apply = true; continue; }
    if (str_starts_with($arg, '--limit=')) { $limit = max(0, (int)substr($arg, 8)); continue; }
    if (ctype_digit($arg)) { $userId = (int)$arg; }
}
if ($userId <= 0) {
    fwrite(STDERR, "Falta el user_id.\n");
    exit(1);
}

$pdo = Database::connection();
$editorial = new BilingualEditorialService($pdo);
$working = $editorial->sourceLocale($userId);
$listingLocale = $editorial->primaryAdaptationTarget($userId) ?: 'en';
// Candidatas: lectura aprobada en el idioma de trabajo y paquete de Saatchi
// faltante. Se lee crudo para no disparar los efectos de get().
$stmt = $pdo->prepare("SELECT a.id, a.final_title,
        (SELECT b.content_json FROM bilingual_editorial_content b
          WHERE b.user_id=? AND b.entity_type='artwork' AND b.entity_id=a.id AND b.locale=? LIMIT 1) master,
        (SELECT b.is_published FROM bilingual_editorial_content b
          WHERE b.user_id=? AND b.entity_type='artwork' AND b.entity_id=a.id AND b.locale=? LIMIT 1) publicado,
        (SELECT b.content_json FROM bilingual_editorial_content b
          WHERE b.user_id=? AND b.entity_type='artwork' AND b.entity_id=a.id AND b.locale=? LIMIT 1) listing
    FROM artworks a WHERE a.user_id=? ORDER BY a.id");
$stmt->execute([$userId, $working, $userId, $working, $userId, $listingLocale, $userId]);

$pendientes = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!(int)$row['publicado']) {
        continue;
    }
    $listing = json_decode((string)$row['listing'], true) ?: [];
    $falta = false;
    foreach (['saatchi_title', 'saatchi_description', 'saatchi_keywords'] as $campo) {
        if (trim((string)($listing[$campo] ?? '')) === '') {
            $falta = true;
        }
    }
    if ($falta) {
        $pendientes[] = ['id' => (int)$row['id'], 'title' => (string)$row['final_title']];
    }
}
if ($limit > 0) {
    $pendientes = array_slice($pendientes, 0, $limit);
}

echo 'Obras con lectura aprobada y textos de canal faltantes: ' . count($pendientes) . "\n";
foreach ($pendientes as $p) {
    echo "  #{$p['id']}  {$p['title']}\n";
}
if (!$apply) {
    echo "\n(simulacion: agrega --apply para escribir)\n";
    exit(0);
}

$listado = new SaatchiListingService($pdo);
$ok = 0;
$revisar = [];

foreach ($pendientes as $i => $p) {
    printf("\n[%d/%d] #%d %s\n", $i + 1, count($pendientes), $p['id'], $p['title']);
    try {
        $r = $listado->generate($userId, $p['id']);
        $estado = (string)$r['validation']['status'];
        printf("   listing: %-16s titulo %d/65  descripcion %d/1000  keywords %d  pies %d\n",
            $estado,
            mb_strlen((string)$r['title']),
            mb_strlen((string)$r['description']),
            count((array)$r['keywords']),
            count(array_filter((array)$r['captions'], static fn ($c): bool => trim((string)$c) !== '')));
        if ($estado === 'ok') {
            $listado->save($userId, $p['id'], $r);
            $ok++;
        } else {
            $revisar[] = "#{$p['id']} listing: " . implode(' · ', (array)$r['validation']['errors']);
        }
        foreach ((array)$r['validation']['warnings'] as $w) {
            if (str_starts_with((string)$w, 'Pie sin escribir')) {
                echo "   aviso: {$w}\n";
            }
        }
    } catch (Throwable $e) {
        echo '   listing FALLO: ' . $e->getMessage() . "\n";
        $revisar[] = "#{$p['id']} listing: " . $e->getMessage();
    }

}

echo "\n===== RESUMEN =====\n";
echo "Obras completas: {$ok} de " . count($pendientes) . "\n";
if ($revisar !== []) {
    echo "\nPara revisar:\n";
    foreach ($revisar as $r) {
        echo "  ! {$r}\n";
    }
}
echo "\nTodo quedo en BORRADOR. Nada se publico.\n";
