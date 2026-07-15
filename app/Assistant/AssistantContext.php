<?php
declare(strict_types=1);

final class AssistantContext
{
    public function __construct(private PDO $pdo) {}

    public static function page(array $input): array
    {
        $route = basename(str_replace('\\', '/', (string)($input['current_route'] ?? 'dashboard.php')));
        if (!preg_match('/^[a-z0-9_.-]+\.php$/i', $route)) {
            $route = 'dashboard.php';
        }
        $pageType = self::pageType($route);
        $page = ['current_route' => $route, 'page_type' => $pageType];
        foreach (['artwork_id','series_id','mockup_id','generation_id','publication_id'] as $key) {
            $value = (int)($input[$key] ?? 0);
            if ($value > 0) {
                $page[$key] = $value;
            }
        }
        $selected = array_values(array_unique(array_filter(array_map('intval', (array)($input['selected_mockup_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        if ($selected) {
            $page['selected_mockup_ids'] = array_slice($selected, 0, 12);
        }
        $uiTarget = self::uiTarget($input['ui_target'] ?? null);
        if ($uiTarget !== []) {
            $page['ui_target'] = $uiTarget;
        }
        return $page;
    }

    public static function label(string $pageType): string
    {
        return match ($pageType) {
            'artwork_detail' => 'Obra abierta',
            'series' => 'Serie',
            'mockup_album' => 'Álbum de mockups',
            'mockup_results' => 'Resultados de mockups',
            'mockup_lab' => 'Laboratorio de mockups',
            'website_publisher' => 'Website',
            'social_publishing' => 'Publicación social',
            'scene_studio' => 'Estudio de escenas',
            'scene_creation' => 'Creación de escenas',
            'camera_boards' => 'Cámaras',
            'prompt_admin' => 'Prompts',
            'user_admin' => 'Usuarios',
            default => 'Área privada',
        };
    }

    public function build(array $user, array $page): array
    {
        $userId = (int)$user['id'];
        $context = [
            'environment_type' => 'artworkmockups_faithful',
            'current_route' => (string)$page['current_route'],
            'page_type' => (string)$page['page_type'],
            'signed_in_account' => [
                'name' => (string)($user['name'] ?? ''),
                'role' => Auth::isAdmin($user) ? 'admin' : 'user',
                'credits' => (int)($user['credits'] ?? 0),
            ],
            'permissions' => [
                'read_own_artworks' => true,
                'read_own_mockups' => true,
                'prepare_codex_task' => true,
                'modify_content' => false,
                'run_generation' => false,
                'change_prompts' => false,
            ],
            'workspace_counts' => $this->counts($userId),
        ];
        if (isset($page['artwork_id'])) {
            $context['artwork'] = $this->artwork($userId, (int)$page['artwork_id']);
        }
        if (isset($page['series_id'])) {
            $context['series'] = $this->series($userId, (int)$page['series_id']);
        }
        if (isset($page['mockup_id'])) {
            $context['mockup'] = $this->mockup($userId, (int)$page['mockup_id']);
        }
        if (isset($page['generation_id'])) {
            $context['generation'] = $this->generation($userId, (int)$page['generation_id']);
        }
        if (isset($page['publication_id']) && $this->tableExists('publications')) {
            $context['publication'] = $this->publication($userId, (int)$page['publication_id']);
        }
        if (!empty($page['selected_mockup_ids'])) {
            $context['selected_mockups'] = $this->selectedMockups($userId, (array)$page['selected_mockup_ids']);
        }
        if (!empty($page['ui_target'])) {
            $context['ui_target'] = ['source' => 'user_selected_visible_element'] + (array)$page['ui_target'];
        }
        return $context;
    }

    private function counts(int $userId): array
    {
        $counts = [];
        foreach (['artworks' => 'artworks', 'mockups' => 'mockups', 'series' => 'artwork_series', 'generations' => 'mockup_generation_jobs'] as $label => $table) {
            if (!$this->tableExists($table)) {
                $counts[$label] = 0;
                continue;
            }
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE user_id=?");
            $statement->execute([$userId]);
            $counts[$label] = (int)$statement->fetchColumn();
        }
        if ($this->tableExists('publications')) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM publications WHERE user_id=?');
            $statement->execute([$userId]);
            $counts['publications'] = (int)$statement->fetchColumn();
        }
        return $counts;
    }

    private function artwork(int $userId, int $id): array
    {
        $statement = $this->pdo->prepare("SELECT a.id,a.final_title,a.subtitle,a.medium,a.artwork_year,a.series,a.status,a.width,a.height,a.depth,a.unit,a.root_view_type,a.root_view_status,a.series_id,
            COALESCE(s.title,'') series_title,COALESCE(sh.description,'') description,COALESCE(sh.short_description,'') short_description,COALESCE(sh.keywords,'') keywords,COALESCE(sh.tags,'') tags,COALESCE(sh.alt_text,'') alt_text,COALESCE(sh.status,'') editorial_status
            FROM artworks a
            LEFT JOIN artwork_series s ON s.id=a.series_id AND s.user_id=a.user_id
            LEFT JOIN artwork_sheets sh ON sh.id=(SELECT MAX(sh2.id) FROM artwork_sheets sh2 WHERE sh2.user_id=a.user_id AND sh2.canonical_artwork_id=a.id)
            WHERE a.id=? AND a.user_id=? LIMIT 1");
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new AssistantException('La obra indicada no está disponible para esta cuenta.', 'artwork_not_found');
        }
        return $row;
    }

    private function series(int $userId, int $id): array
    {
        $statement = $this->pdo->prepare("SELECT s.id,s.title,s.subtitle,s.description,s.long_description,s.keywords,s.tags,s.seo_description,s.year_start,s.year_end,s.status,s.published,(SELECT COUNT(*) FROM artworks a WHERE a.user_id=s.user_id AND a.series_id=s.id) artwork_count FROM artwork_series s WHERE s.id=? AND s.user_id=? LIMIT 1");
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new AssistantException('La serie indicada no está disponible para esta cuenta.', 'series_not_found');
        }
        return $row;
    }

    private function mockup(int $userId, int $id): array
    {
        $statement = $this->pdo->prepare("SELECT m.id,m.context_id,m.created_at,m.source_artwork_id,m.series_id,COALESCE(a.final_title,'') artwork_title,COALESCE(s.title,'') series_title,COALESCE(ms.title,'') title,COALESCE(ms.description,'') description,COALESCE(ms.alt_text,'') alt_text,COALESCE(ms.status,'') editorial_status FROM mockups m LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id LEFT JOIN artwork_series s ON s.id=COALESCE(m.series_id,a.series_id) AND s.user_id=m.user_id LEFT JOIN mockup_sheets ms ON ms.id=(SELECT MAX(ms2.id) FROM mockup_sheets ms2 WHERE ms2.user_id=m.user_id AND ms2.mockup_id=m.id) WHERE m.id=? AND m.user_id=? LIMIT 1");
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new AssistantException('El mockup indicado no está disponible para esta cuenta.', 'mockup_not_found');
        }
        return $row;
    }

    private function generation(int $userId, int $id): array
    {
        $statement = $this->pdo->prepare('SELECT id,artwork_id,context_id,status,mockup_id,attempts,created_at,updated_at FROM mockup_generation_jobs WHERE id=? AND user_id=? LIMIT 1');
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new AssistantException('La generación indicada no está disponible para esta cuenta.', 'generation_not_found');
        }
        return $row;
    }

    private function publication(int $userId, int $id): array
    {
        $statement = $this->pdo->prepare('SELECT id,artwork_sheet_id,title,short_description,description,visibility,status,updated_at FROM publications WHERE id=? AND user_id=? LIMIT 1');
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new AssistantException('La publicación indicada no está disponible para esta cuenta.', 'publication_not_found');
        }
        return $row;
    }

