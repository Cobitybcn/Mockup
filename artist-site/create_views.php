<?php
$views = [
    "home.php" => <<<'EOD'
<section class="hero">
    <div class="hero__media" data-hero-slider>
        <div class="hero__slides">
            <?php foreach ($heroSlides as $index => $slide): ?>
                <img class="hero__slide" src="<?= e(asset_url($slide["image"])) ?>" alt="<?= e($slide["title"] . " root artwork image") ?>" data-hero-slide <?= $index === 0 ? "data-active=\"true\"" : "" ?>>
            <?php endforeach; ?>
        </div>
        <?php if (count($heroSlides) > 1): ?>
            <button class="hero__arrow hero__arrow--prev" type="button" data-hero-prev aria-label="Previous artwork image">‹</button>
            <button class="hero__arrow hero__arrow--next" type="button" data-hero-next aria-label="Next artwork image">›</button>
        <?php endif; ?>
    </div>
    <div class="hero__content">
        <p class="eyebrow">Abstract Painting / Territory and Thought</p>
        <h1>Strata, fault lines and ground frequency</h1>
        <p class="lead">A catalog of works organized by scale, status, series, monoliths, horizons, tectonic tension, and the sedimented time of territory.</p>
        <form class="hero-search" action="<?= e(url_for("paintings")) ?>" method="get" role="search">
            <label class="sr-only" for="hero-search-input">Search artwork catalog</label>
            <input id="hero-search-input" name="q" type="search" placeholder="Try: strata, fault lines, monolith, 120 cm">
            <button type="submit">Search Catalog</button>
        </form>
        <div class="actions">
            <a class="button" href="<?= e(url_for("paintings")) ?>">Open Catalog</a>
            <a class="button button--quiet" href="<?= e(url_for("sold-works")) ?>">View Constellations</a>
            <a class="button button--quiet" href="<?= e(url_for("artist-statement")) ?>">Read Artist Statement</a>
        </div>
    </div>
</section>

<section class="section search-paths">
    <div class="path-card">
        <p class="eyebrow">Catalog</p>
        <h2>Root images</h2>
        <p>The catalog begins with one essential image per work. Detail pages hold the complete visual context and mockup sets.</p>
        <a href="<?= e(url_for("paintings")) ?>">Enter catalog</a>
    </div>
    <div class="path-card">
        <p class="eyebrow">Archive</p>
        <h2>Constellations</h2>
        <p>A map of works that have left the studio, preserving provenance context without reducing the work to transaction.</p>
        <a href="<?= e(url_for("sold-works")) ?>">View constellations</a>
    </div>
    <div class="path-card">
        <p class="eyebrow">Explore</p>
        <h2>Series and concepts</h2>
        <p>Browse by strata, fault lines, ground frequency, monoliths, horizons, and structural silence.</p>
        <a href="<?= e(url_for("series")) ?>">Explore series</a>
    </div>
</section>

<section class="section section--split">
    <div>
        <p class="eyebrow">For collectors, architects and curators</p>
        <h2>Structural metaphysical painting for slow perception</h2>
    </div>
    <div class="prose">
        <p>Valch"s work is built for slow perception: controlled mass, spatial silence, and structural clarity. The paintings function as contemplative presences rather than decorative abstractions.</p>
        <p>The catalog is not arranged as a store. It is a visual index: root images, contextual mockups, conceptual notes, and a quiet distinction between works in the studio and works already placed.</p>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <p class="eyebrow">In studio</p>
        <h2>Selected Works</h2>
        <a href="<?= e(url_for("paintings")) ?>?status=available">Filter available works</a>
    </div>
    <div class="art-grid">
        <?php foreach ($available as $slug => $artwork) render_artwork_card($slug, $artwork, $series); ?>
    </div>
</section>
EOD
,
    "catalog.php" => <<<'EOD'
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
            <button type="button" data-filter-status="all">All</button>
            <button type="button" data-filter-status="available">In Studio</button>
            <button type="button" data-filter-status="sold">Placed</button>
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
                <span>Platform</span>
                <span>Public trace</span>
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
EOD
,
    "constellation-map.php" => <<<'EOD'
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
EOD
,
    "artwork-detail.php" => <<<'EOD'
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
            <?php if ($artwork["status"] === "available"): ?>
                <div><dt>Studio status</dt><dd><?= e($artwork["price"]) ?></dd></div>
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
        <div class="actions">
            <?php if ($artwork["status"] === "available"): ?>
                <a class="button" href="<?= e(url_for("contact")) ?>?artwork=<?= e($slug) ?>">Request Documentation</a>
            <?php else: ?>
                <a class="button" href="<?= e(url_for("paintings")) ?>?status=available">View Works in Studio</a>
            <?php endif; ?>
            <?php if ($seriesSlug && isset($series[$seriesSlug])): ?>
                <a class="button button--quiet" href="<?= e(url_for("series/" . $seriesSlug)) ?>">View Series</a>
            <?php endif; ?>
        </div>
    </div>
</section>
EOD
];

