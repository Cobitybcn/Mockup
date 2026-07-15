<?php
declare(strict_types=1);

interface MockupGeneratorInterface
{
    public function generate(string $imagePath, string $contextId, string $prompt, array $metadata = []): array;
}
