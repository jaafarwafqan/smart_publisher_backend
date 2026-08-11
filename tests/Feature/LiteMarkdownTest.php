<?php

namespace Tests\Feature;

use App\Support\LiteMarkdown;
use Tests\TestCase;

class LiteMarkdownTest extends TestCase
{
    public function test_telegram_html_converts_bold_and_italic_markers(): void
    {
        $this->assertSame(
            'Hello <b>world</b>, this is <i>great</i>!',
            LiteMarkdown::toTelegramHtml('Hello **world**, this is _great_!')
        );
    }

    public function test_telegram_html_escapes_real_html_before_converting_markers(): void
    {
        $this->assertSame(
            '&lt;script&gt; <b>bold</b>',
            LiteMarkdown::toTelegramHtml('<script> **bold**')
        );
    }

    public function test_telegram_html_leaves_plain_text_untouched(): void
    {
        $this->assertSame('Just plain text.', LiteMarkdown::toTelegramHtml('Just plain text.'));
    }

    public function test_plain_text_strips_markers_without_leaving_asterisks(): void
    {
        $this->assertSame(
            'Hello world, this is great!',
            LiteMarkdown::toPlainText('Hello **world**, this is _great_!')
        );
    }

    public function test_plain_text_leaves_untouched_text_as_is(): void
    {
        $this->assertSame('No markers here.', LiteMarkdown::toPlainText('No markers here.'));
    }

    public function test_handles_multiple_and_nested_style_markers(): void
    {
        $this->assertSame(
            '<b>one</b> and <b>two</b>',
            LiteMarkdown::toTelegramHtml('**one** and **two**')
        );
        $this->assertSame('one and two', LiteMarkdown::toPlainText('**one** and **two**'));
    }

    /**
     * Reproduced live: a caption with four Arabic hashtags (underscores as
     * the word separator, since spaces aren't allowed in a hashtag) — the
     * naive _(.+?)_ pattern read the underscore in the first hashtag as
     * opening italic and the underscore in the second as closing it,
     * collapsing all four into two mangled, merged runs, both on the
     * Telegram HTML path (stray <i> tags spanning unrelated hashtags) and
     * plain-text path (every "opening" underscore just vanished).
     */
    public function test_never_treats_underscores_inside_hashtags_as_italic_markers(): void
    {
        $caption = "#رسول_الله\n#وفاء_للحسين\n#شهر_صفر\n#العتبة_الحسينية_المقدسة";

        $this->assertSame($caption, LiteMarkdown::toPlainText($caption));
        $this->assertSame(
            htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            LiteMarkdown::toTelegramHtml($caption)
        );
    }

    public function test_still_treats_underscores_at_real_word_boundaries_as_italic(): void
    {
        $this->assertSame(
            'a snake_case_name and real italic text',
            LiteMarkdown::toPlainText('a snake_case_name and _real italic_ text')
        );
    }
}
