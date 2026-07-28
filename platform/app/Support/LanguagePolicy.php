<?php
declare(strict_types=1);

/**
 * Politica linguistica de cada artista.
 *
 * Separa tres conceptos que el sistema trataba como uno solo:
 *  - idioma de trabajo: en el que el artista analiza, genera y revisa;
 *  - idioma de interfaz: acompana al de trabajo salvo eleccion explicita;
 *  - idiomas de publicacion: en los que decide publicar.
 *
 * El ingles deja de ser obligatorio: solo se adapta contenido cuando el artista
 * publica en un idioma distinto del suyo.
 */
final class LanguagePolicy
{
    /** Idiomas habilitados durante la beta. Extender aqui agrega soporte. */
    private const SUPPORTED_LOCALES = [
        'es' => 'Espanol',
        'en' => 'English',
    ];

    /**
     * Default para cualquier cuenta que todavia no registro su politica:
     * ingles como idioma de trabajo y de publicacion. Los artistas que
     * trabajan en otro idioma (como Maurizio, espanol/ingles) lo definen
     * explicitamente en su cuenta; esto nunca debe asumir espanol por defecto.
     */
    private const COMPATIBILITY_WORKING_LOCALE = 'en';
    private const COMPATIBILITY_PUBLICATION_LOCALES = ['en'];

    /** @var array<int,array{working_locale:string,interface_locale:string,publication_locales:list<string>,is_explicit:bool}> */
    private static array $cache = [];

    /** @return array<string,string> */
    public static function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    public static function isSupportedLocale(string $locale): bool
    {
        return array_key_exists(self::normalizeLocale($locale), self::SUPPORTED_LOCALES);
    }

    public static function localeLabel(string $locale): string
    {
        return self::SUPPORTED_LOCALES[self::normalizeLocale($locale)] ?? $locale;
    }

    public static function normalizeLocale(string $locale): string
    {
        return strtolower(trim($locale));
    }

