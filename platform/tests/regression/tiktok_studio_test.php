<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/TikTokBoardService.php';
require_once __DIR__ . '/../../app/Services/TikTokPublishScheduler.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE video_projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE video_exports (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, video_project_id INTEGER NOT NULL, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE tiktok_boards (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, publish_date TEXT NOT NULL, title TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(user_id,publish_date))');
$pdo->exec('CREATE TABLE tiktok_board_items (id INTEGER PRIMARY KEY AUTOINCREMENT, board_id INTEGER NOT NULL, user_id INTEGER NOT NULL, video_export_id INTEGER NOT NULL, position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, UNIQUE(user_id,video_export_id))');

$pdo->exec('INSERT INTO users (id) VALUES (7)');
$pdo->exec('INSERT INTO video_projects (id,user_id) VALUES (1,7)');
$pdo->exec("INSERT INTO video_exports (id,user_id,video_project_id,status) VALUES (101,7,1,'succeeded')");
$pdo->exec("INSERT INTO video_exports (id,user_id,video_project_id,status) VALUES (102,7,1,'succeeded')");
$pdo->exec("INSERT INTO video_exports (id,user_id,video_project_id,status) VALUES (103,999,1,'succeeded')"); // belongs to a different user

$boards = new TikTokBoardService($pdo);

// Assigning an unowned video export must fail closed.
try {
    $boards->assignVideo(7, 103, '2026-08-01');
    fwrite(STDERR, "FAIL: assignVideo accepted a video export owned by another user.\n");
    exit(1);
} catch (RuntimeException) {
    // expected
}

$board = $boards->assignVideo(7, 101, '2026-08-01', 'Lanzamiento serie X');
if ($board['publish_date'] !== '2026-08-01' || $board['title'] !== 'Lanzamiento serie X') {
    fwrite(STDERR, "FAIL: assignVideo did not create the board with the expected date/title.\n");
    exit(1);
}
$boards->assignVideo(7, 102, '2026-08-01');
$sameBoard = $boards->boardIdsByVideo(7);
if (($sameBoard[101] ?? 0) !== ($sameBoard[102] ?? -1) || (int)$sameBoard[101] !== (int)$board['id']) {
    fwrite(STDERR, "FAIL: two videos assigned to the same date did not share one board.\n");
    exit(1);
}

$list = $boards->boardsForUser(7);
if (count($list) !== 1 || count($list[0]['video_export_ids']) !== 2) {
    fwrite(STDERR, "FAIL: boardsForUser did not group both videos under the single date board.\n");
    exit(1);
}

// Reassigning a video to a different date must move it, not duplicate it.
$secondBoard = $boards->assignVideo(7, 101, '2026-08-05');
if ((int)$secondBoard['id'] === (int)$board['id']) {
    fwrite(STDERR, "FAIL: reassigning a video to a new date reused the old board.\n");
    exit(1);
}
$afterMove = $boards->boardsForUser(7);
$dateForVideo101 = null;
foreach ($afterMove as $b) {
    if (in_array(101, $b['video_export_ids'], true)) $dateForVideo101 = $b['publish_date'];
}
if ($dateForVideo101 !== '2026-08-05') {
    fwrite(STDERR, "FAIL: reassigning a video left it on its original board.\n");
    exit(1);
}
$originalBoardAfterMove = array_values(array_filter($afterMove, static fn (array $b): bool => (int)$b['id'] === (int)$board['id']))[0];
if (in_array(101, $originalBoardAfterMove['video_export_ids'], true)) {
    fwrite(STDERR, "FAIL: reassigning a video did not remove it from its previous board.\n");
    exit(1);
}

$boards->unassignVideo(7, 102);
$afterUnassign = $boards->boardIdsByVideo(7);
if (isset($afterUnassign[102])) {
    fwrite(STDERR, "FAIL: unassignVideo did not remove the board membership.\n");
    exit(1);
}

// TikTokPublishScheduler::scheduledAt() date validation (pure logic, no Cloud Tasks dependency).
$schedulerReflection = new ReflectionClass(TikTokPublishScheduler::class);
$scheduler = $schedulerReflection->newInstanceWithoutConstructor();

try {
    $scheduler->scheduledAt(date('Y-m-d', strtotime('-1 day')), '12:00', 'UTC');
    fwrite(STDERR, "FAIL: scheduledAt accepted a date in the past.\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

try {
    $scheduler->scheduledAt(date('Y-m-d', strtotime('+40 days')), '12:00', 'UTC');
    fwrite(STDERR, "FAIL: scheduledAt accepted a date more than 30 days ahead.\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

$valid = $scheduler->scheduledAt(date('Y-m-d', strtotime('+2 days')), '15:30', 'UTC');
if (!$valid instanceof DateTimeImmutable || $valid->format('H:i') !== '15:30') {
    fwrite(STDERR, "FAIL: scheduledAt did not accept a valid near-future date/time.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: TikTok Studio board assignment and schedule-date validation.\n");
