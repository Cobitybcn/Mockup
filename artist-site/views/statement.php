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