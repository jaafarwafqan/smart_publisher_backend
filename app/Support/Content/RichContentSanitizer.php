<?php

namespace App\Support\Content;

/**
 * Accepts only the small text-only Quill Delta subset emitted by the Flutter
 * composer. No embeds, arbitrary attributes, HTML, or executable payload can
 * be persisted as rich editor data.
 */
final class RichContentSanitizer
{
    /** @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function sanitizeMeta(array $meta): array
    {
        $raw = $meta['rich_content'] ?? null;
        if (! is_array($raw)) {
            return $meta;
        }

        $operations = [];
        $characters = 0;
        foreach ($raw as $operation) {
            if (! is_array($operation) || ! is_string($operation['insert'] ?? null)) {
                continue;
            }
            $insert = str_replace(["\r\n", "\r"], "\n", $operation['insert']);
            $characters += mb_strlen($insert);
            if ($characters > 10000) {
                break;
            }
            $attributes = $this->allowedAttributes($operation['attributes'] ?? null);
            $operations[] = array_filter([
                'insert' => $insert,
                'attributes' => $attributes === [] ? null : $attributes,
            ], fn (mixed $value): bool => $value !== null);
        }

        $meta['rich_content'] = $operations;

        return $meta;
    }

    /** @return array<string, string|bool> */
    private function allowedAttributes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $attributes = [];
        foreach (['bold', 'italic', 'underline'] as $style) {
            if (($raw[$style] ?? false) === true) {
                $attributes[$style] = true;
            }
        }
        foreach (['list', 'align', 'direction'] as $blockAttribute) {
            $value = $raw[$blockAttribute] ?? null;
            if (is_string($value) && in_array($value, ['ordered', 'bullet', 'left', 'center', 'right', 'justify', 'rtl', 'ltr'], true)) {
                $attributes[$blockAttribute] = $value;
            }
        }
        $link = $raw['link'] ?? null;
        if (is_string($link) && filter_var($link, FILTER_VALIDATE_URL) && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $attributes['link'] = $link;
        }

        return $attributes;
    }
}
