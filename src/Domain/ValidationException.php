<?php
declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors campo => mensagem */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
