<?php
declare(strict_types=1);

/**
 * Arnes minimo de tests de no-regresion (Fase 3 de la auditoria de prompts).
 * No usa PHPUnit ni composer porque el proyecto no tiene esa infraestructura;
 * sigue la misma convencion de scripts PHP standalone que el resto del repo.
 */
final class TestHarness
{
    private static int $pass = 0;
    private static int $fail = 0;
    private static array $failures = [];
    private static string $currentGroup = '';

    public static function group(string $name): void
    {
        self::$currentGroup = $name;
        echo "\n=== {$name} ===\n";
    }

    public static function assertTrue(bool $condition, string $label): void
    {
        if ($condition) {
            self::pass($label);
        } else {
            self::fail($label, 'esperaba true, obtuvo false');
        }
    }

    public static function assertSame($expected, $actual, string $label): void
    {
        if ($expected === $actual) {
            self::pass($label);
        } else {
            self::fail($label, 'esperaba ' . var_export($expected, true) . ', obtuvo ' . var_export($actual, true));
        }
    }

    public static function assertContains(string $needle, string $haystack, string $label): void
    {
        if (str_contains($haystack, $needle)) {
            self::pass($label);
        } else {
            self::fail($label, 'no se encontro la subcadena esperada: ' . substr($needle, 0, 120));
        }
    }

    public static function assertNotEmpty(string $value, string $label): void
    {
        if (trim($value) !== '') {
            self::pass($label);
        } else {
            self::fail($label, 'la cadena esta vacia');
        }
    }

    private static function pass(string $label): void
    {
        self::$pass++;
        echo "  [PASS] {$label}\n";
    }

    private static function fail(string $label, string $reason): void
    {
        self::$fail++;
        self::$failures[] = self::$currentGroup . ' :: ' . $label . ' -- ' . $reason;
        echo "  [FAIL] {$label} -- {$reason}\n";
    }

    /**
     * Compara $data contra un snapshot JSON guardado en tests/fixtures/.
     * Si el snapshot no existe, lo crea (primera corrida = linea base) y pasa.
     * Si existe, compara byte a byte (JSON canonico) y falla mostrando el diff
     * de las claves que cambiaron.
     */
    public static function snapshot(string $fixtureName, array $data, string $label): void
    {
        $fixturesDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures';
        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0775, true);
        }
        $path = $fixturesDir . DIRECTORY_SEPARATOR . $fixtureName;
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_file($path)) {
            file_put_contents($path, $encoded);
            self::pass($label . ' (snapshot creado como linea base: ' . $fixtureName . ')');
            return;
        }

        $golden = (string)file_get_contents($path);
        if ($golden === $encoded) {
            self::pass($label . ' (coincide con snapshot ' . $fixtureName . ')');
            return;
        }

        $goldenData = json_decode($golden, true) ?? [];
        $diffKeys = self::diffKeys($goldenData, $data);
        self::fail(
            $label,
            'el snapshot ' . $fixtureName . ' cambio. Claves con diferencias: ' . implode(', ', array_slice($diffKeys, 0, 10))
                . '. Si el cambio es intencional, borrar tests/fixtures/' . $fixtureName . ' para regenerar la linea base.'
        );
    }

    private static function diffKeys(array $a, array $b, string $prefix = ''): array
    {
        $diffs = [];
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $key) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $av = $a[$key] ?? null;
            $bv = $b[$key] ?? null;
            if (is_array($av) && is_array($bv)) {
                $diffs = array_merge($diffs, self::diffKeys($av, $bv, $path));
            } elseif ($av !== $bv) {
                $diffs[] = $path;
            }
        }
        return $diffs;
    }

    public static function summary(): int
    {
        echo "\n=== RESUMEN ===\n";
        echo 'PASS: ' . self::$pass . ' | FAIL: ' . self::$fail . "\n";
        if (self::$failures) {
            echo "\nFallas:\n";
            foreach (self::$failures as $f) {
                echo " - {$f}\n";
            }
        }
        return self::$fail > 0 ? 1 : 0;
    }
}
