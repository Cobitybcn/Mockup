<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/bootstrap.php';
Auth::start();
$user=Auth::user();
if($user){
    FeatureAccess::requirePage($user,FeatureAccess::SOCIAL_MANAGE,'Social Media');
    header('Location: '.PublicPage::path('connections.php?open=pinterest'));
    exit;
}
PublicPage::start('Pinterest Connection | Artwork Mockups','Connect your Pinterest account to prepare and publish approved artwork Pins.','integrations/pinterest/');
?>
<span class="eyebrow">Artist connection</span>
<h1>Connect your Pinterest account</h1>
<p class="lede">Sign in to Artwork Mockups and connect the Pinterest account you want to use as an artist. Pinterest will ask you to authorize access; no developer codes or tokens are required.</p>
<div class="info-card">
    <h2>Artist controlled</h2>
    <p>Connecting an account does not publish automatically. Every Pin is prepared inside Artwork Mockups and requires the artist's explicit approval before publication.</p>
    <p><a class="button-link primary" href="<?=PublicPage::h(PublicPage::path('login.php'))?>">Sign in to connect Pinterest</a></p>
</div>
<?php PublicPage::end(); ?>
