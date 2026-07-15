<?php
$index = file("index.php");
$start = 0;
$end = 0;
foreach ($index as $i => $line) {
    if (strpos($line, "function admin_password_file") !== false) $start = $i;
    if (strpos($line, "function render_home") !== false) $end = $i - 1;
}

if ($start && $end) {
    $adminCode = "<?php\n\n" . implode("", array_slice($index, $start, $end - $start + 1));
    // Ajustar rutas relativas si es necesario
    $adminCode = str_replace("__DIR__ . \"/data", "__DIR__ . \"/../data", $adminCode);
    $adminCode = str_replace("__DIR__ . \"/assets", "__DIR__ . \"/../assets", $adminCode);
    file_put_contents("inc/admin.php", $adminCode);
    echo "inc/admin.php creado con éxito.\n";
} else {
    echo "No se encontraron los marcadores en index.php\n";
}
?>
