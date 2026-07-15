<section class="page-hero">
    <p class="eyebrow">Series</p>
    <h1><?= e($item["title"]) ?></h1>
    <p><?= e($item["description"]) ?></p>
</section>
<?php if ($seriesImage): ?>
    <section class="section series-hero-image">
        <img src="<?= e(asset_url($seriesImage)) ?>" alt="<?= e($item["title"] . " representative image") ?>">
    </section>
<?php endif; ?>
<section class="section section--split">
    <div>
        <h2>Search Language</h2>
    </div>
    <div class="keyword-list">
        <?php foreach ($item["keywords"] as $keyword): ?>
            <span><?= e($keyword) ?></span>
        <?php endforeach; ?>
    </div>
</section>
<section class="section">
    <div class="section-head">
        <h2>Works in this series</h2>
        <a href="<?= e(url_for("paintings")) ?>">Full catalog</a>
    </div>
    <div class="art-grid">
        <?php foreach ($items as $artworkSlug => $artwork) render_artwork_card($artworkSlug, $artwork, $series); ?>
    </div>
</section>