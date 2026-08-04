<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'locale' => (string) ($this['locale'] ?? config('app.locale')),
            'timezone' => (string) ($this['timezone'] ?? config('app.timezone', 'UTC')),
            'date_format' => (string) ($this['date_format'] ?? 'Y-m-d'),
            'time_format' => (string) ($this['time_format'] ?? 'H:i'),
            'features' => (array) ($this['features'] ?? []),
        ];
    }
}
