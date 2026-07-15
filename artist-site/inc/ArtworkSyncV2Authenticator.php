<?php
declare(strict_types=1);

final class ArtworkSyncV2Authenticator
{
    public function __construct(private readonly string $secret,private readonly int $tolerance=300)
    {if(strlen($secret)<32)throw new RuntimeException('ARTWORK_SYNC_SHARED_SECRET must contain at least 32 characters.');}

    public function verify(string $raw,string $timestamp,string $signature,?int $now=null): void
    {
        if(!preg_match('/^\d{10}$/',$timestamp))throw new RuntimeException('Invalid sync timestamp.');$now??=time();
        if(abs($now-(int)$timestamp)>$this->tolerance)throw new RuntimeException('Sync request expired.');
        if(!preg_match('/^[a-f0-9]{64}$/i',$signature))throw new RuntimeException('Invalid sync signature.');
        $expected=hash_hmac('sha256',$timestamp."\n".$raw,$this->secret);if(!hash_equals($expected,strtolower($signature)))throw new RuntimeException('Sync signature mismatch.');
    }
}
