<?php
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'VisualArtist',
    'name' => $artistName ?? $site['name'],
    'url' => $site['url'],
    'description' => $profile['short_bio'] ?: $site['description'],
    'sameAs' => array_values($site['social']),
];
?>
</main>
<footer class="site-footer">
    <div>
        <strong><?= e($artistName ?? 'Artist') ?></strong>
        <p><?= e(!empty($profile['short_bio']) ? mb_substr((string)$profile['short_bio'], 0, 120) . '...' : 'Original paintings and artwork catalog.') ?></p>
    </div>
    <div class="footer-links">
        <a href="<?= e(url_for('paintings')) ?>">Catalog</a>
        <a href="<?= e(url_for('sold-works')) ?>">Constellations</a>
        <a href="<?= e(url_for('artist-statement')) ?>">Artist statement</a>
        <a href="<?= e(url_for('contact')) ?>">Contact</a>
        <a href="<?= e(url_for('privacy-policy')) ?>">Privacy Policy</a>
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
