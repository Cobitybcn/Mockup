<section class="page-hero">
    <p class="eyebrow">Studio Notes</p>
    <h1>Concepts in Architectural Abstract Painting</h1>
    <p>Editorial pages built for informational search intent and deeper reading.</p>
</section>
<section class="section article-list">
    <?php $index = 0; foreach ($journal as $slug => $post): ?>
        <?php $thumb = !empty($post["image"]) ? $post["image"] : ($artworkImages[$index % max(1, count($artworkImages))] ?? ""); ?>
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