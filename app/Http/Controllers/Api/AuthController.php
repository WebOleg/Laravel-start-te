<?php

/**
 * Handles API authentication (login/logout) with 2FA support.
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BackupCodesService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected OtpService $otpService;
    protected BackupCodesService $backupCodesService;

    public function __construct(
        OtpService $otpService,
        BackupCodesService $backupCodesService
    ) {
        $this->otpService = $otpService;
        $this->backupCodesService = $backupCodesService;
    }

    /**
     * Login user and handle 2FA states.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        // Validate Credentials
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if 2FA is NOT enabled
        if (!$user->hasTwoFactorEnabled()) {
            return $this->respondWithToken($user, 'authenticated');
        }

        // Check if 2FA Setup is Required (First Time)
        if ($user->needsTwoFactorSetup()) {
            // Generate fresh backup codes for the user to save
            $codes = $this->backupCodesService->generate($user);

            return response()->json([
                'status' => 'setup_required',
                'message' => '2FA setup required. Please save your backup codes.',
                'user_id' => $user->id,
                'email' => $user->email, // Sent back so client can use it for next step
                'backup_codes' => $codes,
            ]);
        }

        // Standard 2FA Flow (Send OTP)
        $result = $this->otpService->generateAndSend($user);

        if (! $result['success']) {
            return response()->json([
                'status' => 'rate_limited',
                'message' => $result['message'],
            ], 429);
        }

        return response()->json([
            'status' => 'otp_required',
            'message' => 'OTP sent to your email.',
            'user_id' => $user->id,
            'email' => $user->email,
            'email_masked' => $this->maskEmail($user->email),
        ]);
    }

    /**
     * Complete the 2FA setup (User acknowledges saving codes).
     */
    public function setup2fa(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->firstOrFail();

        if (! $user->needsTwoFactorSetup()) {
            return response()->json(['message' => '2FA is already set up.'], 400);
        }

        $user->completeTwoFactorSetup();

        return $this->respondWithToken($user, 'authenticated');
    }

    /**
     * Verify the 6-digit OTP code.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        $result = $this->otpService->verify($user, $request->code);

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'code' => [$result['message']],
            ]);
        }

        return $this->respondWithToken($user, 'authenticated');
    }

    /**
     * Verify a backup code (Recovery flow).
     */
    public function verifyBackupCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        $isValid = $this->backupCodesService->verify($user, $request->code);

        if (! $isValid) {
            throw ValidationException::withMessages([
                'code' => ['Invalid backup code.'],
            ]);
        }

        return $this->respondWithToken($user, 'authenticated');
    }

    /**
     * Resend the OTP code.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->firstOrFail();

        $result = $this->otpService->generateAndSend($user);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message']
            ], 429);
        }

        return response()->json([
            'message' => 'OTP resent successfully.',
        ]);
    }

    /**
     * Logout user and revoke token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Get current authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'created_at' => $request->user()->created_at,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Issue API Token and return formatted response.
     */
    protected function respondWithToken(User $user, string $status): JsonResponse
    {
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => $status,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Mask email address for privacy (j***@example.com).
     */
    protected function maskEmail(string $email): string
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];

        $visibleLen = floor(strlen($name) / 2);
        $visibleLen = max(1, min(3, $visibleLen));

        $maskedName = substr($name, 0, $visibleLen) . '***';

        return $maskedName . '@' . $domain;
    }
}
