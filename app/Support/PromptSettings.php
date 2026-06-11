<?php
declare(strict_types=1);

class PromptSettings
{
    public static function all(): array
    {
        $settings = self::defaultDirectives();
        $stmt = Database::connection()->query('SELECT key, value FROM app_settings');

        foreach ($stmt->fetchAll() as $row) {
            $key = (string)($row['key'] ?? '');

            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string)($row['value'] ?? '');
            }
        }

        return $settings;
    }

    public static function save(array $input): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            INSERT INTO app_settings (key, value, updated_at)
            VALUES (:key, :value, :updated_at)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = excluded.updated_at
        ');
        $now = date('c');

        foreach (array_keys(self::defaults()) as $key) {
            $value = trim((string)($input[$key] ?? self::defaultDirectives()[$key] ?? ''));

            if ($key === 'mockup_context_count') {
                $value = (string)self::normalizeContextCount($value);
            }

            $stmt->execute([
                'key' => $key,
                'value' => $value,
                'updated_at' => $now,
            ]);
        }
    }

    public static function rootArtworkRules(): string
    {
        return trim(self::all()['root_artwork_rules'] ?? '');
    }

    public static function mockupScaleRules(): string
    {
        return trim(self::all()['mockup_scale_rules'] ?? '');
    }

    public static function mockupNegativeRules(): string
    {
        return trim(self::all()['mockup_negative_rules'] ?? '');
    }

    public static function mockupQualityRules(): string
    {
        return trim(self::all()['mockup_quality_rules'] ?? '');
    }

    public static function mockupContextCount(): int
    {
        return self::normalizeContextCount(self::all()['mockup_context_count'] ?? '10');
    }

    public static function defaults(): array
    {
        return [
            'root_artwork_rules' => '',
            'mockup_scale_rules' => '',
            'mockup_negative_rules' => '',
            'mockup_quality_rules' => '',
            'mockup_context_count' => '10',
        ];
    }

    public static function labels(): array
    {
        return [
            'root_artwork_rules' => [
                'title' => 'Imagen raiz',
                'help' => 'Reglas adicionales para Formulario 1. Usar para ajustar fidelidad, luz, bastidor y preservacion.',
            ],
            'mockup_scale_rules' => [
                'title' => 'Escala y proporciones',
                'help' => 'Reglas adicionales para controlar tamaño real de obra, figura humana, muebles y arquitectura.',
            ],
            'mockup_negative_rules' => [
                'title' => 'Prohibiciones visuales',
                'help' => 'Elementos que nunca deben aparecer: textos, medidas, logos, objetos indeseados, etc.',
            ],
            'mockup_quality_rules' => [
                'title' => 'Calidad y atmosfera',
                'help' => 'Reglas finales para integracion, sombras, contexto, sofisticacion y estilo comercial.',
            ],
            'mockup_context_count' => [
                'title' => 'Cantidad de propuestas',
                'help' => 'Numero de direcciones curatoriales sugeridas en Formulario 2. Recomendado: 5, 7 o 10.',
                'type' => 'number',
            ],
        ];
    }

    public static function builtInDirectives(): array
    {
        return self::defaultDirectives();
    }

    public static function defaultDirectives(): array
    {
        return [
            'root_artwork_rules' => <<<'TEXT'
Crea una foto de lujo de primer plano frontal con esta pintura adjunta.
La obra esta apoyada en el suelo y contra la pared.
La pintura esta perfectamente tensada sobre un bastidor de madera.
La obra esta totalmente visible.
Ilumina el producto con luz de estudio compuesta: luz suave de relleno de ambos lados y destellos direccionales, con separacion tonal tipo HDR y bordes impecables.
Sin logotipos, textos ni marcas visibles.
Todo el producto debe estar nitido, sin desenfoque de fondo, con detalles de incisiones, texturas, pinceladas, espatula y bloques.
Respeta la obra original: no redibujes, no cambies composicion, no cambies colores artisticamente, no modifiques la vibracion del trazo ni las texturas del artista.
Las medidas reales pertenecen solo a la obra, no a la foto ni al fondo.
TEXT,
            'mockup_scale_rules' => <<<'TEXT'
Respetar las medidas reales de la obra frente a muebles, puertas, ventanas, cielorraso, pedestales y figuras humanas.
No agrandar la obra para dramatizar.
Si hay figura humana, debe estar parada en el mismo plano de suelo y profundidad (distancia a la cámara) exactamente igual a la de la obra, manteniéndose inmediatamente al lado de ella (a menos de 1 metro de distancia lateral).
Nunca colocar las figuras humanas en el fondo o en planos de profundidad alejados de la obra, ya que esto distorsiona visualmente la proporción real y hace que la pintura parezca gigantesca.
Para una obra de proporciones medianas-grandes (ej. 160 x 120 cm) al lado de una persona (ej. mujer de 1.55 m), la altura de la obra (120 cm) debe ser visiblemente menor que la altura de la persona, llegando aproximadamente al nivel de sus hombros o barbilla si está colgada a la altura de los ojos, y el ancho de la obra (160 cm) debe ser solo un poco mayor que la altura de la persona, nunca viéndose como un panel monumental que domine la pared de piso a techo.
Usar zócalo, puerta, consola, silla y altura humana como referencias de escala realistas y proporcionales en el mismo plano.
No convertir una obra mediana en mural, panel monumental o instalación si sus medidas no lo justifican.
TEXT,
            'mockup_negative_rules' => <<<'TEXT'
No cocinas.
No dormitorios comunes.
No decoracion barata.
No interiores genericos de stock.
No logos.
No texto visible.
No letras, numeros, placas, etiquetas, carteles, medidas, cotas, lineas de medicion, marcas de regla o anotaciones.
No zapatos ni calzado como referencia de escala.
No perspectiva deformada.
No redibujar ni repintar la obra.
TEXT,
            'mockup_quality_rules' => <<<'TEXT'
Crear un mockup integrado, no una foto pegada sobre fondo generico.
Agregar sombras reales, contacto con muro, profundidad, borde fisico del lienzo y luz ambiental sutil.
El ambiente debe sentirse sofisticado, europeo o americano, de coleccionista, galeria, museo, feria de arte o interior de alto nivel.
El impacto emocional debe venir de escena, luz y contexto, no de falsa escala.
La obra debe sentirse colocada, coleccionada y deseada.
TEXT,
            'mockup_context_count' => '10',
        ];
    }

    private static function normalizeContextCount(string $value): int
    {
        $count = (int)$value;

        if ($count < 1) {
            return 1;
        }

        if ($count > 10) {
            return 10;
        }

        return $count;
    }
}
