<section class="section constellation-section" aria-label="World map of placed works">
    <div class="constellation-real-map" aria-label="Night world map of placed works" data-constellation-leaflet data-map-items="<?= e(json_encode($mapItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
        <div class="constellation-real-map__canvas" data-map-canvas aria-label="Placed works on the map"></div>
        <aside class="constellation-real-map__card" data-constellation-card>
            <?php if (!empty($mapItems[0]["image"])): ?>
                <img data-constellation-image src="<?= e($mapItems[0]["image"]) ?>" alt="">
            <?php else: ?>
                <img data-constellation-image src="" alt="" hidden>
            <?php endif; ?>
            <div class="constellation-real-map__text">
                <strong data-constellation-title><?= e($mapItems[0]["title"] ?? "Select a work") ?></strong>
                <span data-constellation-place><?= e(trim(($mapItems[0]["postal_code"] ?? "") . (($mapItems[0]["postal_code"] ?? "") && ($mapItems[0]["country"] ?? "") ? " / " : "") . ($mapItems[0]["country"] ?? ""))) ?></span>
            </div>
            <a data-constellation-link href="<?= e($mapItems[0]["url"] ?? url_for("paintings")) ?>">Open ficha</a>
        </aside>
        <?php if (!$mapItems): ?>
            <div class="constellation-map__empty">
                <strong>Placed works pending location</strong>
                <span>These sold works need a postal zone or coordinates before they can be placed on the map.</span>
                <?php if ($pendingArtworks): ?>
                    <div class="constellation-pending">
                        <?php foreach ($pendingArtworks as $slug => $artwork): ?>
                            <a href="<?= e(url_for("paintings/" . $slug)) ?>">
                                <?php if (!empty($artwork["image"])): ?>
                                    <img src="<?= e(asset_url($artwork["image"])) ?>" alt="<?= e($artwork["title"] . " sold work pending location") ?>">
                                <?php endif; ?>
                                <span><?= e($artwork["title"]) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($locations && $pendingArtworks): ?>
            <div class="constellation-map__pending-strip">
                <strong>Pending placement</strong>
                <div class="constellation-pending">
                    <?php foreach ($pendingArtworks as $slug => $artwork): ?>
                        <a href="<?= e(url_for("paintings/" . $slug)) ?>">
                            <?php if (!empty($artwork["image"])): ?>
                                <img src="<?= e(asset_url($artwork["image"])) ?>" alt="<?= e($artwork["title"] . " sold work pending location") ?>">
                            <?php endif; ?>
                            <span><?= e($artwork["title"]) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <p class="privacy-note privacy-note--map">Locations are shown by postal zone only. Collector names, addresses, and private details are never published.</p>
</section>