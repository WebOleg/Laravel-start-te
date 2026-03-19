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
use OpenApi\Annotations as OA;

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
     *
     * @OA\Post(
     *     path="/api/login",
     *     summary="Login",
     *     description="Authenticates a user with email and password. Returns one of three possible states: direct authentication (no 2FA), 2FA setup required (first time — includes backup codes), or OTP required (standard 2FA flow — sends OTP to email).",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Authentication result (one of three states)",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"authenticated", "setup_required", "otp_required"}, example="authenticated"),
     *             @OA\Property(property="token", type="string", nullable=true, description="Bearer token (only when status=authenticated)", example="1|abc123..."),
     *             @OA\Property(property="user", type="object", nullable=true,
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com")
     *             ),
     *             @OA\Property(property="message", type="string", nullable=true, example="OTP sent to your email."),
     *             @OA\Property(property="user_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="email", type="string", nullable=true, example="admin@example.com"),
     *             @OA\Property(property="email_masked", type="string", nullable=true, example="adm***@example.com"),
     *             @OA\Property(property="backup_codes", type="array", nullable=true, description="Only when status=setup_required", @OA\Items(type="string", example="A1B2-C3D4"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The provided credentials are incorrect."),
     *             @OA\Property(property="errors", type="object", @OA\Property(property="email", type="array", @OA\Items(type="string")))
     *         )
     *     ),
     *     @OA\Response(response=429, description="OTP rate limited")
     * )
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
     * Complete the 2FA setup.
     *
     * @OA\Post(
     *     path="/api/auth/setup-2fa",
     *     summary="Complete 2FA setup",
     *     description="Completes the 2FA setup after the user has saved their backup codes. Returns a bearer token upon success. Only valid when the user's 2FA status is 'setup required'.",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="2FA setup completed, token issued",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="authenticated"),
     *             @OA\Property(property="token", type="string", example="1|abc123..."),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="2FA already set up"),
     *     @OA\Response(response=404, description="User not found")
     * )
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
     * Verify OTP code.
     *
     * @OA\Post(
     *     path="/api/auth/verify-otp",
     *     summary="Verify OTP code",
     *     description="Verifies the 6-digit OTP code sent to the user's email during login. Returns a bearer token upon success. Codes expire after a configured time and have a maximum attempt limit.",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "code"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com"),
     *             @OA\Property(property="code", type="string", minLength=6, maxLength=6, example="123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OTP verified, token issued",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="authenticated"),
     *             @OA\Property(property="token", type="string", example="1|abc123..."),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Invalid or expired code",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid code."),
     *             @OA\Property(property="errors", type="object", @OA\Property(property="code", type="array", @OA\Items(type="string")))
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
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
     * Verify backup code (recovery flow).
     *
     * @OA\Post(
     *     path="/api/auth/verify-backup-code",
     *     summary="Verify backup code",
     *     description="Verifies a single-use backup code for account recovery when OTP is unavailable. Each backup code can only be used once. Returns a bearer token upon success.",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "code"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com"),
     *             @OA\Property(property="code", type="string", description="Backup code in XXXX-XXXX format", example="A1B2-C3D4")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Backup code verified, token issued",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="authenticated"),
     *             @OA\Property(property="token", type="string", example="1|abc123..."),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Invalid backup code",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid backup code."),
     *             @OA\Property(property="errors", type="object", @OA\Property(property="code", type="array", @OA\Items(type="string")))
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
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
     * Resend OTP code.
     *
     * @OA\Post(
     *     path="/api/auth/resend-otp",
     *     summary="Resend OTP code",
     *     description="Resends a new OTP code to the user's email. Invalidates any previously sent code. Rate limited to 5 requests per 15 minutes.",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OTP resent",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="OTP resent successfully.")
     *         )
     *     ),
     *     @OA\Response(response=429, description="Rate limited", @OA\JsonContent(@OA\Property(property="message", type="string", example="Too many OTP requests. Please try again later."))),
     *     @OA\Response(response=404, description="User not found")
     * )
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
     * Logout and revoke token.
     *
     * @OA\Post(
     *     path="/api/logout",
     *     summary="Logout",
     *     description="Revokes the current access token.",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logged out",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logged out")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Get current authenticated user.
     *
     * @OA\Get(
     *     path="/api/user",
     *     summary="Get current user",
     *     description="Returns the currently authenticated user's basic information.",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Current user",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Admin"),
     *                 @OA\Property(property="email", type="string", example="admin@example.com"),
     *                 @OA\Property(property="created_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
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
