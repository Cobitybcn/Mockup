<?php
$footerDescription = site_copy('global.tagline');
$currentLanguage = artist_site_language();
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'VisualArtist',
    'name' => $artistName ?? $site['name'],
    'url' => artist_site_url_with_language((string)$site['url'] . '/', $currentLanguage),
    'description' => $footerDescription,
    'inLanguage' => $currentLanguage,
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
            <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" data-metric-event="<?= $label === 'Saatchi Art' ? 'saatchi_click' : 'social_outbound' ?>" data-metric-entity="footer" data-metric-detail="<?= e($label) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</footer>
<?= json_ld($schema) ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset_version_url('assets/js/catalog.js')) ?>"></script>
<script>
(function () {
    var endpoint = <?= json_encode(url_for('site-event'), JSON_UNESCAPED_SLASHES) ?>;
    var locale = document.documentElement.lang || '';
    function send(type, entity, slug, detail) {
        try {
            var payload = JSON.stringify({
                type: type,
                entity: entity || '',
                slug: slug || '',
                locale: locale,
                referrer: document.referrer || '',
                detail: detail || ''
            });
            if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
            } else {
                fetch(endpoint, { method: 'POST', body: payload, keepalive: true });
            }
        } catch (e) { /* metrics must never break the page */ }
        if (typeof window.gtag === 'function') {
            window.gtag('event', type, { entity: entity || '', slug: slug || '', detail: detail || '' });
        }
    }
    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest ? event.target.closest('[data-metric-event]') : null;
        if (!target) return;
        send(
            target.getAttribute('data-metric-event'),
            target.getAttribute('data-metric-entity'),
            target.getAttribute('data-metric-slug'),
            target.getAttribute('data-metric-detail')
        );
    }, true);
    var section = (document.body.className || '').split(' ')[0] || 'page';
    send('view', section, window.location.pathname, '');
})();
</script>
</body>
</html>
