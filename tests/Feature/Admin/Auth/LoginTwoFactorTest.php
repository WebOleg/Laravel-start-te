<?php

namespace Tests\Feature\Admin\Auth;

use App\Models\User;
use App\Services\BackupCodesService;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class LoginTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $password = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make($this->password),
            'email' => 'test@example.com',
        ]);
    }

    /**
     * First-time user sees backup codes.
     */
    public function test_first_time_user_sees_backup_codes(): void
    {
        // Setup User: 2FA enabled, but setup is required
        $this->user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_setup_required' => true,
        ])->save();

        // Mock the BackupCodesService
        $this->mock(BackupCodesService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn ($arg) => $arg->id === $this->user->id))
                ->andReturn(['111111', '222222', '333333', '444444']);
        });

        // Perform Login
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        // Assertions
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'setup_required',
                'backup_codes' => ['111111', '222222', '333333', '444444'],
            ]);
    }

    /**
     * Entering OTP after 3 failures invalidates the code.
     */
    public function test_otp_invalidation_after_multiple_failures(): void
    {
        $this->user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_setup_required' => false,
        ])->save();

        $this->mock(OtpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verify')->times(3)
                ->andReturn(['success' => false, 'message' => 'Invalid code.']);

            $mock->shouldReceive('verify')->once()
                ->andReturn(['success' => false, 'message' => 'Too many attempts.']);
        });

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/auth/verify-otp', [
                'email' => $this->user->email,
                'code' => '000000',
            ]);

            $response->assertStatus(422);

            if ($i === 3) {
                $response->assertJsonValidationErrors(['code' => 'Too many attempts.']);
            }
        }
    }

    /**
     * Backup code cannot be reused.
     */
    public function test_backup_code_cannot_be_reused(): void
    {
        $this->user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_setup_required' => false,
        ])->save();

        $validCode = 'RECOVERY-CODE-123';

        $this->mock(BackupCodesService::class, function (MockInterface $mock) use ($validCode) {
            $mock->shouldReceive('verify')
                ->with(Mockery::on(fn ($u) => $u->id === $this->user->id), $validCode)
                ->once()
                ->andReturn(true);

            $mock->shouldReceive('verify')
                ->with(Mockery::on(fn ($u) => $u->id === $this->user->id), $validCode)
                ->once()
                ->andReturn(false);
        });

        // First Attempt (Success)
        $this->postJson('/api/auth/verify-backup-code', [
            'email' => $this->user->email,
            'code' => $validCode,
        ])->assertStatus(200);

        // Second Attempt (Fail)
        $this->postJson('/api/auth/verify-backup-code', [
            'email' => $this->user->email,
            'code' => $validCode,
        ])->assertStatus(422);
    }

    /**
     * Returning user gets OTP immediately (no backup codes shown).
     */
    public function test_returning_user_gets_otp_immediately(): void
    {
        $this->user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_setup_required' => false,
        ])->save();

        $this->mock(BackupCodesService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('generate');
        });

        $this->mock(OtpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateAndSend')
                ->once()
                ->andReturn(['success' => true, 'message' => 'OTP sent.', 'code' => '123456']);
        });

        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'otp_required'])
            ->assertJsonMissing(['backup_codes']);
    }

    public function test_verify_otp_success_issues_token(): void
    {
        $this->user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_setup_required' => false,
        ])->save();

        $this->mock(OtpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verify')->once()->andReturn(['success' => true]);
        });

        $this->postJson('/api/auth/verify-otp', [
            'email' => $this->user->email,
            'code' => '123456',
        ])->assertStatus(200)
            ->assertJsonStructure(['token']);
    }
}
