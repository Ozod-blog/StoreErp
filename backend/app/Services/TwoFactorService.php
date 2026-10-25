<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** RFC 6238 TOTP implementation (6 digits, 30-second period, +/- one time step). */
class TwoFactorService
{
    public function newSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        return $this->base32Encode($bytes);
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = (string) config('app.name', 'Store ERP');
        $label = rawurlencode($issuer.':'.$user->email);
        return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->totp($secret, $counter + $offset), $code)) return true;
        }
        return false;
    }

    /** @return list<string> Plain recovery codes; store only hashes. */
    public function recoveryCodes(): array
    {
        return array_map(fn () => strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4)), range(1, 10));
    }

    public function recoveryCodeIndex(array $hashedCodes, string $provided): ?int
    {
        foreach ($hashedCodes as $index => $hash) if (is_string($hash) && Hash::check(strtoupper(trim($provided)), $hash)) return $index;
        return null;
    }

    private function totp(string $base32, int $counter): string
    {
        $key = $this->base32Decode($base32);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
        foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 5) as $chunk) { if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0'); $out .= $alphabet[bindec($chunk)]; }
        return $out;
    }

    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
        foreach (str_split(strtoupper(rtrim($data, '='))) as $char) { $pos = strpos($alphabet, $char); if ($pos === false) throw new \InvalidArgumentException('Invalid base32 secret.'); $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT); }
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        return $out;
    }
}
