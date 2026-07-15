<section class="artwork-detail">
    <div class="artwork-detail__image">
        <img src="<?= e(asset_url($artwork["image"])) ?>" alt="<?= e($artwork["title"] . " original painting by Maurizio Valch") ?>">
        <?php if (!empty($detailImages)): ?>
            <div class="mockup-gallery" aria-label="<?= e($artwork["title"] . " detail photographs") ?>">
                <?php foreach ($detailImages as $detail): ?>
                    <?php if (!empty($detail["image"])): ?>
                        <img src="<?= e(asset_url($detail["image"])) ?>" alt="<?= e($detail["alt"] ?? $artwork["title"] . " detail") ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($artwork["mockups"])): ?>
            <div class="mockup-gallery" aria-label="<?= e($artwork["title"] . " mockups") ?>">
                <?php foreach ($artwork["mockups"] as $mockup): ?>
                    <img src="<?= e(asset_url($mockup["image"])) ?>" alt="<?= e($mockup["alt"] ?? $artwork["title"] . " contextual mockup") ?>">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="artwork-detail__content">
        <p class="eyebrow"><?= e($status) ?> / <?= e($seriesTitle) ?></p>
        <h1><?= e($artwork["title"]) ?></h1>
        <p class="lead"><?= e($artwork["summary"]) ?></p>
        <dl class="specs">
            <div><dt>Year</dt><dd><?= e($artwork["year"]) ?></dd></div>
            <div><dt>Medium</dt><dd><?= e($artwork["medium"]) ?></dd></div>
            <div>
                <dt>Size</dt>
                <dd>
                    <span data-size-metric><?= e($artwork["dimensions_cm"]) ?></span>
                    <span data-size-imperial hidden><?= e($artwork["dimensions_in"]) ?></span>
                </dd>
            </div>
            <div><dt>Orientation</dt><dd><?= e($artwork["orientation"]) ?></dd></div>
            <div><dt>Status</dt><dd><?= e($status) ?></dd></div>
            <?php if ($artwork["status"] === "available" || $artwork["status"] === "for sale"): ?>
                <div><dt>Price</dt><dd><?= e(format_artwork_price($artwork["price"] ?? '', $artwork["currency"] ?? 'EUR')) ?></dd></div>
            <?php else: ?>
                <div><dt>Placement trace</dt><dd><?= e($artwork["sale_platform"] ?? "Private collection") ?></dd></div>
            <?php endif; ?>
        </dl>
        <div class="prose">
            <h2>Conceptual Note</h2>
            <p><?= e($artwork["concept"]) ?></p>
            <h2>Studio Information</h2>
            <p><?= e($artwork["commercial_note"]) ?></p>
        </div>
        <div class="actions" style="display: flex; flex-direction: column; gap: 10px;">
            <?php if ($artwork["status"] === "available" || $artwork["status"] === "for sale"): ?>
                <?php 
                    $checkoutUrl = !empty($artwork["purchase_url"]) ? $artwork["purchase_url"] : url_for("artwork.php?slug=" . $slug);
                ?>
                <a class="button" href="<?= e($checkoutUrl) ?>" <?= !empty($artwork["purchase_url"]) ? 'target="_blank" rel="noopener"' : '' ?> style="justify-content: center; width: 100%;">Buy Artwork</a>
                <a class="button button--quiet" href="<?= e(url_for("contact")) ?>?artwork=<?= e($slug) ?>" style="justify-content: center; width: 100%;">Inquire about this work</a>
            <?php else: ?>
                <a class="button" href="<?= e(url_for("paintings")) ?>" style="justify-content: center; width: 100%;">View Works in Studio</a>
            <?php endif; ?>
            <?php if ($seriesSlug && isset($series[$seriesSlug])): ?>
                <a class="button button--quiet" href="<?= e(url_for("series/" . $seriesSlug)) ?>" style="justify-content: center; width: 100%;">View Series</a>
            <?php endif; ?>
        </div>
    </div>
</section>