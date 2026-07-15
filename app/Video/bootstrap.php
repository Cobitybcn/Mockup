<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/VideoStudioSchema.php';
require_once __DIR__ . '/VideoStudioRepository.php';
require_once __DIR__ . '/VideoStudioService.php';
require_once __DIR__ . '/VideoHttp.php';
require_once __DIR__ . '/VideoGenerationProvider.php';
require_once __DIR__ . '/VideoPromptComposer.php';
require_once __DIR__ . '/VertexVeoProvider.php';
require_once __DIR__ . '/VideoJobRepository.php';
require_once __DIR__ . '/VideoTaskDispatcher.php';
require_once __DIR__ . '/VideoFfmpeg.php';
require_once __DIR__ . '/VideoMediaStorage.php';
require_once __DIR__ . '/VideoGenerationService.php';
require_once __DIR__ . '/VideoExportBuilder.php';
require_once __DIR__ . '/VideoExportService.php';

VideoStudioSchema::migrate(Database::connection());
