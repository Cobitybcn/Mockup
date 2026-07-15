<?php
declare(strict_types=1);
namespace App\Services\Pinterest;
interface PinterestPinService { public function createAfterExplicitConfirmation(int $userId,array $approvedPin): array; }
