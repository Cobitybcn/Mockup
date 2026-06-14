<?php
header('Content-Type: text/plain; charset=utf-8');

echo "--- PRUEBA DE WMIC Y ALTERNATIVAS ---\n\n";

// 1. Probar si wmic está en el path y funciona
$output = [];
$retval = -1;
exec('wmic process call create "cmd.exe /c echo test" 2>&1', $output, $retval);
echo "WMIC Test:\n";
echo "Retval: $retval\n";
echo "Output:\n" . implode("\n", $output) . "\n\n";

// 2. Probar si COM (WScript.Shell) está disponible
echo "WScript.Shell Test:\n";
if (class_exists('COM')) {
    try {
        $wsh = new COM("WScript.Shell");
        echo "COM (WScript.Shell) está disponible y se pudo instanciar.\n";
    } catch (Throwable $e) {
        echo "Error instanciando WScript.Shell: " . $e->getMessage() . "\n";
    }
} else {
    echo "Clase COM no existe en esta instalación de PHP.\n";
}
echo "\n";

// 3. Probar where php
$output2 = [];
$retval2 = -1;
exec('where php 2>&1', $output2, $retval2);
echo "Where PHP:\n";
echo "Retval: $retval2\n";
echo "Output:\n" . implode("\n", $output2) . "\n";
