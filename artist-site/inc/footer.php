<?php
$footerDescription = artist_site_language() === 'es'
    ? trim((string)($profile['short_bio'] ?? ''))
    : trim((string)($site['description'] ?? ''));
$footerDescription = $footerDescription !== ''
    ? mb_substr($footerDescription, 0, 120) . (mb_strlen($footerDescription) > 120 ? '...' : '')
    : site_t('Original paintings and artwork catalogue.', 'Pinturas originales y catálogo de obras.');
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'VisualArtist',
    'name' => $artistName ?? $site['name'],
    'url' => $site['url'],
    'description' => $footerDescription,
    'sameAs' => array_values($site['social']),
];
?>
</main>
<footer class="site-footer">
    <div>
        <strong><?= e($artistName ?? 'Artist') ?></strong>
        <p><?= e($footerDescription) ?></p>
    </div>
    <div class="footer-links">
        <a href="<?= e(url_for('paintings')) ?>"><?= e(site_t('Catalog', 'Catálogo')) ?></a>
        <a href="<?= e(url_for('sold-works')) ?>"><?= e(site_t('Constellations', 'Constelaciones')) ?></a>
        <a href="<?= e(url_for('artist-statement')) ?>"><?= e(site_t('Artist statement', 'Declaración artística')) ?></a>
        <a href="<?= e(url_for('contact')) ?>"><?= e(site_t('Contact', 'Contacto')) ?></a>
        <a href="<?= e(url_for('privacy-policy')) ?>"><?= e(site_t('Privacy Policy', 'Política de privacidad')) ?></a>
    </div>
    <div class="footer-links footer-links--social" aria-label="Social and marketplace profiles">
        <?php foreach ($site['social'] as $label => $url): ?>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</footer>
<?= json_ld($schema) ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset_version_url('assets/js/catalog.js')) ?>"></script>
</body>
</html>
