<?php
declare(strict_types=1);

function run_studio_note_markdown_import_tests(): void
{
    TestHarness::group('Studio Notes: importación Markdown bilingüe sin IA');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE artwork_groups (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,canonical_artwork_id INTEGER NOT NULL,
        title TEXT NOT NULL,status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artwork_series (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,title TEXT NOT NULL,header_file TEXT NOT NULL,
        status TEXT NOT NULL,year_start INTEGER,year_end INTEGER,created_at TEXT NOT NULL,published INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE artworks (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,artwork_group_id INTEGER,status TEXT NOT NULL,
        final_title TEXT NOT NULL,root_file TEXT NOT NULL,main_file TEXT NOT NULL,
        series_id INTEGER,series TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE artwork_sheets (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,canonical_artwork_id INTEGER NOT NULL,
        status TEXT NOT NULL,title TEXT NOT NULL,source_image_file TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE mockups (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,mockup_file TEXT NOT NULL,context_id TEXT NOT NULL,
        source_artwork_id INTEGER,series_id INTEGER,selector_state_json TEXT NOT NULL,created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE mockup_sheets (
        id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,mockup_file TEXT NOT NULL,title TEXT NOT NULL,
        description TEXT NOT NULL,caption TEXT NOT NULL,alt_text TEXT NOT NULL,keywords TEXT NOT NULL,
        generated_json TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE bilingual_editorial_content (
        user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,locale TEXT NOT NULL,
        content_json TEXT NOT NULL,private_memo TEXT NOT NULL,status TEXT NOT NULL,source_hash TEXT NOT NULL,
        is_published INTEGER NOT NULL DEFAULT 0,published_content_json TEXT,published_at TEXT,
        created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
        UNIQUE(user_id,entity_type,entity_id,locale)
    )");
    $pdo->exec("CREATE TABLE bilingual_editorial_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,action TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE user_language_policy (
        user_id INTEGER NOT NULL PRIMARY KEY,working_locale TEXT NOT NULL,publication_locales_json TEXT NOT NULL,
        interface_locale TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL
    )");
    // Este artista trabaja en espanol y publica en espanol+ingles (como Maurizio);
    // sin una politica explicita el default ahora es ingles.
    $pdo->exec("INSERT INTO user_language_policy (user_id,working_locale,publication_locales_json,created_at,updated_at)
        VALUES (70,'es','[\"es\",\"en\"]','2026-07-01T10:00:00Z','2026-07-01T10:00:00Z')");
    $pdo->exec("INSERT INTO artwork_groups VALUES
        (10,70,101,'STRATA IV','active','2026-07-01T10:00:00Z','2026-07-01T10:00:00Z')");
    $pdo->exec("INSERT INTO artwork_series VALUES
        (7,70,'STRATA','series.jpg','active',2025,2026,'2026-06-01T10:00:00Z',1)");
    $pdo->exec("INSERT INTO artworks VALUES
        (101,70,10,'done','STRATA IV','strata-root.jpg','',7,'STRATA')");
    $pdo->exec("INSERT INTO artwork_sheets VALUES
        (201,70,101,'validated','STRATA IV','strata-root.jpg')");
    $pdo->exec("INSERT INTO mockups VALUES
        (2946,70,'strata-architectural.jpg','quiet_architecture',101,7,'{}','2026-07-20T10:00:00Z')");
    $pdo->exec("INSERT INTO mockup_sheets VALUES
        (301,70,'strata-architectural.jpg','STRATA in Architecture','Context description',
         'Existing caption','Existing alt','architecture, strata','{}')");

    $markdown = <<<'MD'
---
schema: "studio-note/v1"
content_type: "essay"
source_language: "es"
created_at: "2026-07-26T14:30:00-03:00"
status: "draft"
cover_image: "strata-architectural-view"
series:
  - id: 7
    title: "STRATA"
artworks:
  - id: 101
    title: "STRATA IV"
mockups:
  - id: 2946
es:
  title: "La piel del territorio"
  excerpt: "Memoria, incisión y materia en la serie STRATA."
  slug: "la-piel-del-territorio"
  seo_title: "La piel del territorio y la serie STRATA"
  meta_description: "Un ensayo sobre memoria, incisión y territorio en la serie STRATA de Maurizio Valch."
  tags:
    - "pintura abstracta"
    - "STRATA"
  search_terms:
    - "pintura abstracta contemporánea"
    - "serie STRATA Maurizio Valch"
  cover_alt: "Obra roja de la serie STRATA en un espacio arquitectónico."
  cover_caption: "Obra de STRATA en relación con un espacio arquitectónico."
en:
  title: "The Skin of Territory"
  excerpt: "Memory, incision, and matter in the STRATA series."
  slug: "the-skin-of-territory"
  seo_title: "The Skin of Territory and the STRATA Series"
  meta_description: "An essay on memory, incision, and territory in Maurizio Valch's STRATA series."
  tags:
    - "abstract painting"
    - "STRATA"
  search_terms:
    - "contemporary abstract painting"
    - "Maurizio Valch STRATA series"
  cover_alt: "Red artwork from the STRATA series in an architectural space."
  cover_caption: "A STRATA work in relation to an architectural setting."
images:
  - key: "strata-architectural-view"
    mockup_id: 2946
    file: "strata-architectural.jpg"
    role: "cover"
    size: "medium"
    align: "left"
    es:
      alt_text: "Obra roja de STRATA situada en un espacio arquitectónico."
      caption: "La superficie pictórica aparece como piel y archivo."
    en:
      alt_text: "Red STRATA artwork displayed in an architectural space."
      caption: "The painted surface appears as skin and archive."
---

<!-- STUDIO_NOTE:ES -->

## Memoria, incisión y materia

En la serie **STRATA**, el territorio deja de presentarse como un escenario distante.

{{image:strata-architectural-view}}

La superficie conserva lo que la ha atravesado. [Consultar la serie](https://example.com/strata).

<script>alert("unsafe")</script>

<!-- STUDIO_NOTE:EN -->

## Memory, Incision, and Matter

In the **STRATA** series, territory ceases to appear as a distant stage.

{{image:strata-architectural-view}}

The surface preserves what has passed through it. [View the series](https://example.com/strata).

<script>alert("unsafe")</script>
MD;

    $service = new StudioNoteMarkdownImportService($pdo);
    $withoutCreatedAt = str_replace(
        'created_at: "2026-07-26T14:30:00-03:00"' . "\n",
        '',
        $markdown
    );
    $defaultedPackage = $service->parse($withoutCreatedAt);
    TestHarness::assertTrue(
        trim((string)$defaultedPackage['manifest']['created_at']) !== '',
        'created_at ausente se completa con la fecha de importación'
    );
    $withoutInternalIds = str_replace(
        [
            "series:\n  - id: 7\n    title: \"STRATA\"",
            "artworks:\n  - id: 101\n    title: \"STRATA IV\"",
            "mockups:\n  - id: 2946",
        ],
        [
            "series:\n  - title: \"STRATA\"",
            "artworks:\n  - \"STRATA IV\"",
            "mockups:\n  - file: \"strata-architectural.jpg\"",
        ],
        $withoutCreatedAt
    );
    $externalPackage = $service->parse($withoutInternalIds);
    TestHarness::assertSame(0, (int)$externalPackage['manifest']['series'][0]['id'], 'una serie externa puede declararse por nombre');
    TestHarness::assertSame('STRATA IV', (string)$externalPackage['manifest']['artworks'][0]['title'], 'una obra externa puede declararse como texto');
    TestHarness::assertSame(
        'strata-architectural.jpg',
        (string)$externalPackage['manifest']['mockups'][0]['file'],
        'un mockup externo puede declararse por archivo'
    );
    $externalImport = $service->importString(70, $withoutInternalIds, 'sin-ids.md');
    TestHarness::assertTrue((int)$externalImport['id'] > 0, 'las relaciones sin IDs internos no bloquean la importación');

    $imported = $service->importString(70, $markdown, 'la-piel-del-territorio.md');
    TestHarness::assertTrue((int)$imported['id'] > 0, 'la importación crea un borrador nuevo');
    TestHarness::assertTrue($imported['no_ai'] === true, 'el resultado declara explícitamente que no ejecutó IA');

    $campaignStmt = $pdo->prepare('SELECT * FROM social_campaigns WHERE id=?');
    $campaignStmt->execute([(int)$imported['id']]);
    $campaign = $campaignStmt->fetch(PDO::FETCH_ASSOC);
    TestHarness::assertSame('draft', (string)$campaign['status'], 'el archivo nunca publica automáticamente');
    TestHarness::assertSame(
        '2026-07-26T14:30:00-03:00',
        (string)$campaign['created_at'],
        'la fecha original gobierna el orden editorial'
    );
    $payload = json_decode((string)$campaign['payload_json'], true);
    TestHarness::assertSame([2946], (array)$payload['mockup_ids'], 'el mockup importado queda relacionado con la nota');
    TestHarness::assertSame(
        'studio-note/v1',
        (string)$payload['markdown_import']['schema'],
        'el borrador conserva la procedencia del contrato'
    );

    $localized = [];
    $localizedStmt = $pdo->prepare(
        "SELECT locale,content_json,status
         FROM bilingual_editorial_content
         WHERE entity_type='studio_note' AND entity_id=?
         ORDER BY locale"
    );
    $localizedStmt->execute([(int)$imported['id']]);
    foreach ($localizedStmt as $row) {
        $localized[(string)$row['locale']] = [
            'content' => json_decode((string)$row['content_json'], true),
            'status' => (string)$row['status'],
        ];
    }
    TestHarness::assertSame('source', $localized['es']['status'], 'el español queda registrado como fuente');
    TestHarness::assertSame('current', $localized['en']['status'], 'el inglés completo queda sincronizado sin adaptación');
    TestHarness::assertContains('<strong>STRATA</strong>', (string)$localized['es']['content']['body_html'], 'Markdown se convierte a HTML editorial');
    TestHarness::assertContains('data-editor-align="left"', (string)$localized['es']['content']['body_html'], 'la alineación declarada llega al WYSIWYG');
    TestHarness::assertContains('studio_note_media.php?note=', (string)$localized['es']['content']['body_html'], 'la imagen usa la URL persistente de la nota');
    TestHarness::assertTrue(
        !str_contains((string)$localized['es']['content']['body_html'], '<script'),
        'el HTML inseguro del Markdown no entra al editor'
    );
    TestHarness::assertSame(
        'Obra roja de STRATA situada en un espacio arquitectónico.',
        (string)$localized['es']['content']['image_metadata'][0]['alt_text'],
        'el SEO español de la imagen queda asociado'
    );
    TestHarness::assertSame(
        'Red STRATA artwork displayed in an architectural space.',
        (string)$localized['en']['content']['image_metadata'][0]['alt_text'],
        'el SEO inglés de la imagen queda asociado'
    );
    $classification = StudioNoteChangeClassifier::classify(
        (array)$localized['es']['content'],
        [],
        (array)$localized['en']['content'],
        [],
        false,
        false
    );
    TestHarness::assertSame('publish_changes', $classification['primary_action'], 'el paquete completo ofrece PUBLICAR directamente');
    TestHarness::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM bilingual_editorial_jobs')->fetchColumn(), 'importar no crea trabajos de Vertex');

    $packagedMarkdown = str_replace(
        "    mockup_id: 2946\n    file: \"strata-architectural.jpg\"",
        "    mockup_id: 0\n    file: \"strata-original.png\"",
        $markdown
    );
    $pngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    $packaged = $service->importString(
        70,
        $packagedMarkdown,
        'nota.md',
        ['strata-original.png' => ['bytes' => (string)$pngBytes, 'mime' => 'image/png']],
        'nota-completa.zip'
    );
    $packageCampaignStmt = $pdo->prepare('SELECT payload_json FROM social_campaigns WHERE id=?');
    $packageCampaignStmt->execute([(int)$packaged['id']]);
    $packagePayload = json_decode((string)$packageCampaignStmt->fetchColumn(), true);
    $packageMedia = (array)($packagePayload['media'][0] ?? []);
    TestHarness::assertSame('studio_note', (string)($packageMedia['type'] ?? ''), 'una imagen propia del ZIP se persiste como medio editorial');
    TestHarness::assertContains('studio-note-70-', (string)($packageMedia['file'] ?? ''), 'la imagen del ZIP recibe un nombre persistente seguro');
    TestHarness::assertSame('nota-completa.zip', (string)$packagePayload['markdown_import']['package'], 'el borrador conserva la procedencia del ZIP');
    TestHarness::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM bilingual_editorial_jobs')->fetchColumn(), 'el ZIP con imágenes tampoco crea trabajos de Vertex');

    if (class_exists(ZipArchive::class)) {
        $zipPath = tempnam(sys_get_temp_dir(), 'studio-note-import-');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('editorial/nota.md', $markdown);
        $zip->close();
        $zipImported = $service->importUpload(70, [
            'name' => 'nota-bilingue.zip',
            'tmp_name' => $zipPath,
            'size' => (int)filesize($zipPath),
            'error' => UPLOAD_ERR_OK,
        ]);
        TestHarness::assertTrue((int)$zipImported['id'] > 0, 'el lector abre un ZIP real con un único Markdown');
        TestHarness::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM bilingual_editorial_jobs')->fetchColumn(), 'leer el ZIP real no ejecuta Vertex');
        @unlink($zipPath);
    }

    $invalid = str_replace(
        '{{image:strata-architectural-view}}' . "\n\nThe surface",
        'The surface',
        $markdown
    );
    $rejected = false;
    try {
        $service->parse($invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    TestHarness::assertTrue($rejected, 'un archivo con imágenes desalineadas entre idiomas se rechaza completo');

    $page = (string)file_get_contents(dirname(__DIR__, 2) . '/website_studio_notes.php');
    TestHarness::assertContains('value="import_markdown"', $page, 'el tablero ofrece la acción de importar');
    TestHarness::assertContains('accept=".zip,application/zip,application/x-zip-compressed"', $page, 'el selector visible se limita al paquete ZIP');
    TestHarness::assertContains('ZIP bilingüe importado como borrador, sin ejecutar IA.', $page, 'la interfaz confirma el flujo ZIP sin Vertex');

    $persistedFile = basename((string)($packageMedia['file'] ?? ''));
    if ($persistedFile !== '') {
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $persistedFile;
        if (is_file($path)) @unlink($path);
    }
}
