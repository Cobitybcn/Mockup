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