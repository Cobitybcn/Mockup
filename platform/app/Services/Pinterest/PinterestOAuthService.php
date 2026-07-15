<?php
declare(strict_types=1);
namespace App\Services\Pinterest;
interface PinterestOAuthService { public function authorizationUrl(int $userId): string; public function exchangeCode(string $code,string $state): array; }
