<?php
declare(strict_types=1);

namespace PHPMailer\PHPMailer {
    final class PHPMailer
    {
        public const ENCRYPTION_SMTPS = 'ssl';
        public const ENCRYPTION_STARTTLS = 'tls';
        public static ?self $last = null;
        public string $Host = '';
        public bool $SMTPAuth = false;
        public string $Username = '';
        public string $Password = '';
        public int $Port = 0;
        public int $Timeout = 0;
        public string $SMTPSecure = '';
        public bool $SMTPAutoTLS = true;
        public string $CharSet = '';
        public string $Subject = '';
        public string $Body = '';
        public array $from = [];
        public array $recipients = [];
        public array $replyTo = [];

        public function __construct(bool $exceptions = false) {}
        public function isSMTP(): void {}
        public function setFrom(string $address, string $name = ''): void { $this->from = [$address, $name]; }
        public function addAddress(string $address): void { $this->recipients[] = $address; }
        public function addReplyTo(string $address, string $name = ''): void { $this->replyTo = [$address, $name]; }
        public function isHTML(bool $isHtml = true): void {}
        public function send(): bool { self::$last = $this; return true; }
    }
}

namespace {
    require_once dirname(__DIR__) . '/inc/ArtistContactMailer.php';

    function assert_contact(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $settings = [
        'CONTACT_RECIPIENT_EMAIL' => 'collector@example.com',
        'CONTACT_FROM_EMAIL' => 'website@example.com',
        'SMTP_HOST' => 'smtp.example.com',
        'SMTP_PORT' => '587',
        'SMTP_ENCRYPTION' => 'tls',
        'SMTP_USERNAME' => 'smtp-user@example.com',
        'SMTP_PASSWORD' => 'test-only-password',
    ];
    foreach ($settings as $key => $value) putenv($key . '=' . $value);

    (new ArtistContactMailer())->send([
        'name' => 'Pablo Chiappero',
        'email' => 'chiappero@example.com',
        'subject' => "Painting inquiry\r\nBcc: ignored@example.com",
        'message' => 'What is the price?',
    ], 'studio@example.com', 'Maurizio Valch');

    $mail = \PHPMailer\PHPMailer\PHPMailer::$last;
    assert_contact($mail !== null, 'SMTP transport sends the message');
    assert_contact($mail->Host === 'smtp.example.com' && $mail->Port === 587, 'SMTP host and port are applied');
    assert_contact($mail->SMTPSecure === 'tls', 'STARTTLS is applied');
    assert_contact($mail->from === ['website@example.com', 'Maurizio Valch Studio'], 'configured sender is applied');
    assert_contact($mail->recipients === ['collector@example.com'], 'configured recipient overrides the site fallback');
    assert_contact($mail->replyTo === ['chiappero@example.com', 'Pablo Chiappero'], 'visitor becomes the reply-to address');
    assert_contact(!str_contains($mail->Subject, "\r") && !str_contains($mail->Subject, "\n"), 'subject header injection is removed');
    assert_contact(str_contains($mail->Body, 'What is the price?'), 'message body is preserved');

    putenv('SMTP_PASSWORD=');
    try {
        (new ArtistContactMailer())->send([
            'name' => 'Pablo', 'email' => 'pablo@example.com', 'subject' => 'Inquiry', 'message' => 'Hello',
        ], 'studio@example.com', 'Maurizio Valch');
        assert_contact(false, 'partial SMTP configuration is rejected');
    } catch (RuntimeException $error) {
        assert_contact(str_contains($error->getMessage(), 'not fully configured'), 'partial SMTP failure is explicit');
    }

    echo "PASS: artist contact SMTP transport\n";
}
