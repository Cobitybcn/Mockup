<?php
declare(strict_types=1);

/**
 * Configuracion minima que un artista Pro necesita antes de usar el resto
 * de la plataforma: idioma explicito y subdominio. El plan Standard nunca
 * se gatea (no depende de esta configuracion) y los admins quedan afuera.
 */
final class ArtistOnboarding
{
    public static function isRequired(array $user): bool
    {
        if (Auth::isAdmin($user)) {
            return false;
        }
        return (string)($user['plan_code'] ?? '') === FeatureAccess::PLAN_ARTIST_PRO;
    }

    public static function isComplete(array $user, ?PDO $pdo = null): bool
    {
        if (!self::isRequired($user)) {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return true;
        }

        $policy = LanguagePolicy::forUser($userId, $pdo);
        if (!$policy['is_explicit']) {
            return false;
        }

        $pdo ??= Database::connection();
        $stmt = $pdo->prepare('SELECT subdomain FROM artist_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);

        return trim((string)($stmt->fetchColumn() ?: '')) !== '';
    }
}
