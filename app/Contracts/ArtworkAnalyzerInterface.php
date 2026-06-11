<?php
declare(strict_types=1);

interface ArtworkAnalyzerInterface
{
    public function analyze(string $imagePath, array $metadata = []): array;
}
