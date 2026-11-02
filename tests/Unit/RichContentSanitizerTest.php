<?php

namespace Tests\Unit;

use App\Support\Content\RichContentSanitizer;
use PHPUnit\Framework\TestCase;

class RichContentSanitizerTest extends TestCase
{
    public function test_allowed_attributes_survive_and_disallowed_ones_are_stripped(): void
    {
        $meta = (new RichContentSanitizer)->sanitizeMeta([
            'rich_content' => [
                [
                    'insert' => 'Hello',
                    'attributes' => [
                        'bold' => true,
                        'italic' => true,
                        'underline' => true,
                        'list' => 'bullet',
                        'align' => 'center',
                        'direction' => 'rtl',
                        'link' => 'https://example.test/a',
                        // Not in the allowlist — must be dropped silently.
                        'color' => '#ff0000',
                        'background' => '#000000',
                        'header' => 1,
                        'code-block' => true,
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            'insert' => 'Hello',
            'attributes' => [
                'bold' => true,
                'italic' => true,
                'underline' => true,
                'list' => 'bullet',
                'align' => 'center',
                'direction' => 'rtl',
                'link' => 'https://example.test/a',
            ],
        ], $meta['rich_content'][0]);
    }

    public function test_a_javascript_link_scheme_is_rejected(): void
    {
        $meta = (new RichContentSanitizer)->sanitizeMeta([
            'rich_content' => [
                ['insert' => 'click', 'attributes' => ['link' => 'javascript:alert(1)']],
            ],
        ]);

        $this->assertArrayNotHasKey('attributes', $meta['rich_content'][0]);
    }

    public function test_a_non_string_insert_operation_is_dropped_entirely(): void
    {
        $meta = (new RichContentSanitizer)->sanitizeMeta([
            'rich_content' => [
                ['insert' => ['image' => 'https://example.test/a.png']],
                ['insert' => 'kept'],
            ],
        ]);

        $this->assertCount(1, $meta['rich_content']);
        $this->assertSame('kept', $meta['rich_content'][0]['insert']);
    }

    /**
     * A future change to the accepted Delta subset needs an explicit marker
     * to branch a migration on — see the sanitizer's own docblock.
     */
    public function test_sanitizing_stamps_the_current_schema_version(): void
    {
        $meta = (new RichContentSanitizer)->sanitizeMeta([
            'rich_content' => [['insert' => 'hi']],
        ]);

        $this->assertSame(1, $meta['rich_content_schema_version']);
    }

    public function test_meta_without_rich_content_is_untouched(): void
    {
        $meta = (new RichContentSanitizer)->sanitizeMeta(['some_other_key' => 'value']);

        $this->assertSame(['some_other_key' => 'value'], $meta);
        $this->assertArrayNotHasKey('rich_content_schema_version', $meta);
    }
}
