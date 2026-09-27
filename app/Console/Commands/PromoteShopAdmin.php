<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shop:promote-admin {identifier : Username or phone number of an existing account}')]
#[Description('Give an existing shop account administrator access')]
class PromoteShopAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identifier = trim((string) $this->argument('identifier'));
        $phone = preg_replace('/\D+/', '', $identifier) ?? '';
        if (str_starts_with($phone, '00964')) {
            $phone = substr($phone, 2);
        } elseif (strlen($phone) === 11 && str_starts_with($phone, '07')) {
            $phone = '964'.substr($phone, 1);
        }
        $user = preg_match('/^[+0-9 ()-]+$/', $identifier)
            ? User::where('phone', $phone)->first()
            : User::where('username', mb_strtolower($identifier))->first();
        if (! $user) {
            $this->error('Account not found. Register it on the storefront first.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => 'admin', 'is_active' => true])->save();
        $this->info('Administrator access granted.');

        return self::SUCCESS;
    }
}
