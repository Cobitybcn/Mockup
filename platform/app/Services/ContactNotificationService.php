<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class ContactNotificationService
{
    public function send(array $message): void
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('Mail transport dependency is unavailable.');
        }

        $host = trim(app_env('SMTP_HOST', ''));
        $username = trim(app_env('SMTP_USERNAME', ''));
        $password = app_env('SMTP_PASSWORD', '');
        $recipient = trim(app_env('CONTACT_RECIPIENT_EMAIL', 'mauriziovalch@gmail.com'));
        $from = trim(app_env('CONTACT_FROM_EMAIL', $username));
        if ($host === '' || $username === '' || $password === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Mail transport is not configured.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = (int)app_env('SMTP_PORT', '587');
        $mail->SMTPSecure = strtolower(app_env('SMTP_ENCRYPTION', 'tls')) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, 'Artwork Mockups');
        $mail->addAddress($recipient);
        $mail->addReplyTo((string)$message['email'], (string)$message['name']);
        $mail->Subject = '[Artwork Mockups] ' . (string)$message['subject'];
        $mail->isHTML(false);
        $mail->Body = "New contact form message\n\n"
            . 'Name: ' . (string)$message['name'] . "\n"
            . 'Email: ' . (string)$message['email'] . "\n"
            . 'Subject: ' . (string)$message['subject'] . "\n\n"
            . (string)$message['message'];
        $mail->send();
    }
}
