<?php
declare(strict_types=1);

class ProviderSettings
{
    private const MAX_MOCKUP_WORKERS = 6;

    private static array $settings = [];
    private static bool $loaded = false;

    public static function set(array $settings): void
    {
        self::$settings = array_merge(self::$settings, self::sanitize($settings));
    }

    public static function save(array $input): void
    {
        $pdo = Database::connection();
        $existing = self::allStored();
        $stmt = $pdo->prepare(Database::appSettingUpsertSql());
        $now = date('c');

        $settings = self::sanitize([
            'app_mode' => $input['app_mode'] ?? '',
            'allow_real_api' => isset($input['allow_real_api']) ? '1' : '0',
            'image_provider' => $input['image_provider'] ?? '',
            'gemini_image_model' => $input['gemini_image_model'] ?? '',
            'openai_image_model' => $input['openai_image_model'] ?? '',
            'openai_analysis_model' => $input['openai_analysis_model'] ?? '',
            'openai_image_quality' => $input['openai_image_quality'] ?? '',
            'openai_image_size' => $input['openai_image_size'] ?? '',
            'mockup_worker_count' => $input['mockup_worker_count'] ?? '',
            'social_video_veo_enabled' => isset($input['social_video_veo_enabled']) ? '1' : '0',
            'social_video_veo_model' => $input['social_video_veo_model'] ?? '',
            'social_video_veo_region' => $input['social_video_veo_region'] ?? '',
            'social_video_veo_resolution' => $input['social_video_veo_resolution'] ?? '',
            'social_video_veo_storage_uri' => $input['social_video_veo_storage_uri'] ?? '',
            'ffmpeg_binary_path' => $input['ffmpeg_binary_path'] ?? '',
        ]);

        foreach (['openai_api_key', 'gemini_api_key'] as $key) {
            $clearKey = 'clear_' . $key;
            $inputValue = trim((string)($input[$key] ?? ''));

            if (!empty($input[$clearKey])) {
                $settings[$key] = '';
            } elseif ($inputValue !== '') {
                $settings[$key] = $inputValue;
            } elseif (array_key_exists($key, $existing)) {
                $settings[$key] = (string)$existing[$key];
            }
        }

        foreach ($settings as $key => $value) {
            $stmt->execute([
                'key' => $key,
                'value' => (string)$value,
                'updated_at' => $now,
            ]);
        }

        self::$settings = array_merge(self::$settings, $settings);
        self::$loaded = true;
    }

    public static function all(): array
    {
        return [
            'app_mode' => self::appMode(),
            'allow_real_api' => self::allowRealApi() ? '1' : '0',
            'image_provider' => self::imageProvider(),
            'gemini_image_model' => self::geminiImageModel(),
            'openai_image_model' => self::openAIImageModel(),
            'openai_analysis_model' => self::openAIAnalysisModel(),
            'openai_image_quality' => self::openAIImageQuality(),
            'openai_image_size' => self::openAIImageSize(),
            'mockup_worker_count' => (string)self::mockupWorkerCount(),
            'social_video_veo_enabled' => self::socialVideoVeoEnabled() ? '1' : '0',
            'social_video_veo_model' => self::socialVideoVeoModel(),
            'social_video_veo_region' => self::socialVideoVeoRegion(),
            'social_video_veo_resolution' => self::socialVideoVeoResolution(),
            'social_video_veo_storage_uri' => self::socialVideoVeoStorageUri(),
            'ffmpeg_binary_path' => self::ffmpegBinaryPath(),
        ];
    }

    public static function appMode(): string
    {
        return self::normalizeAppMode(
            self::value('app_mode', defined('APP_MODE') ? (string)APP_MODE : 'mock')
        );
    }

    /**
     * Punto #1: verdadero si el modo es 'gemini' o 'openai' (ambos usan APIs reales).
     * Simplifica las comparaciones en ServiceFactory eliminando la doble negación.
     */
    public static function isRealMode(): bool
    {
        $mode = self::appMode();
        return $mode === 'gemini' || $mode === 'openai';
    }

    public static function allowRealApi(): bool
    {
        $fallback = defined('ALLOW_REAL_API') && ALLOW_REAL_API === true ? '1' : '0';
        return self::truthy(self::value('allow_real_api', $fallback));
    }

    public static function imageProvider(): string
    {
        return self::normalizeImageProvider(
            self::value('image_provider', defined('IMAGE_PROVIDER') ? (string)IMAGE_PROVIDER : 'openai')
        );
    }

    public static function openAIAPIKey(): string
    {
        return self::value('openai_api_key', defined('OPENAI_API_KEY') ? (string)OPENAI_API_KEY : '');
    }

    public static function geminiAPIKey(): string
    {
        return self::value('gemini_api_key', defined('GEMINI_API_KEY') ? (string)GEMINI_API_KEY : '');
    }

    public static function geminiImageModel(): string
    {
        return self::normalizeGeminiModel(
            self::value('gemini_image_model', defined('GEMINI_IMAGE_MODEL') ? (string)GEMINI_IMAGE_MODEL : 'gemini-3.1-flash-image')
        );
    }

    public static function openAIImageModel(): string
    {
        return self::value('openai_image_model', defined('OPENAI_IMAGE_MODEL') ? (string)OPENAI_IMAGE_MODEL : 'gpt-image-1');
    }

