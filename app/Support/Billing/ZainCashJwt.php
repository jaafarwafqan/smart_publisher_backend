<?php

namespace App\Support\Billing;

/**
 * Minimal, dependency-free HS256 JWT verifier for ZainCash's callback token
 * — ZainCash signs its return/webhook JWT with the merchant's own client
 * secret (HMAC-SHA256), the same symmetric-key shape StripeWebhookProcessor
 * already verifies by hand for Stripe's own signature header (hash_hmac +
 * hash_equals), so this follows the same house pattern rather than pulling
 * in a general-purpose JWT library for one algorithm.
 */
final class ZainCashJwt
{
    /**
     * Returns the decoded payload claims if the signature is valid, null
     * otherwise. Callers MUST treat a null result as fully untrusted — do
     * not read any claim from a JWT that failed verification.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $jwt, string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true));
        if (! hash_equals($expectedSignature, $signatureB64)) {
            return null;
        }

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);

        return is_array($payload) ? $payload : null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + 4 - strlen($data) % 4, '=');

        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
