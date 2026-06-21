<?php
declare(strict_types=1);

/** Persistent, admin-editable narrative archetypes for Social Video. */
class SocialVideoArchetypes
{
    private const SETTING_KEY = 'social_video_archetypes';

    public static function all(): array
    {
        $archetypes = self::defaults();
        try {
            $stmt = Database::connection()->prepare('SELECT value FROM app_settings WHERE `key` = :key LIMIT 1');
            $stmt->execute(['key' => self::SETTING_KEY]);
            $stored = json_decode((string)$stmt->fetchColumn(), true);
            if (is_array($stored) && $stored !== []) {
                $archetypes = self::normalize($stored);
            }
        } catch (Throwable $e) {
            Logger::log('Could not load Social Video archetypes: ' . $e->getMessage(), 'warning');
        }
        return $archetypes;
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $archetype) {
            if (($archetype['id'] ?? '') === $id) { return $archetype; }
        }
        return null;
    }

    public static function saveJson(string $json): void
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new InvalidArgumentException('La lista de arquetipos debe ser un JSON con al menos un elemento.');
        }
        $value = json_encode(self::normalize($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = Database::connection()->prepare(Database::appSettingUpsertSql());
        $stmt->execute(['key' => self::SETTING_KEY, 'value' => $value, 'updated_at' => date('c')]);
    }

    public static function json(): string
    {
        return json_encode(self::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private static function normalize(array $items): array
    {
        $result = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $id = strtolower(trim((string)($item['id'] ?? '')));
            if ($id === '' || !preg_match('/^[a-z0-9_\-]+$/', $id) || isset($seen[$id])) { continue; }
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') { continue; }
            $seen[$id] = true;
            $result[] = [
                'id' => $id,
                'name' => $name,
                'description' => trim((string)($item['description'] ?? '')),
                'requires_new_image_generation' => !empty($item['requires_new_image_generation']),
                'requires_second_artwork' => !empty($item['requires_second_artwork']),
                'selector_guidance' => trim((string)($item['selector_guidance'] ?? '')),
            ];
        }
        if ($result === []) { throw new InvalidArgumentException('Cada arquetipo necesita un id único y un nombre.'); }
        return $result;
    }

    private static function defaults(): array
    {
        return [
            ['id'=>'genesis','name'=>'Génesis','description'=>'La obra emerge o toma forma en un estudio/atelier: proceso y origen, no un objeto terminado en la pared.','requires_new_image_generation'=>true,'requires_second_artwork'=>false,'selector_guidance'=>'Priorizar obras con textura visible, huellas de proceso o superficie gestual que puedan mostrarse como algo que está tomando forma; menos apropiado para superficies planas, mínimas o muy pulidas.'],
            ['id'=>'contexto_vivido','name'=>'Contexto Vivido','description'=>'La obra habita un espacio con presencia humana. Es el flujo existente de contexto, contemplación y escala.','requires_new_image_generation'=>false,'requires_second_artwork'=>false,'selector_guidance'=>'Priorizar mockups existentes compatibles y una presencia humana o espacial que refuerce la escala y la contemplación de cualidades visibles de la obra.'],
            ['id'=>'dimension_metafisica','name'=>'Dimensión Metafísica','description'=>'Un color, forma o marca de la obra adquiere escala o vida propia, alejándose de la lógica de objeto enmarcado.','requires_new_image_generation'=>true,'requires_second_artwork'=>false,'selector_guidance'=>'Priorizar obras con color intenso, formas abiertas, tensión compositiva o marcas que puedan extenderse hacia una escena abstracta y filmable.'],
            ['id'=>'dialogo_simbolico','name'=>'Diálogo Simbólico','description'=>'Una relación visual o conceptual entre dos obras: díptico, contraste o conversación visual.','requires_new_image_generation'=>false,'requires_second_artwork'=>true,'selector_guidance'=>'Priorizar una obra con colores, símbolos, ritmo o composición que pueda entrar en contraste o resonancia visible con una segunda obra.'],
        ];
    }
}
