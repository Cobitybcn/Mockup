<?php
declare(strict_types=1);

/**
 * Traduccion de la interfaz de platform/. La interfaz sigue al idioma de
 * interfaz elegido por el artista (LanguagePolicy); sin sesion o sin politica
 * explicita, cae a ingles, igual que artist-site.
 *
 * Patron deliberadamente liviano (par de strings en el sitio de uso, sin
 * catalogo separado): platform/ tiene mas de 160 paginas con texto disperso
 * dentro del HTML, y mantener un catalogo de claves aparte multiplicaria el
 * riesgo de desincronizacion. Es el mismo patron que artist-site usa como
 * mecanismo dominante via site_t().
 */
if (!function_exists('t')) {
    /**
     * Atajo global para Translator::t(), mismo estilo que el helper h() ya
     * presente en cada pagina. Uso: t('English copy', 'Copia en español').
     */
    function t(string $english, string $spanish): string
    {
        return Translator::t($english, $spanish);
    }
}

final class Translator
{
    private static ?string $resolvedLocale = null;

    /**
     * @param array<string,mixed>|null $user Usuario ya resuelto (Auth::user()),
     *   para evitar una consulta redundante si el llamador ya lo tiene.
     */
    public static function locale(?array $user = null): string
    {
        if (self::$resolvedLocale !== null) {
            return self::$resolvedLocale;
        }

        $user ??= Auth::user();
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return self::$resolvedLocale = 'en';
        }

        $policy = LanguagePolicy::forUser($userId);
        $locale = $policy['interface_locale'];

        return self::$resolvedLocale = LanguagePolicy::isSupportedLocale($locale) ? $locale : 'en';
    }

    /** Para pruebas y para refrescar tras cambiar la politica en el mismo request. */
    public static function forgetResolvedLocale(): void
    {
        self::$resolvedLocale = null;
    }

    /**
     * @param array<string,mixed>|null $user Ver locale().
     */
    public static function t(string $english, string $spanish, ?array $user = null): string
    {
        return self::locale($user) === 'es' ? $spanish : $english;
    }

    /**
     * Para paginas publicas sin sesion que muestran contenido ya publicado en
     * un idioma propio (por ejemplo, la pagina publica de una obra): el idioma
     * de esa vista sigue al registro publicado, no a la interfaz del artista.
     */
    public static function forLocale(string $locale, string $english, string $spanish): string
    {
        return self::normalizeForDisplay($locale) === 'es' ? $spanish : $english;
    }

    private static function normalizeForDisplay(string $locale): string
    {
        $locale = strtolower(trim($locale));
        return LanguagePolicy::isSupportedLocale($locale) ? $locale : 'en';
    }
}
