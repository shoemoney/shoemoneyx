<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exchange\ExchangeRegistry;
use App\Models\ExchangeCredential;
use Illuminate\Console\Command;

class ExchangeCredentialsCommand extends Command
{
    protected $signature = 'exchange:credentials {id : Exchange id, e.g. kraken}';

    protected $description = 'Store (encrypted) the API credentials for an exchange adapter';

    public function handle(ExchangeRegistry $registry): int
    {
        $id = (string) $this->argument('id');

        try {
            $credentials = $registry->make($id)->credentials();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $values = [];
        foreach ($credentials->fields() as $field) {
            $values[$field['key']] = (string) ($field['secret']
                ? $this->secret($field['label'].' (leave blank to skip)')
                : $this->ask($field['label']));
        }

        $errors = $credentials->validate($values);
        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->error("{$key}: {$message}");
            }

            return self::FAILURE;
        }

        ExchangeCredential::updateOrCreate(['exchange' => $id], ['values' => $values]);
        $this->info("stored encrypted credentials for {$id}");

        return self::SUCCESS;
    }
}
