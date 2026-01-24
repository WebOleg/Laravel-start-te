<?php

namespace App\Services;

use App\Interfaces\OtpSenderInterface;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    protected const RATE_LIMIT_ATTEMPTS = 5;
    protected const RATE_LIMIT_MINUTES = 15;

    protected OtpSenderInterface $sender;

    // Inject the Interface here
    public function __construct(OtpSenderInterface $sender)
    {
        $this->sender = $sender;
    }

    public function generateAndSend(User $user, string $purpose = OtpCode::PURPOSE_LOGIN): array
    {
        if ($this->isRateLimited($user->id)) {
            return [
                'success' => false,
                'message' => __('otp.rate_limited'),
            ];
        }

        $this->invalidateExisting($user->id, $purpose);

        // Generate 6-digit code
        $plaintextCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store hashed code
        OtpCode::create([
            'user_id' => $user->id,
            'code' => Hash::make($plaintextCode),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(OtpCode::EXPIRY_MINUTES),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->sender->send($user, $plaintextCode, OtpCode::EXPIRY_MINUTES);

        $this->incrementRateLimit($user->id);

        return [
            'success' => true,
            'code' => $plaintextCode,
            'message' => __('otp.sent'),
        ];
    }

    public function verify(User $user, string $code, string $purpose = OtpCode::PURPOSE_LOGIN): array
    {
        $otpRecord = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return ['success' => false, 'message' => __('otp.invalid_or_expired')];
        }

        if ($otpRecord->hasExceededAttempts()) {
            return ['success' => false, 'message' => __('otp.max_attempts')];
        }

        if (! Hash::check($code, $otpRecord->code)) {
            $otpRecord->incrementAttempts();
            return ['success' => false, 'message' => __('otp.invalid_code')];
        }

        $otpRecord->delete();

        return ['success' => true, 'message' => __('otp.verified')];
    }

    protected function invalidateExisting(int $userId, string $purpose): void
    {
        OtpCode::where('user_id', $userId)->where('purpose', $purpose)->delete();
    }

    protected function isRateLimited(int $userId): bool
    {
        return Cache::get("otp_rate_limit:{$userId}", 0) >= self::RATE_LIMIT_ATTEMPTS;
    }

    protected function incrementRateLimit(int $userId): void
    {
        $key = "otp_rate_limit:{$userId}";
        if (!Cache::has($key)) {
            Cache::put($key, 1, now()->addMinutes(self::RATE_LIMIT_MINUTES));
        } else {
            Cache::increment($key);
        }
    }
}
