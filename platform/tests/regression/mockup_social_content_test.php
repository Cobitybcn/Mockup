<?php
declare(strict_types=1);

function run_mockup_social_content_tests(): void
{
    TestHarness::group('Mockup social content traceability');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        "CREATE TABLE bilingual_editorial_content (
            user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            content_json TEXT NOT NULL,
            status TEXT NOT NULL
        )"
    );
    $content = [
        'alt_text' => 'Complete international alt text.',
        'social' => [
            'instagram' => [
                'hook' => 'International hook',
                'caption' => 'Complete caption',
                'hashtags' => '#MaurizioValch #AbstractPainting #ArtForCollectors',
                'cta' => 'Explore the artwork.',
            ],
            'pinterest' => [
                'title' => 'International Pin title',
                'description' => 'Complete Pin description',
                'board_suggestions' => 'Contemporary Abstract Art, Art for Collectors',
                'keywords' => 'abstract painting, original art',
            ],
            'facebook' => [
                'headline' => 'International headline',
                'post_text' => 'Complete Facebook post',
                'link_description' => 'Artwork and series context',
                'cta' => 'View artwork',
            ],
        ],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO bilingual_editorial_content
         (user_id,entity_type,entity_id,locale,content_json,status)
         VALUES (7,'mockup',21,'en',?,'current')"
    );
    $stmt->execute([json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $spanishContent = $content;
    $spanishContent['alt_text'] = 'Texto alternativo completo en español.';
    $spanishContent['social']['instagram'] = [
        'hook' => 'Apertura en español',
        'caption' => 'Texto completo en español',
        'hashtags' => '#MaurizioValch #PinturaAbstracta #ArteParaColeccionistas',
        'cta' => 'Explora la obra.',
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO bilingual_editorial_content
         (user_id,entity_type,entity_id,locale,content_json,status)
         VALUES (7,'mockup',21,'es',?,'source')"
    );
    $stmt->execute([json_encode($spanishContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

    $reader = new MockupSocialContentService($pdo);
    $reviewed = $reader->forMockup(7, 21, 'en');
    TestHarness::assertSame('current', $reviewed['status'], 'Social Media reads the reviewed English status');
    TestHarness::assertSame(
        'International hook',
        $reviewed['content']['social']['instagram']['hook'] ?? '',
        'Instagram hook comes from the bilingual mockup sheet'
    );
    TestHarness::assertSame(
        3,
        count(MockupSocialContentService::list($reviewed['content']['social']['instagram']['hashtags'] ?? [])),
        'space-separated hashtags remain individual publication terms'
    );
    TestHarness::assertSame(
        'unprepared',
        $reader->forMockup(7, 99, 'en')['status'],
        'historical mockups without bilingual content remain eligible for legacy fallback'
    );
    TestHarness::assertSame(
        'Apertura en español',
        $reader->forMockup(7, 21, 'es')['content']['social']['instagram']['hook'] ?? '',
        'the same mockup exposes its Spanish social package independently'
    );

    $board = (string)file_get_contents(dirname(__DIR__, 2) . '/social_media_board.php');
    $javascript = (string)file_get_contents(dirname(__DIR__, 2) . '/social_media_board.js');
    $styles = (string)file_get_contents(dirname(__DIR__, 2) . '/social_media_board.css');
    $pinterest = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/MockupPinterestDraftService.php');
    $meta = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/MetaSocialDraftService.php');
    $publisher = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/SocialBoardPublishService.php');
    TestHarness::assertContains('forMockups(', $board, 'Social Media Board loads bilingual mockup content in bulk');
    TestHarness::assertContains("'es' => \$socialContent->forMockups", $board, 'Social Media Board loads the Spanish social source');
    TestHarness::assertContains("'en' => \$socialContent->forMockups", $board, 'Social Media Board loads the international English source');
    TestHarness::assertContains("'locales' => \$locales", $board, 'each mockup delivers both languages to Social Media');
    TestHarness::assertContains('localized.instagram?.cta', $javascript, 'default Instagram publication copy includes its CTA');
    TestHarness::assertContains('data-group-locale', $javascript, 'Instagram and Facebook publications expose an ES/EN selector');
    TestHarness::assertContains('data-pin-locale', $javascript, 'each Pinterest Pin exposes an ES/EN selector');
    TestHarness::assertContains('copyCustomizedByLocale', $javascript, 'manual social copy is preserved separately by language');
    TestHarness::assertContains('titleCustomizedByLocale', $javascript, 'manual Pinterest titles are preserved separately by language');
    TestHarness::assertContains('.smb-publication-language', $styles, 'the language selector remains a compact local control');
    TestHarness::assertContains('MockupSocialContentService', $pinterest, 'Pinterest drafts use the same bilingual source');
    TestHarness::assertContains('MockupSocialContentService', $meta, 'Meta drafts use the same bilingual source');
    TestHarness::assertContains("'artist', \$locale", $publisher, 'Meta publication drafts keep the chosen language through delivery');
}
