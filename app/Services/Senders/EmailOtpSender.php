<?php

namespace App\Services\Senders;

use App\Interfaces\OtpSenderInterface;
use App\Models\User;
use App\Services\Senders\Email\SenderEmailService;

class EmailOtpSender implements OtpSenderInterface
{
    protected SenderEmailService $emailService;

    public function __construct(SenderEmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    public function send(User $user, string $code, int $expiryMinutes): void
    {
        // Render the Blade View
        $htmlBody = $this->emailService->renderOtpTemplate($code, $expiryMinutes);

        // Send via API
        $this->emailService->send(
            $user->email,
            __('emails.otp_subject'),
            $htmlBody
        );
    }
}
