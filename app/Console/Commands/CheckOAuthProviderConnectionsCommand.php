<?php

namespace App\Console\Commands;

use App\Infrastructure\ExternalServices\SocialOAuth\OAuthConnectionTester;
use Illuminate\Console\Command;

class CheckOAuthProviderConnectionsCommand extends Command
{
    protected $signature = 'oauth-providers:health-check';

    protected $description = 'Re-verify each configured OAuth provider\'s app-level credentials and record the result.';

    public function handle(OAuthConnectionTester $connectionTester): int
    {
        foreach (OAuthConnectionTester::PROVIDERS as $provider) {
            if (! $connectionTester->isConfigured($provider)) {
                $this->line("Skipped {$provider}: no Client ID/Secret configured.");

                continue;
            }

            // No acting admin for a scheduled check — recorded with a null
            // user so the audit log reads as a system-triggered entry.
            $result = $connectionTester->test($provider, null);

            if ($result['success']) {
                $this->info("{$provider}: OK");
            } else {
                $this->error("{$provider}: {$result['message']}");
            }
        }

        return self::SUCCESS;
    }
}
