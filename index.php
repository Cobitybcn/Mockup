<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: root_album.php');
} else {
    header('Location: login.php');
}

exit;
