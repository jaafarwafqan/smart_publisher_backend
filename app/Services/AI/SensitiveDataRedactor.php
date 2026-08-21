<?php

namespace App\Services\AI;

/**
 * Replaces immutable/sensitive fragments before an external request and
 * restores them afterwards.  It keeps the user-visible content in memory
 * only; callers must never log either the original or redacted text.
 */
final class SensitiveDataRedactor
{
    /** @var array<string, string> */
    private array $replacements = [];

    public function redact(string $text): string
    {
        $this->replacements = [];
        /** @var array<string, string> $temporaryTokens */
        $temporaryTokens = [];
        $patterns = [
            '/https?:\/\/[^\s<>()]+/iu',
            '/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u',
            '/(?<!\w)@[\p{L}\p{N}_]+/u',
            '/#[\p{L}\p{N}_]+/u',
            '/\b\d{4}[-\/]\d{1,2}[-\/]\d{1,2}\b/u',
            '/(?<!\p{L})\d[\d,.\/:\-]*/u',
            '/\b(?:Bearer\s+)?[A-Za-z0-9_-]{24,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/u',
            '/\b(?:sk|pk|api)[_-][A-Za-z0-9_-]{16,}\b/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function (array $match) use (&$temporaryTokens): string {
                // The public token deliberately contains a number (SPP-0).
                // Use a temporary private-use marker while walking the rest of
                // the patterns, otherwise the numeric-data pattern would mask
                // the token itself on the next pass.
                $number = count($temporaryTokens);
                $temporary = "\u{E000}SPP{$number}\u{E001}";
                $token = "[SPP-{$number}]";
                $temporaryTokens[$temporary] = $token;
                $this->replacements[$token] = $match[0];

                return $temporary;
            }, $text) ?? $text;
        }

        return strtr($text, $temporaryTokens);
    }

    public function restore(string $text): string
    {
        return strtr($text, $this->replacements);
    }

    public function hasAllPlaceholders(string $text): bool
    {
        foreach (array_keys($this->replacements) as $placeholder) {
            if (! str_contains($text, $placeholder)) {
                return false;
            }
        }

        return true;
    }
}
