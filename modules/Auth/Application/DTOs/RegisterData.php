<?php

namespace Modules\Auth\Application\DTOs;

final readonly class RegisterData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $deviceName,
    ) {}
}