    private function selectedMockups(int $userId, array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))), 0, 12);
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare("SELECT m.id,m.context_id,m.source_artwork_id,COALESCE(a.final_title,'') artwork_title FROM mockups m LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id WHERE m.user_id=? AND m.id IN ($placeholders) ORDER BY m.id");
        $statement->execute([$userId, ...$ids]);
        return $statement->fetchAll();
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $statement->execute([$table]);
            return (int)$statement->fetchColumn() > 0;
        }
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }

    private static function pageType(string $route): string
    {
        return match ($route) {
            'artwork.php', 'artwork_details.php' => 'artwork_detail',
            'series.php' => 'series',
            'mockups.php', 'viewer.php' => 'mockup_album',
            'mockup_combination_results.php', 'mockup_combinations_review.php' => 'mockup_results',
            'mockup_variation_lab.php' => 'mockup_lab',
            'website_board.php', 'website_catalog.php', 'website_studio_notes.php' => 'website_publisher',
            'social_media_board.php', 'social_media_catalog.php' => 'social_publishing',
            'create_scenes.php' => 'scene_creation',
            'world_mother_studio.php' => 'scene_studio',
            'camera_studio.php' => 'camera_boards',
            'admin_prompts.php' => 'prompt_admin',
            'admin_users.php' => 'user_admin',
            default => 'private_page',
        };
    }

    private static function uiTarget(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $target = [];
        $limits = [
            'tag' => 24,
            'role' => 40,
            'type' => 40,
            'text' => 500,
            'aria_label' => 300,
            'title' => 300,
            'element_id' => 120,
            'nearby_text' => 600,
        ];
        foreach ($limits as $key => $limit) {
            $value = trim(preg_replace('/\s+/u', ' ', (string)($input[$key] ?? '')) ?? '');
            if ($value !== '') {
                $target[$key] = mb_substr($value, 0, $limit);
            }
        }
        $bounds = [];
        foreach (['x','y','width','height','viewport_width','viewport_height'] as $key) {
            if (isset($input['bounds'][$key]) && is_numeric($input['bounds'][$key])) {
                $bounds[$key] = max(-10000, min(10000, (int)round((float)$input['bounds'][$key])));
            }
        }
        if ($bounds !== []) {
            $target['bounds'] = $bounds;
        }
        $styles = [];
        foreach (['background_color','color','font_size','font_weight','padding','border_radius','border'] as $key) {
            $value = trim(preg_replace('/\s+/u', ' ', (string)($input['styles'][$key] ?? '')) ?? '');
            if ($value !== '') {
                $styles[$key] = mb_substr($value, 0, 160);
            }
        }
        if ($styles !== []) {
            $target['styles'] = $styles;
        }
        return $target;
    }
}
