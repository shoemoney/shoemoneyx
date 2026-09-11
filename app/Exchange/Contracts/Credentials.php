<?php

declare(strict_types=1);

namespace App\Exchange\Contracts;

/** Describes and validates the credential fields an exchange adapter needs to store. */
interface Credentials
{
    /** @return array<int, array{key:string, label:string, secret:bool}> */
    public function fields(): array;

    /** @return array<string, string> field key -> error message; empty means valid */
    public function validate(array $values): array;
}
