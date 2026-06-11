<?php
declare(strict_types=1);

interface ContextSelectorInterface
{
    public function select(array $profile, array $imageMeta, int $limit = 5): array;
}
