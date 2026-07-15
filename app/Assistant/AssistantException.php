<?php
declare(strict_types=1);

final class AssistantException extends RuntimeException
{
    public function __construct(string $message, private string $publicCode = 'assistant_error')
    {
        parent::__construct($message);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }
}
