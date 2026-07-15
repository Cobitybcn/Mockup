<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
PublicPage::start(
    'Data Deletion | Artwork Mockups',
    'Instructions for disconnecting third-party integrations and requesting deletion of Artwork Mockups account data.',
    'data-deletion/'
);
?>
<span class="eyebrow">Privacy</span><h1>Data deletion instructions</h1>
<p class="lede">You may disconnect Facebook, Instagram or Pinterest access and request deletion of data associated with your Artwork Mockups account.</p>
<section><h2>Disconnect Meta or Pinterest</h2><ol><li>Sign in to Artwork Mockups.</li><li>Open the relevant Meta or Pinterest connections page.</li><li>Select <strong>Disconnect</strong> for the connected identity.</li><li>You may also remove Artwork Mockups directly from the connected platform's app and business integration settings.</li></ol><p>Disconnecting removes the app's usable local access credentials. Posts already published on a third-party platform remain subject to that platform and may need to be deleted there separately.</p></section>
<section><h2>Request account or Platform Data deletion</h2><p>Use the <a href="<?= PublicPage::h(PublicPage::path('contact/')) ?>">Artwork Mockups contact form</a>, select <strong>Delete my account</strong> or <strong>Privacy request</strong>, and identify the email address used for your account. State whether you request deletion of the entire account or only Meta/Pinterest integration data.</p><p>We will verify the requester's identity before deletion. We will remove applicable account, token and integration records, subject to limited retention required for security, fraud prevention, legal obligations or claims.</p></section>
<section><h2>What to include</h2><ul><li>Your Artwork Mockups account email.</li><li>The connected Facebook Page, Instagram username or Pinterest account, if relevant.</li><li>The scope of deletion requested.</li></ul><p>Do not send access tokens, passwords or application secrets.</p></section>
<?php PublicPage::end(); ?>
