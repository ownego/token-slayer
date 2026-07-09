<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Assign every unassigned user into shared accounts of at most 5 members.
     */
    public function run(): void
    {
        $unassigned = User::whereNull('account_id')->get();

        foreach ($unassigned->chunk(5) as $index => $chunk) {
            $account = Account::create([
                'email' => 'account'.($index + 1).'@example.com',
                'plan' => 'max-20x',
            ]);

            User::whereIn('id', $chunk->pluck('id'))->update(['account_id' => $account->id]);
        }
    }
}
