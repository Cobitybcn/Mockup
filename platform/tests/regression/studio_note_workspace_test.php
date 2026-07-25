<?php
declare(strict_types=1);

function run_studio_note_workspace_tests(): void
{
    TestHarness::group('Mesa editorial recuperable de Studio Notes');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE social_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,campaign_type TEXT NOT NULL,
        title TEXT NOT NULL,objective TEXT NOT NULL,source_type TEXT NOT NULL DEFAULT '',
        source_id TEXT NOT NULL DEFAULT '',source_label TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,
        payload_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
    )");
    $pdo->exec("INSERT INTO users VALUES (7,'artist@example.com')");
    $pdo->exec("INSERT INTO social_campaigns
        (id,user_id,campaign_type,title,objective,status,payload_json,created_at,updated_at)
        VALUES (17,7,'website_blog','Nota activa','','draft','{}','2026-07-25','2026-07-25')");

    $jobMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260724_000001_bilingual_editorial_jobs.php';
    $jobMigration['up']($pdo);
    $original = [
        'title' => 'Del sueño al territorio',
        'body_html' => '<p>Este es el texto original que debe permanecer recuperable.</p>',
    ];
    $result = [
        'spanish_content' => [
            'title' => 'Territorios en transformación',
            'body_html' => '<p>Propuesta editorial española.</p>',
        ],
        'english_content' => [
            'title' => 'Territories in Transformation',
            'body_html' => '<p>English editorial adaptation.</p>',
        ],
    ];
    $insertJob = $pdo->prepare("INSERT INTO bilingual_editorial_jobs
        (id,user_id,entity_type,entity_id,action,status,payload_json,result_json,error,task_name,attempts,created_at,updated_at)
        VALUES (183,7,'studio_note',17,'prepare','completed',?,?,'','',1,'2026-07-25T16:59:53Z','2026-07-25T17:01:12Z')");
    $insertJob->execute([
        json_encode(['current_spanish' => $original], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $workspaceMigration = require dirname(__DIR__, 2) . '/migrations/schema/20260725_000001_studio_note_workspace_items.php';
    $workspaceMigration['up']($pdo);
    $service = new StudioNoteWorkspaceService($pdo);
    $boards = $service->boards(7, 17);

    TestHarness::assertSame(
        'Del sueño al territorio',
        (string)$boards['version'][0]['content']['title'],
        'la migración recupera el texto anterior conservado por el trabajo automático'
    );
    TestHarness::assertSame(1, count($boards['proposal']), 'el Board muestra únicamente propuestas españolas');
    TestHarness::assertSame(
        'Territorios en transformación',
        (string)($boards['proposal'][0]['content']['title'] ?? ''),
        'la propuesta visible pertenece al flujo de trabajo español'
    );
    $proposalId = (int)$boards['proposal'][0]['id'];
    $service->remove(7, 17, $proposalId);
    $service->syncHistoricalJobs(7, 17);
    TestHarness::assertSame(
        0,
        count($service->boards(7, 17)['proposal']),
        'retirar una propuesta es persistente y la sincronización histórica no la recrea'
    );

    $ideaId = $service->addIdea(7, 17, 'Cruce de materiales', "Relacionar pigmento\ncon memoria");
    TestHarness::assertTrue((int)$ideaId > 0, 'la pizarra guarda ideas dentro del borrador');
    $idea = $service->item(7, 17, (int)$ideaId);
    TestHarness::assertContains('<br', (string)$idea['content']['body_html'], 'las ideas conservan saltos de línea legibles');
    $service->remove(7, 17, (int)$ideaId);
    TestHarness::assertSame(0, count($service->boards(7, 17)['idea']), 'una tarjeta se puede retirar sin alterar la nota activa');

    $adaptPayload = json_encode([
        'current_spanish' => [
            'title' => 'El recorrido de las series',
            'body_html' => '<p>Fuente española exacta.</p>',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $adaptResult = json_encode([
        'english_content' => [
            'title' => 'The Journey Through the Series',
            'body_html' => '<p>Exact English adaptation.</p>',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $insertAdaptJob = $pdo->prepare("INSERT INTO bilingual_editorial_jobs
        (id,user_id,entity_type,entity_id,action,status,payload_json,result_json,error,task_name,attempts,created_at,updated_at)
        VALUES (184,7,'studio_note',17,'adapt','completed',?,?,'','',1,'2026-07-25T18:20:00Z','2026-07-25T18:21:00Z')");
    $insertAdaptJob->execute([$adaptPayload, $adaptResult]);
    $service->syncHistoricalJobs(7, 17);
    TestHarness::assertSame(
        0,
        count($service->boards(7, 17)['proposal']),
        'las adaptaciones inglesas no aparecen en el Board español'
    );

    $worker = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/BilingualEditorialGenerationWorker.php');
    TestHarness::assertContains("'proposal_only' => true", $worker, 'el worker marca Studio Notes como propuesta sin guardado automático');
    TestHarness::assertContains('completeStudioNoteMetadata', $worker, 'la flecha completa primero los metadatos españoles desde el análisis editorial');
    TestHarness::assertContains("'metadata_completed' => true", $worker, 'la adaptación registra que el paquete SEO español fue completado');
    TestHarness::assertContains('syncHistoricalJobs', $worker, 'al completar el trabajo el resultado se incorpora a la mesa editorial');
    TestHarness::assertContains("'applied_to_editor' => true", $worker, 'la adaptación explícita actualiza el editor inglés al terminar');
}
