<?php
declare(strict_types=1);

final class ContactMessageRepository
{
    public function __construct(private PDO $pdo) {}

    public function save(array $message): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO contact_messages (name, email, subject, message, status, created_at) VALUES (:name, :email, :subject, :message, :status, :created_at)');
        $stmt->execute([
            'name' => $message['name'], 'email' => $message['email'],
            'subject' => $message['subject'], 'message' => $message['message'],
            'status' => 'new', 'created_at' => date('c'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function markNotified(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE contact_messages SET status = 'notified' WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
}
