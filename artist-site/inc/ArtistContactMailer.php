<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class ArtistContactMailer
{
    public function send(array $message, string $defaultRecipient, string $artistName): void
    {
        $recipient = $this->env('CONTACT_RECIPIENT_EMAIL') ?: trim($defaultRecipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The contact recipient is not configured.');
        }

        $host = $this->env('SMTP_HOST');
        $username = $this->env('SMTP_USERNAME');
        $password = $this->env('SMTP_PASSWORD');
        $from = $this->env('CONTACT_FROM_EMAIL') ?: $username;
        $smtpRequested = $host !== '' || $username !== '' || $password !== '' || $this->env('CONTACT_FROM_EMAIL') !== '';

        if ($smtpRequested) {
            if ($host === '' || $username === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('The SMTP transport is not fully configured.');
            }
            $this->sendWithSmtp($message, $recipient, $from, $artistName, $host, $username, $password);
            return;
        }

        $this->sendWithNativeMail($message, $recipient, $recipient, $artistName);
    }

    private function sendWithSmtp(
        array $message,
        string $recipient,
        string $from,
        string $artistName,
        string $host,
        string $username,
        string $password
    ): void {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('The SMTP mail transport is unavailable.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = max(1, (int)($this->env('SMTP_PORT') ?: '587'));
        $mail->Timeout = 15;

        $encryption = strtolower($this->env('SMTP_ENCRYPTION') ?: 'tls');
        if (in_array($encryption, ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($encryption, ['none', 'off'], true)) {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, $artistName . ' Studio');
        $mail->addAddress($recipient);
        $mail->addReplyTo((string)$message['email'], (string)$message['name']);
        $mail->Subject = $this->subject($message, $artistName);
        $mail->isHTML(false);
        $mail->Body = $this->body($message, $artistName);
        $mail->send();
    }

    private function sendWithNativeMail(
        array $message,
        string $recipient,
        string $from,
        string $artistName
    ): void {
        $headers = [
            'From: ' . $from,
            'Reply-To: ' . (string)$message['email'],
            'X-Mailer: PHP/' . phpversion(),
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (!mail($recipient, $this->subject($message, $artistName), $this->body($message, $artistName), implode("\r\n", $headers))) {
            throw new RuntimeException('The server mail transport rejected the message.');
        }
    }

    private function subject(array $message, string $artistName): string
    {
        $subject = str_replace(["\r", "\n"], ' ', mb_substr(trim((string)$message['subject']), 0, 120));
        return '[' . $artistName . ' Website] ' . $subject;
    }

    private function body(array $message, string $artistName): string
    {
        return 'New message from ' . $artistName . " Website:\n\n"
            . 'Name: ' . (string)$message['name'] . "\n"
            . 'Email: ' . (string)$message['email'] . "\n"
            . 'Subject: ' . (string)$message['subject'] . "\n\n"
            . "Message:\n" . (string)$message['message'] . "\n";
    }

    private function env(string $key): string
    {
        $value = getenv($key);
        return $value === false ? '' : trim((string)$value);
    }
}