    /**
     * @param list<string> $locales
     * @return list<string>
     */
    public static function normalizePublicationLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $candidate = self::normalizeLocale((string)$locale);
            if ($candidate === '' || !array_key_exists($candidate, self::SUPPORTED_LOCALES)) {
                continue;
            }
            if (!in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    /**
     * `pdo` permite a los motores en segundo plano y a las pruebas resolver la
     * politica sobre su propia conexion (patron de FeatureAccess::allowsUserId).
     * Con conexion explicita no se usa cache: la cache global solo es valida
     * para la conexion global.
     *
     * @return array{working_locale:string,interface_locale:string,publication_locales:list<string>,is_explicit:bool}
     */
    public static function forUser(int $userId, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return self::compatibilityPolicy();
        }
        $useCache = $pdo === null;
        if ($useCache && array_key_exists($userId, self::$cache)) {
            return self::$cache[$userId];
        }

        try {
            $stmt = ($pdo ?? Database::connection())->prepare('
                SELECT working_locale, publication_locales_json, interface_locale
                FROM user_language_policy
                WHERE user_id = :user_id
                LIMIT 1
            ');
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch();
        } catch (PDOException $error) {
            // Una base sin la tabla de politica (instalacion anterior a la
            // migracion, o fixture minimo) conserva el comportamiento historico
            // del estudio en lugar de romper la generacion.
            return self::compatibilityPolicy();
        }
        if (!is_array($row)) {
            $fallback = self::compatibilityPolicy();
            if ($useCache) {
                self::$cache[$userId] = $fallback;
            }
            return $fallback;
        }

        $working = self::normalizeLocale((string)($row['working_locale'] ?? ''));
        if (!array_key_exists($working, self::SUPPORTED_LOCALES)) {
            $working = self::COMPATIBILITY_WORKING_LOCALE;
        }

        $decoded = json_decode((string)($row['publication_locales_json'] ?? ''), true);
        $publication = self::normalizePublicationLocales(is_array($decoded) ? $decoded : []);
        if ($publication === []) {
            $publication = [$working];
        }

        $interface = self::normalizeLocale((string)($row['interface_locale'] ?? ''));
        if (!array_key_exists($interface, self::SUPPORTED_LOCALES)) {
            $interface = $working;
        }

        $policy = [
            'working_locale' => $working,
            'interface_locale' => $interface,
            'publication_locales' => $publication,
            'is_explicit' => true,
        ];
        if ($useCache) {
            self::$cache[$userId] = $policy;
        }
        return $policy;
    }

    /**
     * Registra la eleccion del artista. `interfaceLocale` vacio significa que la
     * interfaz acompana al idioma de trabajo.
     *
     * @param list<string> $publicationLocales
     */
    public static function save(
        int $userId,
        string $workingLocale,
        array $publicationLocales,
        string $interfaceLocale = ''
    ): void {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $working = self::normalizeLocale($workingLocale);
        if (!array_key_exists($working, self::SUPPORTED_LOCALES)) {
            throw new InvalidArgumentException('Unsupported working language.');
        }

        $publication = self::normalizePublicationLocales($publicationLocales);
        if ($publication === []) {
            throw new InvalidArgumentException('At least one publication language is required.');
        }

        $interface = self::normalizeLocale($interfaceLocale);
        if ($interface !== '' && !array_key_exists($interface, self::SUPPORTED_LOCALES)) {
            throw new InvalidArgumentException('Unsupported interface language.');
        }

        $now = date(DATE_ATOM);
        $pdo = Database::connection();
        $sql = Database::isMysql()
            ? 'INSERT INTO user_language_policy
                   (user_id, working_locale, publication_locales_json, interface_locale, created_at, updated_at)
               VALUES (:user_id, :working_locale, :publication_locales_json, :interface_locale, :created_at, :updated_at)
               ON DUPLICATE KEY UPDATE
                   working_locale = VALUES(working_locale),
                   publication_locales_json = VALUES(publication_locales_json),
                   interface_locale = VALUES(interface_locale),
                   updated_at = VALUES(updated_at)'
            : 'INSERT INTO user_language_policy
                   (user_id, working_locale, publication_locales_json, interface_locale, created_at, updated_at)
               VALUES (:user_id, :working_locale, :publication_locales_json, :interface_locale, :created_at, :updated_at)
               ON CONFLICT(user_id) DO UPDATE SET
                   working_locale = excluded.working_locale,
                   publication_locales_json = excluded.publication_locales_json,
                   interface_locale = excluded.interface_locale,
                   updated_at = excluded.updated_at';

        $pdo->prepare($sql)->execute([
            'user_id' => $userId,
            'working_locale' => $working,
            'publication_locales_json' => json_encode($publication, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'interface_locale' => $interface,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        unset(self::$cache[$userId]);
    }

    /**
     * Idiomas de publicacion que requieren adaptacion desde el idioma de trabajo.
     * Publicar unicamente en el idioma de trabajo no genera ninguna adaptacion.
     *
     * @param array{working_locale:string,publication_locales:list<string>} $policy
     * @return list<string>
     */
    public static function adaptationTargets(array $policy): array
    {
        $working = self::normalizeLocale((string)($policy['working_locale'] ?? ''));
        $targets = [];
        foreach (self::normalizePublicationLocales((array)($policy['publication_locales'] ?? [])) as $locale) {
            if ($locale !== $working) {
                $targets[] = $locale;
            }
        }

        return $targets;
    }

    /** @param array{working_locale:string,publication_locales:list<string>} $policy */
    public static function requiresAdaptation(array $policy): bool
    {
        return self::adaptationTargets($policy) !== [];
    }

    public static function forgetCachedPolicy(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    /** @return array{working_locale:string,interface_locale:string,publication_locales:list<string>,is_explicit:bool} */
    private static function compatibilityPolicy(): array
    {
        return [
            'working_locale' => self::COMPATIBILITY_WORKING_LOCALE,
            'interface_locale' => self::COMPATIBILITY_WORKING_LOCALE,
            'publication_locales' => self::COMPATIBILITY_PUBLICATION_LOCALES,
            'is_explicit' => false,
        ];
    }
}
