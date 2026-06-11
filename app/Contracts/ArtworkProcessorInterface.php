<?php
declare(strict_types=1);

interface ArtworkProcessorInterface
{
    public function createRootImage(string $jobDir, array $status): array;
}