foreach ($views as $name => $content) {
    file_put_contents("views/$name", $content);
}
?>
<?php
$views = [
    "series-index.php" => <<<'EOD'
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
EOD
,
    "series-detail.php" => <<<'EOD'
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
EOD
,
    "artist.php" => <<<'EOD'
<section class="page-hero artist-page-hero">
    <p class="eyebrow"><?= e($profile["tagline"]) ?></p>
    <h1><?= e($profile["name"]) ?></h1>
    <p><?= nl2br(e($profile["intro"])) ?></p>
</section>
<section class="section artist-profile-block<?= $portraitCanRender ? "" : " artist-profile-block--text-only" ?>">
    <?php if ($portraitCanRender): ?>
        <figure class="artist-profile-block__portrait">
            <img src="<?= e(asset_url($portraitImage)) ?>" alt="<?= e($portrait["alt"] ?: $profile["name"] . " portrait") ?>">
            <?php if (!empty($portrait["caption"])): ?>
                <figcaption><?= e($portrait["caption"]) ?></figcaption>
            <?php endif; ?>
        </figure>
        <?php endif; ?>
    <div class="prose">
        <p><?= nl2br(e($profile["biography"])) ?></p>
    </div>
</section>
<section class="section artist-statement-excerpt">
    <div>
        <p class="eyebrow">Artist statement</p>
        <h2>Statement Excerpt</h2>
    </div>
    <div class="prose">
        <p><?= nl2br(e($profile["statement_excerpt"])) ?></p>
        <?php if (!empty($profile["statement_button_text"]) && !empty($profile["statement_button_url"])): ?>
            <a class="button button--quiet" href="<?= e(url_for($profile["statement_button_url"])) ?>"><?= e($profile["statement_button_text"]) ?></a>
        <?php endif; ?>
    </div>
</section>
<section class="section">
    <div class="section-head section-head--simple">
        <h2>Genealogy of the Work</h2>
    </div>
    <div class="artist-genealogy">
        <?php foreach ($profile["genealogy"] as $item): ?>
            <?php $genealogyUrl = trim((string) ($item["url"] ?? "")); ?>
            <?php if ($genealogyUrl !== ""): ?>
                <a href="<?= e(url_for($genealogyUrl)) ?>">
                    <span><?= e($item["title"] ?? "") ?></span>
                    <p><?= e($item["description"] ?? "") ?></p>
                </a>
            <?php else: ?>
                <article>
                    <span><?= e($item["title"] ?? "") ?></span>
                    <p><?= e($item["description"] ?? "") ?></p>
                </article>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php if (!empty($profile["studio_images"])): ?>
    <section class="section">
        <div class="section-head section-head--simple">
            <h2>Studio Images</h2>
        </div>
        <div class="artist-studio-grid">
            <?php foreach ($profile["studio_images"] as $image): ?>
                <?php if (empty($image["image"])) continue; ?>
                <figure>
                    <img src="<?= e(asset_url($image["image"])) ?>" alt="<?= e($image["alt"] ?? "Maurizio Valch studio image") ?>">
                    <?php if (!empty($image["caption"])): ?><figcaption><?= e($image["caption"]) ?></figcaption><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<section class="section artist-link-panel">
    <?php foreach ($links as $link): ?>
        <?php if (empty($link["text"]) || empty($link["url"])) continue; ?>
        <a class="button <?= str_contains($link["url"], "artist-statement") ? "" : "button--quiet" ?>" href="<?= e(url_for($link["url"])) ?>"><?= e($link["text"]) ?></a>
    <?php endforeach; ?>
</section>
EOD
];

