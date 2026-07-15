<section class="page-hero">
    <p class="eyebrow">Concept clusters</p>
    <h1>Painting Series</h1>
    <p>Series pages connect Maurizio Valch"s current language: structural metaphysical painting, strata, fault lines, ground frequency, monoliths, horizons, and silence.</p>
</section>
<section class="section">
    <div class="series-grid series-grid--primary">
        <?php foreach ($currentSeriesSlugs as $slug): ?>
            <?php if (!isset($series[$slug])) continue; ?>
            <?php
            $item = $series[$slug];
            $seriesImage = series_representative_image($slug, $item, $artworks);
            ?>
            <a class="series-card" href="<?= e(url_for("series/" . $slug)) ?>">
                <?php if ($seriesImage): ?>
                    <img src="<?= e(asset_url($seriesImage)) ?>" alt="<?= e($item["title"] . " series thumbnail") ?>">
                <?php endif; ?>
                <span><?= e($item["title"]) ?></span>
                <p><?= e($item["description"]) ?></p>
            </a>
        <?php endforeach; ?>
        <article class="series-card series-card--earlier">
            <span>Earlier Works</span>
            <?php foreach ($earlierWorks as $slug => $item): ?>
                <?php $seriesImage = series_representative_image($slug, $item, $artworks); ?>
                <a class="earlier-work" href="<?= e(url_for("series/" . $slug)) ?>">
                    <?php if ($seriesImage): ?>
                        <img src="<?= e(asset_url($seriesImage)) ?>" alt="<?= e($item["title"] . " series thumbnail") ?>">
                    <?php endif; ?>
                    <div>
                        <strong><?= e($item["title"]) ?></strong>
                        <p><?= e($item["description"]) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </article>
    </div>
</section>