<?php
declare(strict_types=1);

final class FeatureAccess
{
    public const PLAN_ARTIST_STUDIO = 'artist_studio';
    public const PLAN_ARTIST_PRO = 'artist_pro';

    public const ARTWORKS_MANAGE = 'artworks.manage';
    public const MOCKUPS_GENERATE = 'mockups.generate';
    public const MOCKUPS_LAB = 'mockups.lab';
    public const WEBSITE_MANAGE = 'website.manage';
    public const SOCIAL_MANAGE = 'social.manage';
    public const VIDEO_MANAGE = 'video.manage';
    public const ADMIN_USERS = 'admin.users';
    public const ADMIN_SYSTEM = 'admin.system';
    public const ADMIN_SCENE_LIBRARY = 'admin.scene_library';
    public const ADMIN_CAMERA_LIBRARY = 'admin.camera_library';

    /** @var array<int,array<string,bool>> */
    private static array $overrideCache = [];

    /** @return array<string,string> */
    public static function plans(): array
    {
        return [
            self::PLAN_ARTIST_STUDIO => 'Artist Studio',
            self::PLAN_ARTIST_PRO => 'Artist Pro',
        ];
    }

    /** @return array<string,string> */
    public static function overridableFeatures(): array
    {
        return [
            self::WEBSITE_MANAGE => 'Website Catalog Sync',
            self::SOCIAL_MANAGE => 'Social Media Board',
            self::VIDEO_MANAGE => 'Video Lab',
        ];
    }

    public static function normalizePlan(string $plan): string
    {
        return array_key_exists($plan, self::plans()) ? $plan : self::PLAN_ARTIST_STUDIO;
    }

    public static function planForUser(array $user): string
    {
        return self::normalizePlan((string)($user['plan_code'] ?? self::PLAN_ARTIST_STUDIO));
    }

    public static function planLabel(array|string $userOrPlan): string
    {
        $plan = is_array($userOrPlan)
            ? self::planForUser($userOrPlan)
            : self::normalizePlan($userOrPlan);

        return self::plans()[$plan];
    }

    public static function isKnownFeature(string $feature): bool
    {
        return in_array($feature, self::allFeatures(), true);
    }

    public static function planAllows(string $plan, string $feature): bool
    {
        $plan = self::normalizePlan($plan);
        if (!self::isKnownFeature($feature)) {
            return false;
        }

        $studioFeatures = [
            self::ARTWORKS_MANAGE,
            self::MOCKUPS_GENERATE,
            self::MOCKUPS_LAB,
        ];

        if ($plan === self::PLAN_ARTIST_PRO) {
            return in_array($feature, [
                ...$studioFeatures,
                self::WEBSITE_MANAGE,
                self::SOCIAL_MANAGE,
                self::VIDEO_MANAGE,
            ], true);
        }

        return in_array($feature, $studioFeatures, true);
    }

    public static function allows(array $user, string $feature): bool
    {
        if (!self::isKnownFeature($feature) || (string)($user['status'] ?? 'active') !== 'active') {
            return false;
        }

        if (Auth::isAdmin($user)) {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId > 0) {
            $overrides = self::overridesForUser($userId);
            if (array_key_exists($feature, $overrides)) {
                return $overrides[$feature];
            }
        }

        if (!self::globallyEnabled($feature)) {
            return false;
        }

        return self::planAllows(self::planForUser($user), $feature);
    }

    public static function requirePage(array $user, string $feature, string $label): void
    {
        if (self::allows($user, $feature)) {
            return;
        }

        http_response_code(403);
        header('Cache-Control: private, no-store');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $upgradePath = 'account.php?upgrade=artist_pro&feature=' . rawurlencode($feature) . '#plan';
        $upgradeUrl = class_exists('PublicPage') ? PublicPage::path($upgradePath) : $upgradePath;
        $safeUrl = htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8');
        exit("<!doctype html><html lang=\"es\"><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Artist Pro</title><body style=\"font-family:Arial,sans-serif;max-width:680px;margin:12vh auto;padding:24px;line-height:1.6;color:#302b27\"><h1>{$safeLabel} requiere Artist Pro</h1><p>Tu cuenta y tus archivos no cambiaron. Puedes solicitar el upgrade desde la sección de plan.</p><p><a href=\"{$safeUrl}\">Ver mi plan</a></p></body></html>");
    }

