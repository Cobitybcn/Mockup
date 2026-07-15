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