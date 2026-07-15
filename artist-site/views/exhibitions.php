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