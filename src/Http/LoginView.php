<?php

declare(strict_types=1);

namespace App\Http;

final readonly class LoginView
{
    public function __construct(
        public string $action,
        public string $aviso,
        public string $bloqueio,
        public string $csrfToken,
        public string $csrfField,
        public string $field,
    ) {
    }
}
