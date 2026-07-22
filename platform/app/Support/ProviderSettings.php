<?php
declare(strict_types=1);

class ProviderSettings
{
    private const MAX_MOCKUP_WORKERS = 8;

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
            'ffmpeg_binary_path' => $input['ffmpeg_binary_path'] ?? '',
            'stripe_connect_redirect_uri' => $input['stripe_connect_redirect_uri'] ?? '',
        ]);

        foreach ([
            'openai_api_key',
            'gemini_api_key',
            'stripe_connect_secret_key',
            'stripe_connect_client_id',
            'stripe_connect_webhook_secret',
        ] as $key) {
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

        self::validateStripeSettings($settings);

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
            'ffmpeg_binary_path' => self::ffmpegBinaryPath(),
            'stripe_connect_redirect_uri' => self::stripeConnectRedirectUri(),
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
        $configured = self::value('openai_api_key', defined('OPENAI_API_KEY') ? (string)OPENAI_API_KEY : '');
        if ($configured !== '') {
            return $configured;
        }

        // A blank OPENAI_API_KEY entry in .env must not hide a securely stored
        // process/user environment variable (the assistant config follows the
        // same fallback rule).
        $environment = getenv('OPENAI_API_KEY');
        return $environment === false ? '' : trim((string)$environment);
    }

    public static function canSelectGenerationProvider(bool $isAdmin, string $httpHost = ''): bool
    {
        if ($isAdmin) {
            return true;
        }

        $host = strtolower(trim(explode(',', $httpHost)[0] ?? ''));
        if (str_starts_with($host, '[')) {
            $closingBracket = strpos($host, ']');
            $host = $closingBracket === false ? trim($host, '[]') : substr($host, 1, $closingBracket - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = explode(':', $host, 2)[0];
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
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

    public static function ffmpegBinaryPath(): string
    {
        return self::value('ffmpeg_binary_path', '');
    }

    public static function stripeConnectSecretKey(): string
    {
        return self::value('stripe_connect_secret_key', self::environmentValue('STRIPE_CONNECT_SECRET_KEY'));
    }

    public static function stripeConnectClientId(): string
    {
        return self::value('stripe_connect_client_id', self::environmentValue('STRIPE_CONNECT_CLIENT_ID'));
    }

    public static function stripeConnectWebhookSecret(): string
    {
        return self::value('stripe_connect_webhook_secret', self::environmentValue('STRIPE_CONNECT_WEBHOOK_SECRET'));
    }

    public static function stripeConnectRedirectUri(): string
    {
        return self::value('stripe_connect_redirect_uri', self::environmentValue('STRIPE_CONNECT_REDIRECT_URI'));
    }

    /** @return array{ready:bool,mode:string,secret_key:bool,client_id:bool,webhook_secret:bool,redirect_uri:bool} */
    public static function stripeConnectStatus(): array
    {
        $secretKey = self::stripeConnectSecretKey();
        $clientId = self::stripeConnectClientId();
        $webhookSecret = self::stripeConnectWebhookSecret();
        $redirectUri = self::stripeConnectRedirectUri();
        $mode = str_starts_with($secretKey, 'sk_live_') ? 'live' : (str_starts_with($secretKey, 'sk_test_') ? 'test' : 'not configured');
        $status = [
            'mode' => $mode,
            'secret_key' => str_starts_with($secretKey, 'sk_live_') || str_starts_with($secretKey, 'sk_test_'),
            'client_id' => str_starts_with($clientId, 'ca_'),
            'webhook_secret' => str_starts_with($webhookSecret, 'whsec_'),
            'redirect_uri' => filter_var($redirectUri, FILTER_VALIDATE_URL) !== false,
        ];
        $status['ready'] = $status['secret_key'] && $status['client_id'] && $status['webhook_secret'] && $status['redirect_uri'];
        return $status;
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
            'ffmpeg_binary_path',
            'stripe_connect_secret_key',
            'stripe_connect_client_id',
            'stripe_connect_webhook_secret',
            'stripe_connect_redirect_uri',
        ];
    }

    private static function validateStripeSettings(array $settings): void
    {
        $validators = [
            'stripe_connect_secret_key' => static fn(string $value): bool => str_starts_with($value, 'sk_test_') || str_starts_with($value, 'sk_live_'),
            'stripe_connect_client_id' => static fn(string $value): bool => str_starts_with($value, 'ca_'),
            'stripe_connect_webhook_secret' => static fn(string $value): bool => str_starts_with($value, 'whsec_'),
            'stripe_connect_redirect_uri' => static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_URL) !== false,
        ];
        $labels = [
            'stripe_connect_secret_key' => 'Stripe secret key',
            'stripe_connect_client_id' => 'Stripe Connect client ID',
            'stripe_connect_webhook_secret' => 'Stripe webhook secret',
            'stripe_connect_redirect_uri' => 'Stripe redirect URI',
        ];

        foreach ($validators as $key => $validator) {
            $value = trim((string)($settings[$key] ?? ''));
            if ($value !== '' && !$validator($value)) {
                throw new InvalidArgumentException($labels[$key] . ' has an invalid format.');
            }
        }
    }

    private static function environmentValue(string $key): string
    {
        $configured = function_exists('app_env') ? trim((string)app_env($key, '')) : '';
        if ($configured !== '') {
            return $configured;
        }

        $environment = getenv($key);
        return $environment === false ? '' : trim((string)$environment);
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

    public static function readForRoot(string $imagePath): array
    {
        $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

        if (!is_file($metaPath)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($metaPath), true);

        return is_array($data) && isset($data['provider_settings']) && is_array($data['provider_settings'])
            ? $data['provider_settings']
            : [];
    }
}
