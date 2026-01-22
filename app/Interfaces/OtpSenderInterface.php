<?php

namespace App\Interfaces;

use App\Models\User;

interface OtpSenderInterface
{
    public function send(User $user, string $code, int $expiryMinutes): void;
}
