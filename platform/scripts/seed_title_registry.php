<?php
declare(strict_types=1);

/**
 * Siembra del registro semantico de titulos (EDITORIAL_CORE Libro I Cap. 6)
 * con el catalogo existente del artista, y del ADN de titulos por serie con
 * las listas escritas por el artista (criterio 2026-07-29).
 *
 * Uso: php scripts/seed_title_registry.php [--dry-run]
 * Idempotente: re-ejecutar actualiza sin duplicar.
 * Imprime el mapa de colisiones al final — solo informa; renombrar es
 * decision del artista.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = Database::connection();
$service = new TitleSuggestionService($pdo);

// Idioma / significado / raiz semantica del catalogo actual, autoria asistida
// y revisable por el artista (el registro es editable; esto es la semilla).
$catalogMeta = [
    'CUSTODES' => ['Latín', 'custodios, guardianes', 'guardián'],
    'LIMEN' => ['Latín', 'umbral', 'umbral'],
    'ADITUS' => ['Latín', 'acceso, entrada', 'umbral'],
    'LUX REMOTA' => ['Latín', 'luz remota', 'luz'],
    'SOL DIVISUS' => ['Latín', 'sol dividido', 'luz'],
    'VIA SOLIS' => ['Latín', 'camino del sol', 'luz'],
    'INTERVALLUM' => ['Latín', 'intervalo, pausa', 'intervalo'],
    'INTERVALLA' => ['Latín', 'intervalos', 'intervalo'],
    'SUBMERSA' => ['Latín', 'sumergida', 'inmersión'],
    'ASCENSUS' => ['Latín', 'ascenso', 'ascenso'],
    'SIGNUM' => ['Latín', 'señal, signo', 'señal'],
    'VESTIGIA' => ['Latín', 'huellas, vestigios', 'huella'],
    'FISSURA' => ['Latín', 'fisura, grieta', 'fractura'],
    'QUINQUE' => ['Latín', 'cinco', 'número'],
    'DISPERSA' => ['Latín', 'dispersa', 'dispersión'],
    'NEXUS' => ['Latín', 'vínculo, nexo', 'vínculo'],
    'TRIA' => ['Latín', 'tres', 'número'],
    'DECLIVIS' => ['Latín', 'en pendiente, inclinado', 'pendiente'],
];

// ADN de titulos por serie — texto literal del criterio del artista.
$seriesDna = [
    'STRATA' => "Conceptos compatibles: territorio, memoria, huella, incisión, división, estrato, peso, tránsito, ascenso, creencia, fractura, frecuencia, permanencia, dispersión, umbral, luz y sombra.\nReglas específicas: STRATA no contiene escaleras. No interpretar las incisiones como simples elementos decorativos. Las incisiones pueden vincularse con memoria, huella o mapa. Los bloques o monolitos pueden vincularse con peso, permanencia o creencias. No convertir automáticamente la obra en un paisaje exterior. No describir la observación como \"territorio que todavía se está formando\". El pensamiento puede entenderse como territorio. La memoria puede entenderse como territorio ocupado. La observación pertenece a otro plano: consciencia.",
    'PRIMORDIUM' => "Conceptos compatibles: origen, suelo, materia primaria, horizonte, germen, nacimiento, base, tierra primigenia.\nEs una serie más exterior y originaria que STRATA.",
    'EMERSIO' => "Conceptos compatibles: aparición, emergencia, transformación, surgimiento, revelación, paso de lo oculto a lo visible.",
    'MARE SOMNIORUM' => "Conceptos compatibles: sueño, mar, noche, lejanía, horizonte, deriva, silencio, memoria mediterránea.\nEs un sueño personal, no una representación literal del Mediterráneo.",
    'FACIES' => "Conceptos compatibles: rostro, superficie, máscara, aparición, fragmentación, identidad, estratificación.",
    'VORTEX' => "Conceptos compatibles: giro, tensión, arrastre, fuerza, exterior e interior, movimiento psicológico, chorreado, incisiones iniciales.",
];

$users = $pdo->query('SELECT DISTINCT user_id FROM artworks')->fetchAll(PDO::FETCH_COLUMN);
foreach (array_map('intval', $users) as $userId) {
    echo "== usuario {$userId} ==\n";

    // 1) ADN por serie (solo si esta vacio: lo escrito por el artista manda).
    $seriesStmt = $pdo->prepare('SELECT id, title, COALESCE(title_dna, \'\') AS title_dna FROM artwork_series WHERE user_id=?');
    $seriesStmt->execute([$userId]);
    foreach ($seriesStmt->fetchAll(PDO::FETCH_ASSOC) as $series) {
        $key = mb_strtoupper(trim((string)$series['title']));
        if (!isset($seriesDna[$key]) || trim((string)$series['title_dna']) !== '') continue;
        echo "  ADN de títulos → serie {$series['title']}\n";
        if (!$dryRun) {
            $pdo->prepare('UPDATE artwork_series SET title_dna=?, updated_at=? WHERE id=? AND user_id=?')
                ->execute([$seriesDna[$key], date(DATE_ATOM), (int)$series['id'], $userId]);
        }
    }

    // 2) Registro con el catalogo vigente.
    $artStmt = $pdo->prepare("SELECT id, series_id, final_title FROM artworks WHERE user_id=? AND TRIM(COALESCE(final_title,''))<>''");
    $artStmt->execute([$userId]);
    foreach ($artStmt->fetchAll(PDO::FETCH_ASSOC) as $artwork) {
        $full = trim((string)$artwork['final_title']);
        // El titulo individual: lo que sigue al guion largo en el formato
        // SERIE + romano + — + TITULO; si no hay guion, el titulo completo.
        $individual = $full;
        // Formato SERIE + romano + separador + TITULO: acepta em-dash y el
        // guion corto historico (STRATA VIII - TRIA).
        if (preg_match('/(?:—|\s-\s)\s*(.+)$/u', $full, $m)) $individual = trim($m[1]);
        $meta = $catalogMeta[mb_strtoupper($individual)] ?? ['', '', ''];
        echo "  registro → obra {$artwork['id']} \"{$full}\" (raíz: " . ($meta[2] ?: '—') . ")\n";
        if (!$dryRun) {
            $service->registerCatalogTitle($userId, (int)$artwork['id'], (int)$artwork['series_id'], $full, $meta[0], $meta[1], $meta[2]);
        }
    }

    // 3) Mapa de colisiones — informa, nunca actua.
    if (!$dryRun) {
        $map = $service->collisionMap($userId);
        echo $map === [] ? "  sin colisiones semánticas\n" : "  MAPA DE COLISIONES (decisión del artista):\n";
        foreach ($map as $root => $titles) {
            echo "    raíz \"{$root}\": " . implode(' · ', $titles) . "\n";
        }
    }
}
echo "listo" . ($dryRun ? ' (dry-run, sin escribir)' : '') . "\n";
