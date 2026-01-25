<?php

namespace App\Services\Senders\Email;

use Resend\Laravel\Facades\Resend;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class SenderEmailService
{
    public const DEFAULT_FROM_EMAIL = 'onboarding@resend.dev';

    /**
     * Send an email via an API provider (Resend).
     */
    public function send(string $toEmail, string $subject, string $htmlBody): bool
    {
        try {
            Resend::emails()->send([
                'from' => config('mail.from.address', self::DEFAULT_FROM_EMAIL),
                'to' => $toEmail,
                'subject' => $subject,
                'html' => $htmlBody,
            ]);

            Log::info("SenderEmailService: Email sent to {$toEmail} | Subject: {$subject}");

            return true;
        } catch (\Exception $e) {
            // Logs the specific Resend error
            Log::error("SenderEmailService Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Render the Blade template for the OTP email.
     */
    public function renderOtpTemplate(string $code, int $expiryMinutes, array $extraData = []): string
    {
        return View::make('emails.otp', array_merge([
            'code' => $code,
            'expiryMinutes' => $expiryMinutes
        ], $extraData))->render();
    }
}