foreach ($views as $name => $content) {
    file_put_contents("views/$name", $content);
}
?>
<?php
$views = [
    "statement.php" => <<<'EOD'
<section class="page-hero artist-page-hero artist-statement-hero">
    <p class="eyebrow">Artist statement</p>
    <h1><?= e($statement["title"] ?? "Genesis of Metaphysical Territories") ?></h1>
    <p><?= e($statement["intro"] ?? "Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth.") ?></p>
</section>
<section class="section artist-statement-body">
    <div>
        <p class="eyebrow">Artist statement</p>
        <h2><?= e($statement["title"] ?? "Genesis of Metaphysical Territories") ?></h2>
    </div>
    <div class="prose">
        <?php foreach (($statement["body"] ?? []) as $paragraph): ?>
            <p><?= e($paragraph) ?></p>
        <?php endforeach; ?>
    </div>
</section>
EOD
,
    "exhibitions.php" => <<<'EOD'
<section class="page-hero">
    <p class="eyebrow">Trust signals</p>
    <h1>Exhibitions and Collections</h1>
    <p>Selected public references, marketplace history, and collection context for collectors and curators researching Maurizio Valch.</p>
</section>
<section class="section timeline">
    <?php foreach ($items as $item): ?>
        <?php
        $title = trim((string) ($item["title"] ?? ""));
        $description = trim((string) ($item["description"] ?? ""));
        $url = trim((string) ($item["url"] ?? ""));
        if ($title === "" && $description === "") {
            continue;
        }
        $tag = $url !== "" ? "a" : "div";
        ?>
        <<?= $tag ?><?= $url !== "" ? " href=\"" . e(url_for($url)) . "\"" : "" ?>>
            <strong><?= e($title) ?></strong>
            <span><?= e($description) ?></span>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>
EOD
,
    "journal.php" => <<<'EOD'
<section class="page-hero">
    <p class="eyebrow">Studio Notes</p>
    <h1>Concepts in Architectural Abstract Painting</h1>
    <p>Editorial pages built for informational search intent and deeper reading.</p>
</section>
<section class="section article-list">
    <?php $index = 0; foreach ($journal as $slug => $post): ?>
        <?php $thumb = $post["image"] ?? ($artworkImages[$index % max(1, count($artworkImages))] ?? ""); ?>
        <article>
            <?php if ($thumb): ?>
                <a class="article-thumb" href="<?= e(url_for("studio-notes/" . $slug)) ?>">
                    <img src="<?= e(asset_url($thumb)) ?>" alt="<?= e($post["title"] . " Studio Notes thumbnail") ?>">
                </a>
            <?php endif; ?>
            <p class="eyebrow">Essay</p>
            <h2><a href="<?= e(url_for("studio-notes/" . $slug)) ?>"><?= e($post["title"]) ?></a></h2>
            <p><?= e($post["description"]) ?></p>
        </article>
    <?php $index++; ?>
    <?php endforeach; ?>
</section>
EOD
,
    "journal-post.php" => <<<'EOD'
<section class="page-hero artist-page-hero journal-post-hero__intro">
    <p class="eyebrow">Studio Notes</p>
    <h1><?= e($post["title"]) ?></h1>
    <p><?= e($post["description"]) ?></p>
</section>
<?php if ($heroImage !== ""): ?>
    <section class="section artist-profile-block journal-post-feature">
        <figure class="artist-profile-block__portrait journal-post-feature__portrait">
            <img src="<?= e(asset_url($heroImage)) ?>" alt="<?= e($post["title"] . " Studio Notes image") ?>" loading="eager">
        </figure>
        <div class="prose">
            <?php if ($blocks && !empty($blocks[0]["text"])): ?>
                <?php $firstParagraphs = preg_split("/\R{2,}/", trim($blocks[0]["text"] ?? "")) ?: []; ?>
                <?php $firstBlockRendered = true; ?>
                <?php foreach (array_slice($firstParagraphs, 0, 2) as $paragraph): ?>
                    <?php if (trim($paragraph) !== ""): ?><p><?= e(trim($paragraph)) ?></p><?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?= e($post["description"]) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<article class="section prose prose--wide">
    <?php if ($blocks): ?>
        <?php foreach ($blocks as $blockIndex => $block): ?>
            <?php if (isset($firstBlockRendered) && $firstBlockRendered && $blockIndex === 0): ?>
                <?php $allParagraphs = preg_split("/\R{2,}/", trim($block["text"] ?? "")) ?: []; ?>
                <?php foreach (array_slice($allParagraphs, 2) as $paragraph): ?>
                    <?php if (trim($paragraph) !== ""): ?><p><?= e(trim($paragraph)) ?></p><?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if (!empty($block["title"])): ?><<?= $block["level"] ?? "h2" ?>><?= e($block["title"]) ?></<?= $block["level"] ?? "h2" ?>><?php endif; ?>
                <?php if (!empty($block["text"])): ?>
                    <?php foreach (preg_split("/\R{2,}/", trim($block["text"])) as $paragraph): ?>
                        <p><?= e(trim($paragraph)) ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($block["image"])): ?>
                <figure class="journal-block-image">
                    <img src="<?= e(asset_url($block["image"])) ?>" alt="<?= e($block["caption"] ?: "Studio process image") ?>">
                    <?php if (!empty($block["caption"])): ?><figcaption><?= e($block["caption"]) ?></figcaption><?php endif; ?>
                </figure>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</article>
