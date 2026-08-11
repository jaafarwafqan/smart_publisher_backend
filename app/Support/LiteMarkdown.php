<?php

namespace App\Support;

/**
 * A tiny, intentionally limited markdown subset (`**bold**`, `_italic_`) for
 * post captions. Only Telegram's real API renders formatting (via
 * parse_mode: HTML) — Facebook/Instagram/WhatsApp captions are plain text,
 * so the same marker must become real markup for Telegram and be cleanly
 * stripped everywhere else, never sent as literal asterisks/underscores.
 */
class LiteMarkdown
{
    // CommonMark's own rule for this exact ambiguity: `_` immediately
    // adjacent to a letter/digit ("intraword") never opens/closes emphasis
    // — without it, any hashtag using underscores as a word separator (a
    // standard Arabic-hashtag convention, since spaces aren't allowed) gets
    // misread as italic markup, silently eating every underscore between
    // the first and last hashtag in the caption — and for Telegram, wraps
    // unintended spans in stray <i> tags. Reproduced live: four hashtags
    // like #رسول_الله #وفاء_للحسين collapsed into two mangled, merged runs.
    // Needs the /u modifier for \p{L}/\p{N} (Unicode letter/number) — \w in
    // PCRE without /u is effectively ASCII-only and misses Arabic entirely.
    private const ITALIC_PATTERN = '/(?<![\p{L}\p{N}])_(.+?)_(?![\p{L}\p{N}])/su';

    public static function toTelegramHtml(string $text): string
    {
        // Escape first so the raw text can never inject unintended HTML —
        // only the **/_ markers we explicitly convert below become tags.
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bolded = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $escaped);

        return preg_replace(self::ITALIC_PATTERN, '<i>$1</i>', $bolded);
    }

    public static function toPlainText(string $text): string
    {
        $stripped = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);

        return preg_replace(self::ITALIC_PATTERN, '$1', $stripped);
    }
}
