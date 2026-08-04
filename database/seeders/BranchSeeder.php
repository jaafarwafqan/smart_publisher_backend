<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Seed initial branches.
     */
    public function run(): void
    {
        Branch::query()->updateOrCreate(
            ['code' => 'HQ'],
            ['name' => 'Headquarters', 'is_active' => true]
        );

        Branch::query()->updateOrCreate(
            ['code' => 'BR-01'],
            ['name' => 'Branch 01', 'is_active' => true]
        );
    }
}