    public static function requireJson(array $user, string $feature, string $label): void
    {
        if (self::allows($user, $feature)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode([
            'ok' => false,
            'error' => $label . ' requiere Artist Pro.',
            'code' => 'FEATURE_ACCESS_DENIED',
            'feature' => $feature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @return array<string,bool> */
    public static function overridesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (array_key_exists($userId, self::$overrideCache)) {
            return self::$overrideCache[$userId];
        }

        $stmt = Database::connection()->prepare('
            SELECT feature_key, allowed, expires_at
            FROM user_feature_overrides
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        $overrides = [];
        $now = time();
        foreach ($stmt->fetchAll() as $row) {
            $feature = (string)($row['feature_key'] ?? '');
            $expiresAt = trim((string)($row['expires_at'] ?? ''));
            if (!self::isKnownFeature($feature)) {
                continue;
            }
            if ($expiresAt !== '' && ($timestamp = strtotime($expiresAt)) !== false && $timestamp <= $now) {
                continue;
            }
            $overrides[$feature] = (int)($row['allowed'] ?? 0) === 1;
        }

        return self::$overrideCache[$userId] = $overrides;
    }

    /**
     * @param array<string,string> $overrideStates Values: inherit, allow, deny.
     */
    public static function updateUserAccess(
        PDO $pdo,
        int $userId,
        string $plan,
        array $overrideStates,
        string $note = '',
        ?int $actorUserId = null,
        string $actorContext = 'system'
    ): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }
        $plan = self::normalizePlan($plan);
        $managedFeatures = array_keys(self::overridableFeatures());
        $now = date('c');

        Database::withBusyRetry(function () use ($pdo, $userId, $plan, $overrideStates, $note, $managedFeatures, $now, $actorUserId, $actorContext): void {
            Database::beginWriteTransaction($pdo);
            try {
                $beforeUser = $pdo->prepare('SELECT plan_code FROM users WHERE id = :id');
                $beforeUser->execute(['id' => $userId]);
                $beforePlan = $beforeUser->fetchColumn();
                if ($beforePlan === false) {
                    throw new RuntimeException('User not found.');
                }
                $beforeOverrides = self::loadStoredOverrideStates($pdo, $userId, $managedFeatures);

                $update = $pdo->prepare('UPDATE users SET plan_code = :plan_code, updated_at = :updated_at WHERE id = :id');
                $update->execute([
                    'plan_code' => $plan,
                    'updated_at' => $now,
                    'id' => $userId,
                ]);
                if ($update->rowCount() < 1) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
                    $exists->execute(['id' => $userId]);
                    if ((int)$exists->fetchColumn() < 1) {
                        throw new RuntimeException('User not found.');
                    }
                }

                $placeholders = implode(',', array_fill(0, count($managedFeatures), '?'));
                $delete = $pdo->prepare("DELETE FROM user_feature_overrides WHERE user_id = ? AND feature_key IN ({$placeholders})");
                $delete->execute([$userId, ...$managedFeatures]);

                $insert = $pdo->prepare('
                    INSERT INTO user_feature_overrides
                        (user_id, feature_key, allowed, expires_at, note, created_at, updated_at)
                    VALUES
                        (:user_id, :feature_key, :allowed, NULL, :note, :created_at, :updated_at)
                ');
                foreach ($managedFeatures as $feature) {
                    $state = strtolower(trim((string)($overrideStates[$feature] ?? 'inherit')));
                    if (!in_array($state, ['allow', 'deny'], true)) {
                        continue;
                    }
                    $insert->execute([
                        'user_id' => $userId,
                        'feature_key' => $feature,
                        'allowed' => $state === 'allow' ? 1 : 0,
                        'note' => substr(trim($note), 0, 255),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $afterOverrides = self::loadStoredOverrideStates($pdo, $userId, $managedFeatures);
                $audit = $pdo->prepare('INSERT INTO user_access_audit
                    (target_user_id, actor_user_id, actor_context, before_json, after_json, note, created_at)
                    VALUES (:target_user_id, :actor_user_id, :actor_context, :before_json, :after_json, :note, :created_at)');
                $audit->execute([
                    'target_user_id' => $userId,
                    'actor_user_id' => $actorUserId && $actorUserId > 0 ? $actorUserId : null,
                    'actor_context' => substr(trim($actorContext) !== '' ? trim($actorContext) : 'system', 0, 80),
                    'before_json' => json_encode([
                        'plan_code' => self::normalizePlan((string)$beforePlan),
                        'feature_overrides' => $beforeOverrides,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'after_json' => json_encode([
                        'plan_code' => $plan,
                        'feature_overrides' => $afterOverrides,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'note' => substr(trim($note), 0, 255),
                    'created_at' => $now,
                ]);

                $pdo->exec('COMMIT');
            } catch (Throwable $error) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
                throw $error;
            }
        });

        unset(self::$overrideCache[$userId]);
    }

    /** @param list<string> $managedFeatures @return array<string,string> */
    private static function loadStoredOverrideStates(PDO $pdo, int $userId, array $managedFeatures): array
    {
        $states = array_fill_keys($managedFeatures, 'inherit');
        $placeholders = implode(',', array_fill(0, count($managedFeatures), '?'));
        $stmt = $pdo->prepare("SELECT feature_key, allowed FROM user_feature_overrides
            WHERE user_id = ? AND feature_key IN ({$placeholders}) ORDER BY feature_key");
        $stmt->execute([$userId, ...$managedFeatures]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(string)$row['feature_key']] = (int)$row['allowed'] === 1 ? 'allow' : 'deny';
        }
        ksort($states);
        return $states;
    }

    private static function globallyEnabled(string $feature): bool
    {
        $flag = match ($feature) {
            self::WEBSITE_MANAGE => 'FEATURE_WEBSITE_ENABLED',
            self::SOCIAL_MANAGE => 'FEATURE_SOCIAL_ENABLED',
            self::VIDEO_MANAGE => 'FEATURE_VIDEO_ENABLED',
            default => '',
        };

        return $flag === '' || strtolower(app_env($flag, 'true')) === 'true';
    }

    /** @return list<string> */
    private static function allFeatures(): array
    {
        return [
            self::ARTWORKS_MANAGE,
            self::MOCKUPS_GENERATE,
            self::MOCKUPS_LAB,
            self::WEBSITE_MANAGE,
            self::SOCIAL_MANAGE,
            self::VIDEO_MANAGE,
            self::ADMIN_USERS,
            self::ADMIN_SYSTEM,
            self::ADMIN_SCENE_LIBRARY,
            self::ADMIN_CAMERA_LIBRARY,
        ];
    }
}