    public static function openAIAnalysisModel(): string
    {
        return self::value('openai_analysis_model', defined('OPENAI_ANALYSIS_MODEL') ? (string)OPENAI_ANALYSIS_MODEL : 'gpt-4.1-mini');
    }

    public static function openAIImageQuality(): string
    {
        return self::value('openai_image_quality', defined('OPENAI_IMAGE_QUALITY') ? (string)OPENAI_IMAGE_QUALITY : 'low');
    }

    public static function openAIImageSize(): string
    {
        return self::value('openai_image_size', defined('OPENAI_IMAGE_SIZE') ? (string)OPENAI_IMAGE_SIZE : '1024x1024');
    }

    public static function mockupWorkerCount(): int
    {
        return self::normalizeWorkerCount(
            self::value('mockup_worker_count', defined('MOCKUP_WORKER_COUNT') ? (string)MOCKUP_WORKER_COUNT : '4')
        );
    }

    public static function socialVideoVeoEnabled(): bool
    {
        return self::truthy(self::value('social_video_veo_enabled', '0'));
    }

    public static function socialVideoVeoModel(): string
    {
        return self::value('social_video_veo_model', 'veo-3.1-lite-generate-001');
    }

    public static function socialVideoVeoRegion(): string
    {
        return self::value('social_video_veo_region', 'us-central1');
    }

    public static function socialVideoVeoResolution(): string
    {
        return self::value('social_video_veo_resolution', '1080p');
    }

    public static function socialVideoVeoStorageUri(): string
    {
        return self::value('social_video_veo_storage_uri', 'gs://artwork-curator-veo-output');
    }

    public static function ffmpegBinaryPath(): string
    {
        return self::value('ffmpeg_binary_path', '');
    }

    private static function value(string $key, string $fallback): string
    {
        self::load();
        $value = trim((string)(self::$settings[$key] ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$settings = array_merge(self::allStored(), self::$settings);
        self::$loaded = true;
    }

    private static function allStored(): array
    {
        try {
            $stmt = Database::connection()->query('SELECT `key`, value FROM app_settings');
            $settings = [];

            foreach ($stmt->fetchAll() as $row) {
                $key = (string)($row['key'] ?? '');

                if (in_array($key, self::allowedKeys(), true)) {
                    $settings[$key] = (string)($row['value'] ?? '');
                }
            }

            return self::sanitize($settings);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function sanitize(array $settings): array
    {
        $clean = [];

        foreach (self::allowedKeys() as $key) {
            if (isset($settings[$key])) {
                $clean[$key] = trim((string)$settings[$key]);
            }
        }

        if (isset($clean['app_mode'])) {
            $clean['app_mode'] = self::normalizeAppMode($clean['app_mode']);
        }

        if (isset($clean['allow_real_api'])) {
            $clean['allow_real_api'] = self::truthy($clean['allow_real_api']) ? '1' : '0';
        }

        if (isset($clean['image_provider'])) {
            $clean['image_provider'] = self::normalizeImageProvider($clean['image_provider']);
        }

        if (isset($clean['gemini_image_model'])) {
            $clean['gemini_image_model'] = self::normalizeGeminiModel($clean['gemini_image_model']);
        }

        if (isset($clean['mockup_worker_count'])) {
            $clean['mockup_worker_count'] = (string)self::normalizeWorkerCount($clean['mockup_worker_count']);
        }

        if (isset($clean['social_video_veo_enabled'])) {
            $clean['social_video_veo_enabled'] = self::truthy($clean['social_video_veo_enabled']) ? '1' : '0';
        }

        return $clean;
    }

    private static function allowedKeys(): array
    {
        return [
            'app_mode',
            'allow_real_api',
            'image_provider',
            'openai_api_key',
            'gemini_api_key',
            'gemini_image_model',
            'openai_image_model',
            'openai_analysis_model',
            'openai_image_quality',
            'openai_image_size',
            'mockup_worker_count',
            'social_video_veo_enabled',
            'social_video_veo_model',
            'social_video_veo_region',
            'social_video_veo_resolution',
            'social_video_veo_storage_uri',
            'ffmpeg_binary_path',
        ];
    }

    private static function normalizeAppMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        // Punto #1: 'gemini' es el modo real explícito para Gemini/Vertex AI
        // 'openai' se mantiene como alias retrocompatible
        if ($mode === 'gemini') {
            return 'gemini';
        }
        if ($mode === 'openai') {
            return 'openai';
        }
        return 'mock';
    }

    private static function normalizeImageProvider(string $provider): string
    {
        return strtolower(trim($provider)) === 'gemini' ? 'gemini' : 'openai';
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeGeminiModel(string $model): string
    {
        $model = strtolower(trim($model));

        if (in_array($model, [
            'gemini-3.1-flash-image',
            'gemini-3-pro-image',
            'gemini-2.5-flash-image',
        ], true)) {
            return $model;
        }

        return 'gemini-3.1-flash-image';
    }

    private static function normalizeWorkerCount(string $value): int
    {
        $workers = (int)trim($value);
        return max(1, min(self::MAX_MOCKUP_WORKERS, $workers));
    }
}
