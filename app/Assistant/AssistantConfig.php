<?php
declare(strict_types=1);

final class AssistantConfig
{
    public function enabled(): bool
    {
        return filter_var(app_env('ASSISTANT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function enabledFor(array $user): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if (Auth::isAdmin($user)) {
            return filter_var(app_env('ASSISTANT_ADMIN_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        }
        return filter_var(app_env('ASSISTANT_APP_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public function apiKey(): string
    {
        $configured = trim((string)(defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ''));
        if ($configured !== '') {
            return $configured;
        }
        $environment = getenv('OPENAI_API_KEY');
        return $environment === false ? '' : trim((string)$environment);
    }

    public function model(): string
    {
        return app_env('OPENAI_ASSISTANT_MODEL', 'gpt-5.6-terra');
    }

    public function apiBase(): string
    {
        return rtrim(app_env('OPENAI_API_BASE', 'https://api.openai.com/v1'), '/');
    }

    public function maxOutputTokens(): int
    {
        return max(256, min(4000, (int)app_env('ASSISTANT_MAX_OUTPUT_TOKENS', '1200')));
    }

    public function historyMessages(): int
    {
        return max(2, min(24, (int)app_env('ASSISTANT_HISTORY_MESSAGES', '12')));
    }

    public function perMinuteLimit(): int
    {
        return max(1, min(60, (int)app_env('ASSISTANT_RATE_LIMIT_PER_MINUTE', '12')));
    }

    public function dailyLimit(): int
    {
        return max(10, min(5000, (int)app_env('ASSISTANT_DAILY_MESSAGE_LIMIT', '250')));
    }
}
