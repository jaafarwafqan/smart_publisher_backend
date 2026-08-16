<?php

namespace App\Support\Webhooks;

/**
 * Meta signs every Page webhook POST body with the app's App Secret and
 * sends the result as `X-Hub-Signature-256: sha256=<hex>`. This is the
 * entire trust boundary for the receiver — anyone can POST to a public URL,
 * only Meta (or someone who already has the App Secret) can produce a
 * signature that verifies. See https://developers.facebook.com/docs/graph-api/webhooks/getting-started#validate-payloads
 */
class FacebookWebhookSignature
{
    public static function verify(string $rawBody, ?string $signatureHeader, string $appSecret): bool
    {
        if ($appSecret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $appSecret);
        $provided = substr($signatureHeader, strlen('sha256='));

        return hash_equals($expected, $provided);
    }
}
