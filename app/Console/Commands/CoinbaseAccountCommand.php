<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Models\CoinbaseAccount;
use Illuminate\Console\Command;

class CoinbaseAccountCommand extends Command
{
    protected $signature = 'coinbase:account {--name=Default} {--key= : CDP key name (organizations/.../apiKeys/...)} {--secret= : EC private key PEM} {--test : just test the active account}';

    protected $description = 'Store (encrypted) or test the Coinbase Advanced Trade API key';

    public function handle(CoinbaseService $cb): int
    {
        if (! $this->option('test')) {
            $key = (string) ($this->option('key') ?: config('coinbase.api_key_name') ?: $this->ask('CDP API key name'));
            $secret = (string) ($this->option('secret') ?: config('coinbase.api_private_key') ?: $this->secret('EC private key (PEM, \\n allowed)'));
            if (! $key || ! $secret) {
                $this->error('key and secret required');

                return self::FAILURE;
            }
            CoinbaseAccount::where('is_active', true)->update(['is_active' => false]);
            CoinbaseAccount::create(['name' => (string) $this->option('name'), 'api_key_name' => $key, 'api_private_key' => $secret, 'is_active' => true]);
            $this->info('stored encrypted');
        }

        $acct = CoinbaseAccount::active();
        if (! $acct) {
            $this->error('no active account');

            return self::FAILURE;
        }
        try {
            $perms = $cb->keyPermissions($acct);
            $this->table(['permission', 'value'], collect($perms)->map(fn ($v, $k) => [$k, json_encode($v)])->values()->all());
            $bal = $cb->balances($acct);
            $this->table(['currency', 'available'], collect($bal)->map(fn ($v, $k) => [$k, $v])->values()->all());
        } catch (\Throwable $e) {
            $this->error('API test failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
