<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/ArtworkSyncDryRun.php';
header('Content-Type: application/json; charset=utf-8');
$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'local_only']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) { http_response_code(415); echo json_encode(['ok'=>false,'error'=>'application_json_required']); exit; }
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 2_000_000) { http_response_code(413); echo json_encode(['ok'=>false,'error'=>'payload_too_large']); exit; }
try {
    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new JsonException('JSON object required.');
    $source = getenv('ARTWORK_SYNC_SOURCE_DIR') ?: dirname(__DIR__, 3) . '/mockups/results';
    $draft = getenv('ARTWORK_SYNC_DRAFT_FILE') ?: dirname(__DIR__, 2) . '/data/drafts/artwork-sync-drafts.json';
    $result = (new ArtworkSyncDryRun($source, $draft))->process($payload);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_json']); }
catch (Throwable) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal_error']); }
