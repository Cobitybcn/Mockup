<?php
declare(strict_types=1);
namespace App\Services\Pinterest;
interface PinterestTokenService { public function storeEncrypted(int $userId,array $tokens): void; public function refresh(int $userId): void; public function revoke(int $userId): void; }
