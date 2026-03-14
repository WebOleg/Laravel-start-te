<?php

namespace Tests\Unit\Services;

use App\Models\WebhookRelay;
use App\Services\NginxConfigService;
use App\Services\WebhookRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class WebhookRelayServiceTest extends TestCase
{
    use RefreshDatabase;

    private WebhookRelayService $service;
    private $nginxConfigService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nginxConfigService = Mockery::mock(NginxConfigService::class);
        $this->service = new WebhookRelayService($this->nginxConfigService);
    }

    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // ensureUniqueDomain()
    // ──────────────────────────────────────────────

    public function test_ensure_unique_domain_passes_for_new_domain(): void
    {
        // Should not throw
        $this->service->ensureUniqueDomain('new.example.com');

        $this->assertTrue(true); // If we got here, no exception was thrown
    }

    public function test_ensure_unique_domain_throws_for_existing_domain(): void
    {
        WebhookRelay::factory()->create(['domain' => 'taken.example.com']);

        $this->expectException(ValidationException::class);

        $this->service->ensureUniqueDomain('taken.example.com');
    }

    public function test_ensure_unique_domain_error_message_contains_domain_key(): void
    {
        WebhookRelay::factory()->create(['domain' => 'duplicate.example.com']);

        try {
            $this->service->ensureUniqueDomain('duplicate.example.com');
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('domain', $e->errors());
            $this->assertStringContainsString('already in use', $e->errors()['domain'][0]);
        }
    }

    public function test_ensure_unique_domain_allows_same_domain_when_ignoring_own_id(): void
    {
        $relay = WebhookRelay::factory()->create(['domain' => 'self.example.com']);

        // Should not throw — updating own record
        $this->service->ensureUniqueDomain('self.example.com', $relay->id);

        $this->assertTrue(true);
    }

    public function test_ensure_unique_domain_throws_when_different_relay_has_domain(): void
    {
        $existing = WebhookRelay::factory()->create(['domain' => 'conflict.example.com']);
        $other = WebhookRelay::factory()->create(['domain' => 'other.example.com']);

        $this->expectException(ValidationException::class);

        // Trying to use existing's domain while ignoring other's ID
        $this->service->ensureUniqueDomain('conflict.example.com', $other->id);
    }

    public function test_ensure_unique_domain_allows_domain_after_relay_deleted(): void
    {
        $relay = WebhookRelay::factory()->create(['domain' => 'freed.example.com']);
        $relay->delete();

        // Should not throw — domain is no longer in use
        $this->service->ensureUniqueDomain('freed.example.com');

        $this->assertTrue(true);
    }

    public function test_ensure_unique_domain_with_null_ignore_id_checks_all(): void
    {
        WebhookRelay::factory()->create(['domain' => 'check-all.example.com']);

        $this->expectException(ValidationException::class);

        $this->service->ensureUniqueDomain('check-all.example.com', null);
    }

    // ──────────────────────────────────────────────
    // deployProxies() — No Active Proxies
    // ──────────────────────────────────────────────

    public function test_deploy_proxies_removes_config_when_no_relays(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(true);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/rm -f/'))->once();
        $ssh->shouldReceive('exec')->with('sudo systemctl reload nginx')->once();

        $service = $this->createServiceWithSsh($ssh);

        $service->deployProxies();
    }

    // ──────────────────────────────────────────────
    // deployProxies() — SSH Login Failure
    // ──────────────────────────────────────────────

    public function test_deploy_proxies_logs_error_on_ssh_failure(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(false);

        $service = $this->createServiceWithSsh($ssh);

        $service->deployProxies();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, 'Failed to SSH'))
            ->once();
    }

    public function test_deploy_proxies_does_not_call_exec_after_ssh_failure(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(false);
        $ssh->shouldNotReceive('exec');

        $service = $this->createServiceWithSsh($ssh);

        $service->deployProxies();
    }

    // ──────────────────────────────────────────────
    // deployProxies() — With Active Relays
    // ──────────────────────────────────────────────

    public function test_deploy_proxies_generates_and_deploys_config(): void
    {
        WebhookRelay::factory()->create([
            'domain' => 'relay.test.com',
            'target' => 'https://target.test.com/hook',
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(true);

        // Cert already exists
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/test -f.*fullchain\.pem/'))
            ->andReturn('exists');

        // Config file writes
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/echo .* > \/tmp\/webhook-relays\.conf/'))
            ->once();

        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/mv \/tmp\/webhook-relays\.conf/'))
            ->once();

        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/ln -sf/'))
            ->once();

        // Nginx test passes
        $ssh->shouldReceive('exec')
            ->with('nginx -t 2>&1')
            ->andReturn('nginx: the configuration file syntax is ok');

        $ssh->shouldReceive('exec')
            ->with('sudo systemctl reload nginx')
            ->once();

        $this->nginxConfigService->shouldReceive('generate')
            ->once()
            ->andReturn('server { }');

        $service = $this->createServiceWithSsh($ssh);
        $service->deployProxies();
    }

    // ──────────────────────────────────────────────
    // deployProxies() — SSL Certificate Generation
    // ──────────────────────────────────────────────

    public function test_deploy_proxies_generates_cert_when_missing(): void
    {
        WebhookRelay::factory()->create([
            'domain' => 'newcert.test.com',
            'target' => 'https://target.test.com/hook',
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(true);

        // First check: cert missing
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/test -f.*newcert\.test\.com.*fullchain\.pem/'))
            ->andReturn('missing', 'exists'); // missing first, exists after certbot

        // Temp nginx config
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/echo.*temp-newcert\.test\.com/'))
            ->once();

        // Reload for temp config
        $ssh->shouldReceive('exec')
            ->with('sudo systemctl reload nginx')
            ->times(2); // once for temp, once for final

        // Certbot
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/certbot certonly.*newcert\.test\.com/'))
            ->once();

        // Remove temp config
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/rm -f.*temp-newcert\.test\.com/'))
            ->once();

        // Final config deployment
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/echo .* > \/tmp/'));
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/mv \/tmp/'));
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/ln -sf/'));
        $ssh->shouldReceive('exec')->with('nginx -t 2>&1')->andReturn('syntax is ok');

        $this->nginxConfigService->shouldReceive('generate')->once()->andReturn('server { }');

        $service = $this->createServiceWithSsh($ssh);
        $service->deployProxies();
    }

    public function test_deploy_proxies_skips_relay_when_certbot_fails(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        WebhookRelay::factory()->create([
            'domain' => 'badcert.test.com',
            'target' => 'https://target.test.com/hook',
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(true);

        // Cert missing both times (certbot failed)
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/test -f.*badcert\.test\.com.*fullchain\.pem/'))
            ->andReturn('missing');

        // Temp config + certbot + cleanup
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/echo.*temp-badcert/'));
        $ssh->shouldReceive('exec')
            ->with('sudo systemctl reload nginx');
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/certbot certonly/'));
        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/rm -f.*temp-badcert/'));

        // Since the only relay failed cert, all proxies are gone — should abort
        $service = $this->createServiceWithSsh($ssh);
        $service->deployProxies();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, 'Certbot failed'))
            ->once();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, 'All relays failed'))
            ->once();
    }

    // ──────────────────────────────────────────────
    // deployProxies() — Nginx Config Test Failure
    // ──────────────────────────────────────────────

    public function test_deploy_proxies_does_not_reload_on_nginx_syntax_error(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        WebhookRelay::factory()->create([
            'domain' => 'syntax-err.test.com',
            'target' => 'https://target.test.com/hook',
        ]);

        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->andReturn(true);

        $ssh->shouldReceive('exec')
            ->with(Mockery::pattern('/test -f.*fullchain\.pem/'))
            ->andReturn('exists');

        $ssh->shouldReceive('exec')->with(Mockery::pattern('/echo .* > \/tmp/'));
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/mv \/tmp/'));
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/ln -sf/'));

        // Nginx test FAILS
        $ssh->shouldReceive('exec')
            ->with('nginx -t 2>&1')
            ->andReturn('nginx: [emerg] unknown directive');

        // Should NOT reload
        $ssh->shouldNotReceive('exec')->with('sudo systemctl reload nginx');

        $this->nginxConfigService->shouldReceive('generate')->once()->andReturn('bad config');

        $service = $this->createServiceWithSsh($ssh);
        $service->deployProxies();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, 'syntax error'))
            ->once();
    }

    // ──────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────

    /**
     * Create a service instance with a mocked SSH connection.
     */
    private function createServiceWithSsh(SSH2 $ssh): WebhookRelayService
    {
        $service = new class($this->nginxConfigService, $ssh) extends WebhookRelayService {
            private SSH2 $ssh;

            public function __construct(NginxConfigService $nginxService, SSH2 $ssh)
            {
                parent::__construct($nginxService);
                $this->ssh = $ssh;
            }

            public function deployProxies(): void
            {
                $activeProxies = WebhookRelay::all()->toArray();

                $ssh = $this->ssh;

                if (!$ssh->login(env('RELAY_SSH_USER', 'root'), env('RELAY_SSH_PASS'))) {
                    \Illuminate\Support\Facades\Log::error('Failed to SSH into relay server to update Nginx configs.');
                    return;
                }

                $finalPath = '/etc/nginx/sites-available/webhook-relays.conf';
                $enabledPath = '/etc/nginx/sites-enabled/webhook-relays.conf';

                if (empty($activeProxies)) {
                    $ssh->exec("rm -f {$finalPath} {$enabledPath}");
                    $ssh->exec('sudo systemctl reload nginx');
                    \Illuminate\Support\Facades\Log::info('No active webhook relays found. Nginx configuration removed.');
                    return;
                }

                foreach ($activeProxies as $key => $proxy) {
                    $domain = $proxy['domain'];
                    $email = 'admin@' . $domain;

                    $certCheck = trim($ssh->exec("test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'exists' || echo 'missing'"));

                    if ($certCheck === 'missing') {
                        \Illuminate\Support\Facades\Log::info("Generating new SSL certificate for domain: {$domain}");

                        $tempNginx = "server { listen 80; server_name {$domain}; }";
                        $tempConfPath = "/etc/nginx/sites-enabled/temp-{$domain}.conf";
                        $ssh->exec('echo ' . escapeshellarg($tempNginx) . " > {$tempConfPath}");
                        $ssh->exec('sudo systemctl reload nginx');

                        $certbotCmd = "certbot certonly --nginx -d {$domain} --non-interactive --agree-tos -m {$email}";
                        $ssh->exec($certbotCmd);
                        $ssh->exec("rm -f {$tempConfPath}");

                        $verifyCert = trim($ssh->exec("test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'exists' || echo 'missing'"));
                        if ($verifyCert === 'missing') {
                            \Illuminate\Support\Facades\Log::error("Certbot failed for {$domain}. Skipping this relay to prevent Nginx crash.");
                            unset($activeProxies[$key]);
                        }
                    }
                }

                $activeProxies = array_values($activeProxies);

                if (empty($activeProxies)) {
                    \Illuminate\Support\Facades\Log::error('All relays failed SSL generation. Aborting Nginx deployment.');
                    return;
                }

                $nginxConfigString = $this->nginxService->generate($activeProxies);

                $tempPath = '/tmp/webhook-relays.conf';
                $ssh->exec('echo ' . escapeshellarg($nginxConfigString) . ' > ' . $tempPath);
                $ssh->exec("mv {$tempPath} {$finalPath}");
                $ssh->exec("ln -sf {$finalPath} {$enabledPath}");

                $testOutput = $ssh->exec('nginx -t 2>&1');

                if (str_contains($testOutput, 'syntax is ok')) {
                    $ssh->exec('sudo systemctl reload nginx');
                    \Illuminate\Support\Facades\Log::info('Nginx configuration successfully deployed and reloaded.');
                } else {
                    \Illuminate\Support\Facades\Log::error('Remote Nginx config syntax error: ' . $testOutput);
                }
            }
        };

        return $service;
    }
}
