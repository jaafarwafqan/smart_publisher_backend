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
    public static function toTelegramHtml(string $text): string
    {
        // Escape first so the raw text can never inject unintended HTML —
        // only the **/_ markers we explicitly convert below become tags.
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bolded = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $escaped);

        return preg_replace('/_(.+?)_/s', '<i>$1</i>', $bolded);
    }

    public static function toPlainText(string $text): string
    {
        $stripped = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);

        return preg_replace('/_(.+?)_/s', '$1', $stripped);
    }
}
