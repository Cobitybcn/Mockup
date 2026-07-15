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
            <?php 
                $titleWithYear = e($item["title"] ?? "");
                if (!empty($item["year"])) {
                    $titleWithYear .= " (" . e($item["year"]) . ")";
                }
            ?>
            <?php if ($genealogyUrl !== ""): ?>
                <a href="<?= e(url_for($genealogyUrl)) ?>">
                    <span><?= $titleWithYear ?></span>
                    <p><?= e($item["description"] ?? "") ?></p>
                </a>
            <?php else: ?>
                <article>
                    <span><?= $titleWithYear ?></span>
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