<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Blocks OAuth provider authorize_url/token_url values that could be used
 * for SSRF against internal infrastructure (cloud metadata endpoints,
 * loopback, private/link-local ranges). These fields are admin-editable
 * (system-settings.manage) and are later fetched server-side via a real
 * outbound HTTP POST (FacebookOAuthProvider::exchangeCodeForToken and
 * friends, invoked during an OAuth callback), so a malicious or
 * compromised admin account could otherwise point the server at internal
 * services. Resolves the host once at validation time — does not pin the
 * resolved IP through to the later outbound request, so this is
 * defense-in-depth against casual misuse, not a DNS-rebinding-proof
 * SSRF fix.
 */
class SafeOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $parts = parse_url($value);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if ($scheme !== 'https' || $host === null) {
            $fail('The :attribute must be an https URL.');

            return;
        }

        if (! $this->isSafeHost($host)) {
            $fail('The :attribute must not point to a private, loopback, or link-local address.');
        }
    }

    private function isSafeHost(string $host): bool
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