EOD
,
    "studio-process.php" => <<<'EOD'
<section class="page-hero">
    <p class="eyebrow">Studio process</p>
    <h1>Structural Painting Process</h1>
    <p>Painting begins with spatial order: field, mass, horizon, vertical tension, and only then chromatic vibration.</p>
</section>
<section class="section process-grid">
    <div><span>01</span><h2>Field</h2><p>The dominant field establishes silence and scale.</p></div>
    <div><span>02</span><h2>Structure</h2><p>Masses, segments, and horizons organize the pictorial territory.</p></div>
    <div><span>03</span><h2>Ascent</h2><p>Stairways and thresholds introduce the measure of consciousness.</p></div>
    <div><span>04</span><h2>Presence</h2><p>The final painting holds equilibrium between void, material, and perception.</p></div>
</section>
EOD
];

foreach ($views as $name => $content) {
    file_put_contents("views/$name", $content);
}
?>
<?php
$views = [
    "contact.php" => <<<'EOD'
<section class="page-hero">
    <p class="eyebrow">Inquiries</p>
    <h1>Contact the Studio</h1>
    <p>For catalog documentation, curatorial questions, commissions, trade inquiries, or studio availability.</p>
</section>
<section class="section contact-panel">
    <div>
        <h2>Email</h2>
        <p><a href="mailto:<?= e($site["email"]) ?>?subject=<?= rawurlencode($subject) ?>"><?= e($site["email"]) ?></a></p>
    </div>
    <div>
        <h2>Collector Notes</h2>
        <p>Works are original hand-painted acrylic paintings. Certificates of authenticity and professional shipping from Spain can be documented for each acquisition.</p>
    </div>
    <div>
        <h2>Profiles</h2>
        <div class="social-links" aria-label="Social and marketplace profiles">
            <?php foreach ($site["social"] as $label => $url): ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
EOD
];

foreach ($views as $name => $content) {
    file_put_contents("views/$name", $content);
}
?>
