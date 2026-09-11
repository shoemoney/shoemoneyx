<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Exchange\Coinbase\Api\CoinbaseJwtService;
use App\Exchange\Contracts\Credentials;

/** Describes and validates the CDP API key pair coinbase:account stores. */
final class CoinbaseCredentials implements Credentials
{
    public function fields(): array
    {
        return [
            ['key' => 'api_key_name', 'label' => 'CDP API key name', 'secret' => false],
            ['key' => 'api_private_key', 'label' => 'EC private key (PEM)', 'secret' => true],
        ];
    }

    public function validate(array $values): array
    {
        $errors = [];

        if (empty($values['api_key_name'])) {
            $errors['api_key_name'] = 'required';
        }

        if (empty($values['api_private_key'])) {
            $errors['api_private_key'] = 'required';
        } else {
            try {
                CoinbaseJwtService::keyMaterial((string) $values['api_private_key']);
            } catch (\InvalidArgumentException $e) {
                $errors['api_private_key'] = $e->getMessage();
            }
        }

        return $errors;
    }
}
