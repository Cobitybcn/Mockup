<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/MockupEditorialContent.php';

$result = MockupEditorialContent::build(
    ['final_title'=>'Silent Territory','subtitle'=>'A Structural Field'],
    ['artwork_profile'=>['style_tags'=>['estructural','abstracto'],'mood_tags'=>['contemplative'],'palette'=>['sober tones']]],
    ['recurring_themes'=>'territorio; silencio','visual_language'=>'structural abstraction'],
    'Collector Loft'
);
$expectedDescription = "Silent Territory: A Structural Field\n\nThis Pin features a generated curatorial mockup of an original contemporary artwork in a collector loft setting. The image highlights the artwork scale, wall presence, color atmosphere, and gallery-ready presentation for collectors, interior designers, galleries, and buyers searching for abstract art for interiors.";
$checks = [
    $result['board'] === 'Architectural Minimalism',
    $result['title'] === 'Silent Territory - Original Contemporary Abstract Artwork',
    $result['description'] === $expectedDescription,
    $result['altText'] === 'A generated mockup showing Silent Territory as a contemporary artwork in a collector loft setting, with visible wall placement, artwork scale, color presence, and surrounding interior atmosphere.',
    $result['keywords'][0] === 'contemporary abstract art',
    in_array('structural', $result['keywords'], true),
    $result['hashtags'][0] === '#contemporaryart',
];
if (in_array(false, $checks, true)) { fwrite(STDERR,"FAIL: mockup editorial content changed.\n"); exit(1); }
echo "PASS: viewer editorial output remains stable for the characterization fixture.\n";
