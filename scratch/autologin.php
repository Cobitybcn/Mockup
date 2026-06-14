<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
Auth::start();
$_SESSION['user_id'] = 2;
header('Location: ../form2.php?image=main_artwork.jpg');
exit;
