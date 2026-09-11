<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use App\Exchange\Contracts\Credentials;

/** The three fields ccxt's constructor understands across every venue it wraps. */
final class CcxtCredentials implements Credentials
{
    public function fields(): array
    {
        return [
            ['key' => 'apiKey', 'label' => 'API key', 'secret' => false],
            ['key' => 'secret', 'label' => 'API secret', 'secret' => true],
            ['key' => 'password', 'label' => 'Passphrase, only some exchanges', 'secret' => true],
        ];
    }

    public function validate(array $values): array
    {
        $errors = [];

        foreach (['apiKey', 'secret'] as $key) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                $errors[$key] = 'required';
            }
        }

        return $errors;
    }
}
