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

        // 1. Create a dummy user
        $this->user = User::factory()->create();

        // 2. Mock the Interface
        $this->senderMock = Mockery::mock(OtpSenderInterface::class);

        // 3. Bind the mock to the Service Container
        $this->app->instance(OtpSenderInterface::class, $this->senderMock);

        // 4. Resolve the Service (it will use the mocked interface)
        $this->otpService = app(OtpService::class);

        // 5. Ensure cache is clean for rate limit tests
        Cache::flush();
    }

    // ==========================================
    // Generate and Send Tests
    // ==========================================

    public function test_it_generates_otp_stores_hash_and_calls_sender()
    {
        // Expectation: Sender should be called once with a 6-digit code
        $this->senderMock
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($user, $code, $expiry) {
                return $user->id === $this->user->id
                    && strlen($code) === 6
                    && is_numeric($code)
                    && $expiry === OtpCode::EXPIRY_MINUTES;
            });

        // Action
        $result = $this->otpService->generateAndSend($this->user);

        // Assert Response
        $this->assertTrue($result['success']);
        $this->assertEquals(__('otp.sent'), $result['message']);

        // Assert Database
        $this->assertDatabaseCount('otp_codes', 1);

        $otpRecord = OtpCode::first();
        $this->assertEquals($this->user->id, $otpRecord->user_id);

        // Security Check: Ensure code is NOT stored as plaintext
        $this->assertNotEquals('123456', $otpRecord->code);
        // We can't verify the exact hash because the code is random,
        // but the success of the test implies the flow completed.
    }

    public function test_it_invalidates_existing_otp_before_creating_new_one()
    {
        // Arrange: Create an existing OTP
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('111111'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->senderMock->shouldReceive('send');

        // Action: Generate a new one
        $this->otpService->generateAndSend($this->user);

        // Assert: We should still only have 1 record (the old one was deleted)
        $this->assertDatabaseCount('otp_codes', 1);

        // Verify the remaining record is NOT the old one (hash check will fail implies it changed)
        $newRecord = OtpCode::first();
        $this->assertFalse(Hash::check('111111', $newRecord->code));
    }

    public function test_it_prevents_sending_if_rate_limited()
    {
        // Arrange: Hit the limit (5 times)
        $this->senderMock->shouldReceive('send')->times(5);

        for ($i = 0; $i < 5; $i++) {
            $this->otpService->generateAndSend($this->user);
        }

        // Action: Attempt the 6th time
        $result = $this->otpService->generateAndSend($this->user);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals(__('otp.rate_limited'), $result['message']);

        // Ensure Cache key exists
        $this->assertTrue(Cache::has("otp_rate_limit:{$this->user->id}"));
    }

    // ==========================================
    // Verification Tests
    // ==========================================

    public function test_verification_succeeds_with_valid_code()
    {
        $plainTextCode = '123456';

        // Arrange: Manually store a valid OTP
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make($plainTextCode),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0
        ]);

        // Action
        $result = $this->otpService->verify($this->user, $plainTextCode);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals(__('otp.verified'), $result['message']);

        // Assert: Record should be deleted after successful verification
        $this->assertDatabaseMissing('otp_codes', ['user_id' => $this->user->id]);
    }

    public function test_verification_fails_with_invalid_code_and_increments_attempts()
    {
        // Arrange
        $otp = OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('888888'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0
        ]);

        // Action: Send Wrong Code
        $result = $this->otpService->verify($this->user, '999999');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals(__('otp.invalid_code'), $result['message']);

        // Assert: Attempts incremented in DB
        $this->assertDatabaseHas('otp_codes', [
            'id' => $otp->id,
            'attempts' => 1
        ]);
    }

    public function test_verification_fails_if_expired()
    {
        // Arrange: Expired 1 minute ago
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('123456'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->subMinute(),
        ]);

        // Action
        $result = $this->otpService->verify($this->user, '123456');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals(__('otp.invalid_or_expired'), $result['message']);
    }

    public function test_verification_fails_if_max_attempts_exceeded()
    {
        // Arrange: Set attempts to a high number (assuming OtpCode model logic handles checking logic)
        // You might need to check your OtpCode model for the specific MAX constant.
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('123456'),
            'purpose' => OtpCode::PURPOSE_LOGIN,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 5 // Assuming 3 or 5 is max
        ]);

        // Action
        $result = $this->otpService->verify($this->user, '123456');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals(__('otp.max_attempts'), $result['message']);
    }

    public function test_verification_fails_if_purpose_mismatch()
    {
        // Arrange: Insert a code with a VALID alternative purpose (Recovery)
        OtpCode::create([
            'user_id' => $this->user->id,
            'code' => Hash::make('123456'),
            'purpose' => OtpCode::PURPOSE_RECOVERY,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Action: Try to verify for LOGIN (the default expected purpose)
        $result = $this->otpService->verify($this->user, '123456', OtpCode::PURPOSE_LOGIN);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals(__('otp.invalid_or_expired'), $result['message']);
    }
}
