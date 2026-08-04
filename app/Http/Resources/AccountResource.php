<?php

namespace App\Http\Resources;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->account();

        return [
            'id' => (int) $account->id,
            'platform' => (string) $account->provider,
            // The API contract predates the normalized column names; keep
            // its public keys while reading the persisted attributes.
            'display_name' => $account->account_name,
            'username' => $account->account_username,
            'status' => (string) $account->status,
            'connected' => $account->status === 'connected',
            'last_sync_at' => $account->last_synced_at,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }

    private function account(): SocialAccount
    {
        if (! $this->resource instanceof SocialAccount) {
            throw new LogicException('AccountResource requires a SocialAccount model.');
        }

        return $this->resource;
    }
}
