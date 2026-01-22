<?php

namespace App\Services\Senders\Email;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class SenderEmailService
{
    /**
     * Send an email via an API provider.
     */
    public function send(string $toEmail, string $subject, string $htmlBody): bool
    {
        try {
           // todo - implementation
            Log::info("SenderEmailService: Email sent to {$toEmail} | Subject: {$subject}");

            return true;
        } catch (\Exception $e) {
            Log::error("SenderEmailService Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Render the Blade template for the OTP email.
     */
    public function renderOtpTemplate(string $code, int $expiryMinutes): string
    {
        return View::make('emails.otp', [
            'code' => $code,
            'expiryMinutes' => $expiryMinutes
        ])->render();
    }
}
