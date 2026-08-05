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
require_once __DIR__ . '/../app/Services/InfluencesAnalysisService.php';

$userId = 0;
$apply = false;
$limit = 0;
$soloPies = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $apply = true; continue; }
    if ($arg === '--captions-only') { $soloPies = true; continue; }
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

// Que le falta a cada obra. El paso escribe SOLO lo que falta, asi que la
// definicion de pendiente abarca todo el material del canal: listing en el
// idioma de publicacion, listing en el idioma de trabajo (material del
// artista) y analisis de influencias en ambos, si hay afinidades declaradas.
$perfil = ArtistProfile::findForUser($userId);
$hayAfinidades = ArtistReferences::names((string)($perfil['reference_artists'] ?? '')) !== [];
$faltanCampos = static function (array $contenido): bool {
    foreach (['saatchi_title', 'saatchi_description', 'saatchi_keywords'] as $campo) {
        if (trim((string)($contenido[$campo] ?? '')) === '') {
            return true;
        }
    }
    return false;
};

$pendientes = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!(int)$row['publicado']) {
        continue;
    }
    $listing = json_decode((string)$row['listing'], true) ?: [];
    $master = json_decode((string)$row['master'], true) ?: [];
    $faltaEn = $faltanCampos($listing);
    $faltaEs = $working !== $listingLocale && $faltanCampos($master);
    $faltaInflu = $hayAfinidades && (
        trim((string)($master['influences_analysis'] ?? '')) === ''
        || trim((string)($listing['influences_analysis'] ?? '')) === ''
    );
    $falta = $faltaEn || $faltaEs || $faltaInflu;
    if ($soloPies) {
        // En modo pies, pendiente es la obra a la que le falta algun pie en las
        // primeras imagenes de su composicion, tenga o no el listing.
        $c = $pdo->prepare("SELECT COUNT(*) FROM artwork_sheets a
            JOIN publications p ON p.artwork_sheet_id=a.id AND p.user_id=a.user_id
            JOIN publication_items i ON i.publication_id=p.id
            JOIN mockup_sheets ms ON ms.id=i.mockup_sheet_id
            WHERE a.user_id=? AND a.canonical_artwork_id=? AND COALESCE(a.status,'')<>'merged'
              AND p.id=(SELECT MAX(p2.id) FROM publications p2 WHERE p2.artwork_sheet_id=a.id AND p2.user_id=a.user_id)
              AND TRIM(COALESCE(ms.saatchi_caption,''))=''");
        $c->execute([$userId, (int)$row['id']]);
        $falta = (int)$c->fetchColumn() > 0;
    }
    if ($falta) {
        $pendientes[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['final_title'],
            'en' => $faltaEn,
            'es' => $faltaEs,
            'influ' => $faltaInflu,
        ];
    }
}
if ($limit > 0) {
    $pendientes = array_slice($pendientes, 0, $limit);
}

echo 'Obras con lectura aprobada y material de canal faltante: ' . count($pendientes) . "\n";
foreach ($pendientes as $p) {
    $faltantes = array_keys(array_filter([
        "listing-{$listingLocale}" => (bool)($p['en'] ?? false),
        "listing-{$working}" => (bool)($p['es'] ?? false),
        'influencias' => (bool)($p['influ'] ?? false),
    ]));
    echo "  #{$p['id']}  {$p['title']}  [" . implode(' · ', $faltantes) . "]\n";
}
if (!$apply) {
    echo "\n(simulacion: agrega --apply para escribir)\n";
    exit(0);
}

$listado = new SaatchiListingService($pdo);
$influencias = new InfluencesAnalysisService($pdo);
$ok = 0;
$revisar = [];

foreach ($pendientes as $i => $p) {
    printf("\n[%d/%d] #%d %s\n", $i + 1, count($pendientes), $p['id'], $p['title']);

    if ($soloPies) {
        try {
            $r = $listado->generateCaptionsOnly($userId, $p['id']);
            $escritos = $listado->saveCaptions($userId, $r['captions']);
            printf("   pies: %d escritos de %d\n", $escritos, count($r['captions']));
            foreach ($r['errors'] as $e) { echo "   ! {$e}\n"; $revisar[] = "#{$p['id']} pie: {$e}"; }
            if ($escritos > 0) { $ok++; }
        } catch (Throwable $e) {
            echo '   pies FALLO: ' . $e->getMessage() . "\n";
            $revisar[] = "#{$p['id']} pies: " . $e->getMessage();
        }
        continue;
    }

    // Cada pieza se escribe SOLO si falta. Lo que ya existe no se toca.
    $piezasFallidas = 0;

    if ($p['en']) {
        try {
            $r = $listado->generate($userId, $p['id']);
            $estado = (string)$r['validation']['status'];
            printf("   listing %s: %-16s titulo %d/65  descripcion %d/1000  keywords %d  pies %d\n",
                $listingLocale,
                $estado,
                mb_strlen((string)$r['title']),
                mb_strlen((string)$r['description']),
                count((array)$r['keywords']),
                count(array_filter((array)$r['captions'], static fn ($c): bool => trim((string)$c) !== '')));
            if ($estado === 'ok') {
                $listado->save($userId, $p['id'], $r);
            } else {
                $piezasFallidas++;
                $revisar[] = "#{$p['id']} listing {$listingLocale}: " . implode(' · ', (array)$r['validation']['errors']);
            }
            foreach ((array)$r['validation']['warnings'] as $w) {
                if (str_starts_with((string)$w, 'Pie sin escribir')) {
                    echo "   aviso: {$w}\n";
                }
            }
        } catch (Throwable $e) {
            $piezasFallidas++;
            echo "   listing {$listingLocale} FALLO: " . $e->getMessage() . "\n";
            $revisar[] = "#{$p['id']} listing {$listingLocale}: " . $e->getMessage();
        }
    } else {
        echo "   listing {$listingLocale}: conservado\n";
    }

    if ($p['es']) {
        // El material del artista, en su idioma de trabajo: derivado de su
        // propia lectura, sin pies (viven en una sola columna, del canal real).
        try {
            $r = $listado->generate($userId, $p['id'], $working, false);
            $estado = (string)$r['validation']['status'];
            printf("   listing %s: %-16s\n", $working, $estado);
            if ($estado === 'ok') {
                $listado->save($userId, $p['id'], $r);
            } else {
                $piezasFallidas++;
                $revisar[] = "#{$p['id']} listing {$working}: " . implode(' · ', (array)$r['validation']['errors']);
            }
        } catch (Throwable $e) {
            $piezasFallidas++;
            echo "   listing {$working} FALLO: " . $e->getMessage() . "\n";
            $revisar[] = "#{$p['id']} listing {$working}: " . $e->getMessage();
        }
    }

    if ($p['influ']) {
        foreach (array_unique([$working, $listingLocale]) as $idiomaInflu) {
            try {
                echo '   ' . $influencias->deriveIfEmpty($userId, $p['id'], $idiomaInflu) . "\n";
            } catch (Throwable $e) {
                $piezasFallidas++;
                echo "   influencias {$idiomaInflu} FALLO: " . $e->getMessage() . "\n";
                $revisar[] = "#{$p['id']} influencias {$idiomaInflu}: " . $e->getMessage();
            }
        }
    }

    if ($piezasFallidas === 0) {
        $ok++;
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
