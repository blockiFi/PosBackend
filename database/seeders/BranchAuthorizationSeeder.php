<?php

namespace Database\Seeders;

use App\Models\BranchAuthorization;
use App\Models\Business;
use Illuminate\Database\Seeder;

class BranchAuthorizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates branch authorization codes for demo users so that
     * getBusinessDetailsWithBranchAuthorization and generate-auth-codes flow can be tested.
     */
    public function run(): void
    {
        $businesses = Business::with(['branches', 'owner'])->get();

        if ($businesses->isEmpty()) {
            $this->command->warn('No businesses found. Run BusinessSeeder first.');

            return;
        }

        $created = 0;
        foreach ($businesses as $business) {
            $branches = $business->branches;
            $user = $business->owner;

            if ($branches->isEmpty() || ! $user) {
                continue;
            }

            foreach ($branches as $branch) {
                BranchAuthorization::create([
                    'user_id' => $user->id,
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'auth_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                    'expires_at' => now()->addHour(),
                ]);
                $created++;
            }
        }

        $this->command->info("Created {$created} branch authorization(s).");
    }
}
