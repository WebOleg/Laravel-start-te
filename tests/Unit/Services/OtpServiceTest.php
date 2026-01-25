<?php

namespace Tests\Unit\Services;

use App\Interfaces\OtpSenderInterface;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OtpService $otpService;
    protected MockInterface $senderMock;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->senderMock = Mockery::mock(OtpSenderInterface::class);
        $this->app->instance(OtpSenderInterface::class, $this->senderMock);
        $this->otpService = app(OtpService::class);

        Cache::flush();
    }

    public function test_it_generates_otp_stores_hash_and_calls_sender()
    {
        $this->senderMock
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($user, $code, $expiry) {
                return $user->id === $this->user->id
                    && strlen($code) === 6
                    && is_numeric($code)
                    && $expiry === OtpCode::EXPIRY_MINUTES;
            });

        $result = $this->otpService->generateAndSend($this->user);

        $this->assertTrue($result['success']);
        // Changed to literal string
        $this->assertEquals('OTP sent successfully.', $result['message']);

        $this->assertDatabaseCount('otp_codes', 1);
        $otpRecord = OtpCode::first();
        $this->assertEquals($this->user->id, $otpRecord->user_id);
        $this->assertNotEquals('123456', $otpRecord->code);
    }

    public function test_it_prevents_sending_if_rate_limited()
    {
        $this->senderMock->shouldReceive('send')->times(5);

        for ($i = 0; $i < 5; $i++) {
            $this->otpService->generateAndSend($this->user);
        }

        $result = $this->otpService->generateAndSend($this->user);

        $this->assertFalse($result['success']);
        // Changed to literal string
        $this->assertEquals('Too many OTP requests. Please try again later.', $result['message']);
        $this->assertTrue(Cache::has("otp_rate_limit:{$this->user->id}"));
    }

    // ==========================================
    // Verification Tests
    // ==========================================

    public function test_verification_succeeds_with_valid_code()
    {
        $plainTextCode = '123456';

        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make($plainTextCode),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0
        ]);

        $result = $this->otpService->verify($this->user, $plainTextCode);

        $this->assertTrue($result['success']);
        // Changed to literal string
        $this->assertEquals('Verification successful.', $result['message']);
        $this->assertDatabaseMissing('otp_codes', ['user_id' => $this->user->id]);
    }

    public function test_verification_fails_with_invalid_code_and_increments_attempts()
    {
        $otp = OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('888888'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0
        ]);

        $result = $this->otpService->verify($this->user, '999999');

        $this->assertFalse($result['success']);
        // Changed to literal string
        $this->assertEquals('Invalid code.', $result['message']);
        $this->assertDatabaseHas('otp_codes', [
            'id' => $otp->id,
            'attempts' => 1
        ]);
    }

    public function test_verification_fails_if_expired()
    {
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('123456'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->subMinute(),
        ]);

        $result = $this->otpService->verify($this->user, '123456');

        $this->assertFalse($result['success']);
        // Changed to literal string
        $this->assertEquals('Code invalid or expired.', $result['message']);
    }

    public function test_verification_fails_if_max_attempts_exceeded()
    {
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('123456'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 5
        ]);

        $result = $this->otpService->verify($this->user, '123456');

        $this->assertFalse($result['success']);
        // Changed to literal string
        $this->assertEquals('Maximum verification attempts exceeded.', $result['message']);
    }

    public function test_verification_fails_if_purpose_mismatch()
    {
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('123456'),
            'purpose' => OtpCode::PURPOSE_RECOVERY,
            'expires_at' => now()->addMinutes(10),
        ]);

        $result = $this->otpService->verify($this->user, '123456', OtpCode::PURPOSE_LOGIN);

        $this->assertFalse($result['success']);
        // Changed to literal string
        $this->assertEquals('Code invalid or expired.', $result['message']);
    }
}
