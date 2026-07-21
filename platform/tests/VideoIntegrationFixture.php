<?php
declare(strict_types=1);

function createVideoIntegrationFixture(PDO $pdo): array
{
    $suffix = bin2hex(random_bytes(8));
    $now = date('c');
    $user = $pdo->prepare('INSERT INTO users (email,password_hash,name,credits,is_admin,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
    $user->execute([
        "video-integration-{$suffix}@example.test",
        password_hash($suffix, PASSWORD_DEFAULT),
        'Video Integration Fixture',
        10,
        0,
        $now,
        $now,
    ]);
    $userId = (int)$pdo->lastInsertId();

    $fileName = "video-integration-{$suffix}.png";
    $filePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $fileName;
    if (!is_dir(RESULTS_DIR)) mkdir(RESULTS_DIR, 0775, true);
    file_put_contents($filePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

    $artwork = $pdo->prepare('INSERT INTO artworks
        (user_id,job_id,root_file,main_file,final_title,status,width,height,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $artwork->execute([$userId, 'video-integration-' . $suffix, $fileName, $fileName, 'Video Integration Artwork', 'done', '1', '1', $now, $now]);

    return ['user_id' => $userId, 'artwork_id' => (int)$pdo->lastInsertId(), 'file_path' => $filePath];
}

function removeVideoIntegrationFixture(PDO $pdo, array $fixture): void
{
    $userId = (int)($fixture['user_id'] ?? 0);
    if ($userId > 0) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    $filePath = (string)($fixture['file_path'] ?? '');
    if ($filePath !== '' && is_file($filePath)) unlink($filePath);
}
