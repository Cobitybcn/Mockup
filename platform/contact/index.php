<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
Auth::start();
$subjects = ['General support','Privacy request','Delete my account','Copyright or content report','Pinterest integration','Meta integration','Technical issue'];
$errors = []; $success = false; $name = $email = $subject = $message = '';
$_SESSION['contact_csrf'] ??= bin2hex(random_bytes(32)); $_SESSION['contact_form_started'] ??= time();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name=trim((string)($_POST['name']??'')); $email=trim((string)($_POST['email']??'')); $subject=trim((string)($_POST['subject']??'')); $message=trim((string)($_POST['message']??''));
    if (!hash_equals((string)$_SESSION['contact_csrf'],(string)($_POST['csrf']??''))) $errors[]='Your session expired. Please refresh and try again.';
    if ((string)($_POST['website']??'')!=='') $errors[]='We could not accept this message.';
    if (time()-(int)$_SESSION['contact_form_started']<3) $errors[]='Please take a moment to review your message.';
    if (isset($_SESSION['contact_last_sent'])&&time()-(int)$_SESSION['contact_last_sent']<60) $errors[]='Please wait before sending another message.';
    if (mb_strlen($name)<2||mb_strlen($name)>100) $errors[]='Enter a valid name.';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>254) $errors[]='Enter a valid email address.';
    if (!in_array($subject,$subjects,true)) $errors[]='Choose a valid subject.';
    if (mb_strlen($message)<20||mb_strlen($message)>5000) $errors[]='Message must be between 20 and 5,000 characters.';
    if (!$errors) { try { $payload=compact('name','email','subject','message'); $repository=new ContactMessageRepository(Database::connection()); $messageId=$repository->save($payload); (new ContactNotificationService())->send($payload); $repository->markNotified($messageId); $_SESSION['contact_last_sent']=time(); $_SESSION['contact_csrf']=bin2hex(random_bytes(32)); $success=true; $name=$email=$subject=$message=''; } catch(Throwable $e) { error_log('Contact form delivery failed'); $errors[]='Your message could not be delivered by email right now. Please try again later.'; } }
}
PublicPage::start('Contact | Artwork Mockups','Contact Artwork Mockups for support, privacy requests, account deletion, copyright matters or technical assistance.','contact/');
?>
<span class="eyebrow">Support</span><h1>Contact Artwork Mockups</h1><p class="lede">For support, privacy requests, account deletion, copyright matters or questions about Pinterest and Meta integrations, contact us through the form below.</p>
<?php if($success):?><p class="notice success" role="status">Your message was received. We will review it as soon as possible.</p><?php endif;?>
<?php if($errors):?><div class="notice error" role="alert"><strong>Please check the form:</strong><ul><?php foreach($errors as $error):?><li><?=PublicPage::h($error)?></li><?php endforeach;?></ul></div><?php endif;?>
<form class="contact-form" method="post" novalidate><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['contact_csrf'])?>"><label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
<label>Name<input name="name" required minlength="2" maxlength="100" autocomplete="name" value="<?=PublicPage::h($name??'')?>"></label><label>Email<input type="email" name="email" required maxlength="254" autocomplete="email" value="<?=PublicPage::h($email??'')?>"></label>
<label>Subject<select name="subject" required><option value="">Select a subject</option><?php foreach($subjects as $option):?><option<?=($subject??'')===$option?' selected':''?>><?=PublicPage::h($option)?></option><?php endforeach;?></select></label><label>Message<textarea name="message" required minlength="20" maxlength="5000"><?=PublicPage::h($message??'')?></textarea></label><button type="submit">Send message</button></form>
<?php PublicPage::end(); ?>
