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
}
