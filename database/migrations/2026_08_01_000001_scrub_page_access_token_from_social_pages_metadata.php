<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FacebookOAuthProvider::listPages() used to embed the page-scoped
     * access_token inside social_pages.metadata even though nothing ever
     * reads it back (publishing uses the account-level token) — pure
     * credential exposure. This scrubs any values already persisted before
     * the provider stopped capturing it.
     */
    public function up(): void
    {
        DB::table('social_pages')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->each(function (object $row): void {
                $metadata = json_decode((string) $row->metadata, true);

                if (! is_array($metadata) || ! array_key_exists('page_access_token', $metadata)) {
                    return;
                }

                unset($metadata['page_access_token']);

                DB::table('social_pages')
                    ->where('id', $row->id)
                    ->update(['metadata' => json_encode($metadata)]);
            });
    }

    public function down(): void
    {
        // Irreversible: the stripped tokens are not recoverable, and
        // shouldn't be re-persisted even if they were (see up() note).
    }
};
