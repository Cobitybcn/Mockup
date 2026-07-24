<?php
declare(strict_types=1);
function run_public_pages_regression_tests(): void {
    TestHarness::group('Public legal, Pinterest and Meta pages');
    $root=dirname(__DIR__,2);
    foreach(['privacy/index.php','terms/index.php','contact/index.php','data-deletion/index.php','integrations/pinterest/index.php','integrations/pinterest/callback/index.php','integrations/meta/index.php','integrations/meta/callback/index.php','integrations/instagram/index.php','integrations/instagram/callback/index.php','integrations/instagram/deauthorize/index.php','integrations/instagram/data-deletion/index.php','integrations/stripe/webhook/index.php'] as $path) TestHarness::assertTrue(is_file($root.'/'.$path),$path.' exists');
    $privacy=(string)file_get_contents($root.'/privacy/index.php'); $terms=(string)file_get_contents($root.'/terms/index.php'); $pin=(string)file_get_contents($root.'/integrations/pinterest/index.php'); $callback=(string)file_get_contents($root.'/integrations/pinterest/callback/index.php'); $metaCallback=(string)file_get_contents($root.'/integrations/meta/callback/index.php'); $instagram=(string)file_get_contents($root.'/integrations/instagram/index.php'); $instagramCallback=(string)file_get_contents($root.'/integrations/instagram/callback/index.php');
    TestHarness::assertContains('does not publish automatically',$pin,'automatic publishing is explicitly prohibited');
    TestHarness::assertContains('Connect your Pinterest account',$pin,'artists can connect their private Pinterest account from the connection page');
    TestHarness::assertContains('no developer codes or tokens are required',$pin,'artists are not asked for Pinterest developer credentials');
    TestHarness::assertTrue(!str_contains($pin,'name="access_token"'),'the artist connection page does not request a manual token');
    TestHarness::assertContains("authorizationUrl(\$userId, 'artist')",(string)file_get_contents($root.'/connections.php'),'artist Pinterest connections use the official OAuth flow');
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
    $artistSite=(string)file_get_contents(dirname($root).'/artist-site/index.php');
    $artistHeader=(string)file_get_contents(dirname($root).'/artist-site/inc/header.php');
    $artistStore=(string)file_get_contents(dirname($root).'/artist-site/inc/AppStore.php');
    $stripeCheckout=(string)file_get_contents(dirname($root).'/artist-site/inc/StripeCheckout.php');
    TestHarness::assertContains("app_artist_photo_url(\$artistPhotoFile)",$artistHeader,'the artist website uses the published profile photo as its favicon');
    TestHarness::assertContains('<link rel="icon" href="<?= e($faviconUrl) ?>">',$artistHeader,'the artist website exposes the resolved artist favicon in every page head');
    TestHarness::assertTrue(
        strpos($artistHeader, "url_for('artworks/')") < strpos($artistHeader, "url_for('series')")
            && strpos($artistHeader, "url_for('series')") < strpos($artistHeader, "url_for('sold-works')"),
        'the artist website navigation places Series immediately after Artworks'
    );
    TestHarness::assertContains("strcasecmp(trim((string)(\$artwork['series'] ?? '')), \$seriesTitle) === 0",$artistSite,'public series details select their dependent published artworks');
    TestHarness::assertContains('<h2>Works in this series</h2>',$artistSite,'public series details render their dependent artwork collection');
    TestHarness::assertContains('class="artwork-series-link"',$artistSite,'published artwork details link their series to its public detail page');
    TestHarness::assertContains('class="artwork-series-preview"',$artistSite,'published artwork details provide an accessible series preview');
    TestHarness::assertContains("array_keys(app_series_catalog()?->all() ?? [])",$artistSite,'the sitemap reads the currently published managed series');
    TestHarness::assertTrue(!str_contains($artistSite,"array_keys(\$series) as \$slug"),'the sitemap does not advertise legacy static series');
    TestHarness::assertContains("url_for('acquire/' . \$artwork['slug'])",$artistSite,'available published artworks open the private acquisition flow');
    TestHarness::assertContains("case 'acquire':",$artistSite,'the artist website routes acquisition requests separately from editorial content');
    TestHarness::assertContains("'order.created'",$artistStore,'public acquisition requests create auditable Store orders');
    TestHarness::assertContains('stock_reserved=stock_reserved+1',$artistStore,'public acquisition requests reserve stock atomically');
    TestHarness::assertContains("p.status='published' AND p.visibility IN ('public','unlisted')",$artistStore,'order creation revalidates publication at submission time');
    TestHarness::assertContains("\$meta['robots'] = 'noindex,nofollow'",$artistSite,'private acquisition forms are excluded from search indexing');
    TestHarness::assertTrue(!str_contains($stripeCheckout,"'stripe_account' =>"),'Stripe Checkout uses the artist account key directly without a Connect account header');
    TestHarness::assertContains("hash_equals((string)\$order['provider_account_id'], \$this->accountId)",$stripeCheckout,'Stripe webhooks cannot settle an order belonging to another artist account');
    TestHarness::assertContains('checkout.session.expired',$stripeCheckout,'expired Stripe sessions release temporary artwork reservations');
    $mockupLandingStart=strpos($artistSite,'function render_published_mockup');
    $mockupLanding=$mockupLandingStart===false?'':substr($artistSite,$mockupLandingStart,strpos($artistSite,'function render_series_index',$mockupLandingStart)-$mockupLandingStart);
    TestHarness::assertContains("offerForArtwork((int)(\$artwork['canonical_artwork_id'] ?? 0))",$mockupLanding,'published mockup pages resolve the offer belonging to their original artwork');
    TestHarness::assertContains("AppStore::money((int)\$storeOffer['price_minor']",$mockupLanding,'published mockup pages show the original artwork price');
    TestHarness::assertContains("url_for('acquire/' . \$artwork['slug'])",$mockupLanding,'published mockup pages link available works to the canonical acquisition flow');
    $stripeWebhook=(string)file_get_contents($root.'/integrations/stripe/webhook/index.php');
    TestHarness::assertContains("['metadata']['order_id']",$stripeWebhook,'the shared webhook routes each signed event to the order owner');
    $apiSettings=(string)file_get_contents($root.'/admin_api_keys.php');
    TestHarness::assertTrue(!str_contains($apiSettings,'stripe_connect_secret_key'),'Stripe platform credentials are never editable in the product admin');
    $publishedSeriesCatalog=(string)file_get_contents(dirname($root).'/artist-site/inc/AppPublishedSeriesCatalog.php');
    TestHarness::assertContains('COALESCE(s.year_start, s.year_end) DESC',$publishedSeriesCatalog,'the public series catalog follows the app editorial year order');
    TestHarness::assertContains('s.created_at DESC',$publishedSeriesCatalog,'the public series catalog preserves the app editorial tie breaker');
    TestHarness::assertTrue(!str_contains($publishedSeriesCatalog,'ORDER BY s.year_start DESC, s.title ASC'),'the public series catalog no longer replaces editorial order with alphabetical order');
    $artistStyles=(string)file_get_contents(dirname($root).'/artist-site/assets/css/styles.css');
    TestHarness::assertContains('grid-template-columns: repeat(4, minmax(0, 1fr));',$artistStyles,'the published series overview uses four equal desktop columns');
    preg_match('/\\.artwork-detail__content\\s*\\{([^}]*)\\}/s',$artistStyles,$artworkDetailContentRules);
    TestHarness::assertTrue(
        !str_contains((string)($artworkDetailContentRules[1] ?? ''),'position: sticky'),
        'artwork images and editorial content scroll together'
    );
    TestHarness::assertContains('.artwork-series-reference:hover .artwork-series-preview',$artistStyles,'series previews appear on pointer hover');
    TestHarness::assertContains('.artwork-series-reference:focus-within .artwork-series-preview',$artistStyles,'series previews appear for keyboard focus');
}
