<?php
declare(strict_types=1);

final class ArtistSitemapCache
{
    private string $path;

    public function __construct(
        string $namespace,
        private readonly int $freshSeconds = 600,
        private readonly int $staleSeconds = 86400,
        ?string $cacheDirectory = null
    ) {
        $directory = rtrim($cacheDirectory ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $this->path = $directory . DIRECTORY_SEPARATOR
            . 'artist-site-sitemap-' . hash('sha256', $namespace) . '.xml';
    }

    public static function forRequest(): self
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'artist-site')));
        return new self($host);
    }

    public function fresh(): ?string
    {
        return $this->read($this->freshSeconds);
    }

    public function stale(): ?string
    {
        return $this->read($this->staleSeconds);
    }

    public function store(string $xml): bool
    {
        if (!$this->isValid($xml)) return false;
        return file_put_contents($this->path, $xml, LOCK_EX) !== false;
    }

    public static function emit(string $xml, string $state): never
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=300, stale-if-error=86400');
        header('X-Sitemap-Cache: ' . strtoupper($state));
        echo $xml;
        exit;
    }

    private function read(int $maximumAge): ?string
    {
        if ($maximumAge < 0 || !is_file($this->path)) return null;
        $modifiedAt = filemtime($this->path);
        if ($modifiedAt === false || (time() - $modifiedAt) > $maximumAge) return null;
        $xml = file_get_contents($this->path);
        return is_string($xml) && $this->isValid($xml) ? $xml : null;
    }

    private function isValid(string $xml): bool
    {
        return str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>')
            && str_contains($xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
            && str_ends_with(trim($xml), '</urlset>');
    }
}
