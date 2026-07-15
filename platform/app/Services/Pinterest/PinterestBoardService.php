<?php
declare(strict_types=1);
namespace App\Services\Pinterest;
interface PinterestBoardService { public function boardsForUser(int $userId): array; }
