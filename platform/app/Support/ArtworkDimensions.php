<?php
declare(strict_types=1);

/**
 * Medidas fisicas de una obra: unica puerta de escritura y de formato.
 *
 * Se escriben en la tabla artworks, que es lo que leen la ficha, la seccion
 * Publicacion y el sitio publico. El .meta.json del disco es una foto del
 * momento de la generacion y no se reescribe: no es fuente.
 */
class ArtworkDimensions
{
    /** Tope defensivo: una obra fisica no mide 20 metros. */
    public const MAX_CM = 2000.0;

    /**
     * Interpreta lo que el artista escribio en el encabezado.
     * Acepta "120 × 80 × 3", "120x80", "120 X 80 x 3 cm", comas decimales.
     * Vaciar el campo borra las medidas.
     *
     * @return array{width:string,height:string,depth:string}
     */
    public static function parse(string $raw): array
    {
        $normalized = str_replace([',', '×', 'X'], ['.', 'x', 'x'], trim($raw));
        $normalized = (string)preg_replace('/\s*(?:cm|centimetros|centímetros)\s*$/iu', '', $normalized);

        if (trim($normalized) === '') {
            return ['width' => '', 'height' => '', 'depth' => ''];
        }

        $parts = array_values(array_filter(
            array_map('trim', explode('x', $normalized)),
            static fn (string $part): bool => $part !== ''
        ));

        if (count($parts) < 2 || count($parts) > 3) {
            throw new InvalidArgumentException('Escribí ancho × alto, y opcionalmente la profundidad. Por ejemplo: 120 × 80 × 3');
        }

        $values = [];
        foreach ($parts as $part) {
            if (!preg_match('/^\d+(?:\.\d+)?$/', $part)) {
                throw new InvalidArgumentException('Las medidas tienen que ser números. Por ejemplo: 120 × 80 × 3');
            }
            $value = (float)$part;
            if ($value <= 0) {
                throw new InvalidArgumentException('Las medidas tienen que ser mayores que cero.');
            }
            if ($value > self::MAX_CM) {
                throw new InvalidArgumentException('Esa medida supera los ' . (int)self::MAX_CM . ' cm. Revisá si te sobra un dígito.');
            }
            $values[] = self::format((string)$part);
        }

        return [
            'width' => $values[0],
            'height' => $values[1],
            'depth' => $values[2] ?? '',
        ];
    }

    /** '120' y '120.00' se ven igual; rtrim a secas convertiria '120' en '12'. */
    public static function format(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    /**
     * Texto del encabezado. Sin unidad a proposito: el alta fija cm y repetirlo
     * ensucia la linea del titulo.
     */
    public static function headerText(string $width, string $height, string $depth): string
    {
        if (trim($width) === '' || trim($height) === '') {
            return '';
        }

        $text = self::format($width) . ' × ' . self::format($height);

        return trim($depth) !== '' ? $text . ' × ' . self::format($depth) : $text;
    }

    /**
     * @return array{width:string,height:string,depth:string,text:string}
     */
    public static function save(PDO $pdo, int $artworkId, int $userId, string $raw): array
    {
        $parsed = self::parse($raw);

        $statement = $pdo->prepare('
            UPDATE artworks
            SET width = :width,
                height = :height,
                depth = :depth,
                unit = :unit,
                updated_at = :updated_at
            WHERE id = :id AND user_id = :user_id
        ');
        $statement->execute([
            'width' => $parsed['width'],
            'height' => $parsed['height'],
            'depth' => $parsed['depth'],
            'unit' => 'cm',
            'updated_at' => date('c'),
            'id' => $artworkId,
            'user_id' => $userId,
        ]);

        if ($statement->rowCount() === 0) {
            $owned = $pdo->prepare('SELECT 1 FROM artworks WHERE id = ? AND user_id = ? LIMIT 1');
            $owned->execute([$artworkId, $userId]);
            if (!$owned->fetchColumn()) {
                throw new RuntimeException('La obra no existe o no es tuya.');
            }
        }

        return $parsed + ['text' => self::headerText($parsed['width'], $parsed['height'], $parsed['depth'])];
    }
}
