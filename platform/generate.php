<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

http_response_code(410);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Flujo legacy desactivado</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background:#f3f3f0; color:#111; padding:40px;">
    <main style="max-width:760px; margin:0 auto; background:#fff; border:1px solid #ddd; padding:28px;">
        <h1>Flujo legacy desactivado</h1>
        <p>
            Este archivo pertenecia a la version sincronica con llamadas reales a API.
            En <strong>APP_MODE=<?= htmlspecialchars(ServiceFactory::appMode(), ENT_QUOTES, 'UTF-8') ?></strong>
            no debe generar imagenes ni consumir creditos.
        </p>
        <p>
            Usa <a href="create_scenes.php">create_scenes.php</a> para probar el flujo local.
        </p>
    </main>
</body>
</html>
