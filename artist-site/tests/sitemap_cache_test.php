<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/ArtistSitemapCache.php';

$namespace = 'test-' . bin2hex(random_bytes(8));
$cache = new ArtistSitemapCache($namespace, 60, 3600);
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
    . "  <url><loc>https://example.com/en/</loc></url>\n"
    . "</urlset>";

if ($cache->fresh() !== null) {
    fwrite(STDERR, "FAIL: a new sitemap cache must be empty.\n");
    exit(1);
}
if (!$cache->store($xml) || $cache->fresh() !== $xml || $cache->stale() !== $xml) {
    fwrite(STDERR, "FAIL: a valid sitemap is not stored and recovered.\n");
    exit(1);
}
if ($cache->store('<html>not a sitemap</html>')) {
    fwrite(STDERR, "FAIL: invalid sitemap content entered the cache.\n");
    exit(1);
}

echo "PASS: sitemap cache stores only complete XML documents.\n";
