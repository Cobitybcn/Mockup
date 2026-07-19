<?php
declare(strict_types=1);

final class UiPreview
{
    private const ENV_FLAG = 'UI_VISUAL_CONSISTENCY_PREVIEW';
    private const LOCAL_REVIEWERS_ENV = 'UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS';

    /** @var list<string> */
    private const ALLOWED_SCOPES = [
        'artworks-kpi',
        'series-catalog',
    ];

    public static function isActive(array $user, string $scope): bool
    {
        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            return false;
        }

        if (!self::masterFlagEnabled() || (!Auth::isAdmin($user) && !self::isLocalReviewer($user))) {
            return false;
        }

        return in_array($scope, self::requestedScopes(), true);
    }

    /** @return list<string> */
    public static function requestedScopes(): array
    {
        $raw = $_GET['design_preview'] ?? '';
        if (!is_string($raw)) {
            return [];
        }

        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return [];
        }

        $requested = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($requested) || $requested === []) {
            return [];
        }

        foreach ($requested as $scope) {
            if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
                return [];
            }
        }

        return array_values(array_unique($requested));
    }

    private static function masterFlagEnabled(): bool
    {
        return filter_var(
            app_env(self::ENV_FLAG, 'false'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) === true;
    }

    private static function isLocalReviewer(array $user): bool
    {
        if (strtolower(app_env('APP_ENV', 'production')) !== 'local') {
            return false;
        }

        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($email === '') {
            return false;
        }

        $reviewers = array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            preg_split('/[\s,;]+/', app_env(self::LOCAL_REVIEWERS_ENV, ''), -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));

        return in_array($email, $reviewers, true);
    }
}
