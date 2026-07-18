<?php
declare(strict_types=1);
function run_public_pages_regression_tests(): void {
    TestHarness::group('Public legal, Pinterest and Meta pages');
    $root=dirname(__DIR__,2);
    foreach(['privacy/index.php','terms/index.php','contact/index.php','data-deletion/index.php','integrations/pinterest/index.php','integrations/pinterest/callback/index.php','integrations/meta/index.php','integrations/meta/callback/index.php','integrations/instagram/index.php','integrations/instagram/callback/index.php','integrations/instagram/deauthorize/index.php','integrations/instagram/data-deletion/index.php'] as $path) TestHarness::assertTrue(is_file($root.'/'.$path),$path.' exists');
    $privacy=(string)file_get_contents($root.'/privacy/index.php'); $terms=(string)file_get_contents($root.'/terms/index.php'); $pin=(string)file_get_contents($root.'/integrations/pinterest/index.php'); $callback=(string)file_get_contents($root.'/integrations/pinterest/callback/index.php'); $metaCallback=(string)file_get_contents($root.'/integrations/meta/callback/index.php'); $instagram=(string)file_get_contents($root.'/integrations/instagram/index.php'); $instagramCallback=(string)file_get_contents($root.'/integrations/instagram/callback/index.php');
    TestHarness::assertContains('does not publish automatically',$pin,'automatic publishing is explicitly prohibited');
    TestHarness::assertContains('explicitly approving each',$terms,'terms require Pin approval');
    TestHarness::assertContains('does not use Pinterest data for unrelated advertising',$privacy,'privacy limits Pinterest data use');
    TestHarness::assertContains('We do not sell Meta Platform Data',$privacy,'privacy limits Meta Platform Data use');
    TestHarness::assertContains('reviewing each image, caption, hashtag, alt text and destination',$terms,'terms require Meta publication review');
    TestHarness::assertContains('VERIFICADO',(string)file_get_contents($root.'/meta_batch_resolve.php'),'inconclusive Meta writes require manual verification');
    TestHarness::assertContains("true);",$callback,'callback requests noindex rendering');
    TestHarness::assertContains("true);",$metaCallback,'Meta callback requests noindex rendering');
    TestHarness::assertContains("true);",$instagramCallback,'Instagram callback requests noindex rendering');
    TestHarness::assertContains('never publishes by itself',$instagram,'Instagram connection does not publish automatically');
    TestHarness::assertContains('signed_request',(string)file_get_contents($root.'/integrations/instagram/data-deletion/index.php'),'Instagram deletion endpoint accepts signed requests');
    TestHarness::assertContains('contact_csrf',$rootFile=(string)file_get_contents($root.'/contact/index.php'),'contact form has CSRF validation');
    $home=(string)file_get_contents($root.'/index.php');
    TestHarness::assertContains("PublicPage::path('integrations/pinterest/')",$home,'home links to Pinterest integration');
    TestHarness::assertContains('does not publish automatically',$home,'home states that Pinterest publishing is manual');
    TestHarness::assertContains("PublicPage::path('contact/')",$home,'home links to public contact page');
    TestHarness::assertContains('favicon.svg',$home,'the public landing page exposes the Artwork Mockups favicon');
    TestHarness::assertContains("self::path('favicon.svg?v=1')",(string)file_get_contents($root.'/app/Support/PublicPage.php'),'shared public pages expose the Artwork Mockups favicon');
    TestHarness::assertContains('favicon.svg?v=1',(string)file_get_contents($root.'/sidebar.php'),'private workspace pages expose the Artwork Mockups favicon');
    $social=(string)file_get_contents($root.'/social_media_catalog.php');
    TestHarness::assertContains('Current Campaign Draft',$social,'social campaigns identify the single current draft');
    TestHarness::assertContains('Active Campaign Drafts',$social,'active drafts are separated from the current campaign');
    TestHarness::assertContains('Publication History',$social,'published campaigns have a separate read-only history');
    TestHarness::assertTrue(!str_contains($social,'value="facebook" checked')&&!str_contains($social,'value="instagram" checked'),'Facebook and Instagram destinations are never preselected');
}
