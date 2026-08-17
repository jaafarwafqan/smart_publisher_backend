<?php

namespace App\Support\SocialAccounts;

use App\Models\SocialAccount;

/**
 * @internal Return value of TelegramBotConnector::connect() — a plain
 * value carrier, not a persisted model of its own.
 */
final class TelegramBotConnectionResult
{
    public function __construct(
        public readonly SocialAccount $account,
        public readonly bool $wasUpdate,
    ) {}
}
