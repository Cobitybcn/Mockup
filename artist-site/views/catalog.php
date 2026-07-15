<section class="page-hero <?= $status === "sold" ? "page-hero--compact" : "" ?>">
    <p class="eyebrow">Artwork catalog</p>
    <h1><?= e($title) ?></h1>
    <p><?= e($intro) ?></p>
</section>
<?php if ($status === "sold"): ?>
    <?php render_constellation_map($soldLocations, $artworks); ?>
<?php endif; ?>
<section class="section catalog-grid-section">
    <div class="art-grid">
        <?php foreach ($items as $slug => $artwork) render_artwork_card($slug, $artwork, $series); ?>
    </div>
    <div class="catalog-tools" data-catalog-tools>
        <div class="catalog-search">
            <label for="catalog-search-input">Search the catalog</label>
            <input id="catalog-search-input" data-catalog-search type="search" placeholder="Title, status, series, concept, size">
        </div>
        <div class="filter-row" aria-label="Catalog filters">
            <?php if (!$status): ?>
                <button type="button" data-filter-status="all">All</button>
                <button type="button" data-filter-status="available">In Studio</button>
                <button type="button" data-filter-status="sold">Placed</button>
            <?php endif; ?>
            <?php foreach ($series as $slug => $item): ?>
                <button type="button" data-filter-series="<?= e($slug) ?>"><?= e($item["title"]) ?></button>
            <?php endforeach; ?>
        </div>
        <p class="catalog-count" data-catalog-count></p>
    </div>
</section>
<?php if ($status === "sold" && $soldRecords): ?>
    <section class="section">
        <div class="section-head">
            <div>
                <p class="eyebrow">Provenance map</p>
                <h2>Constellations of Works</h2>
            </div>
        </div>
        <div class="sold-table" role="table" aria-label="Constellations of works">
            <div class="sold-table__row sold-table__head" role="row">
                <span>Artwork</span>
                <span>Size</span>
                <span>Provenance</span>
                <span>Status</span>
                <span>Concept cluster</span>
            </div>
            <?php foreach ($soldRecords as $record): ?>
                <a class="sold-table__row" role="row" href="<?= e($record["url"]) ?>" target="_blank" rel="noopener">
                    <span><?= e($record["title"]) ?>, <?= e($record["year"]) ?></span>
                    <span><?= e($record["dimensions"]) ?></span>
                    <span><?= e($record["platform"]) ?></span>
                    <span><?= e($record["public_price"]) ?></span>
                    <span><?= e($record["cluster"]) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>