<?php

namespace App\Services;

use App\Models\WebhookRelay;
use Illuminate\Validation\ValidationException;
use phpseclib3\Net\SSH2;

class WebhookRelayService
{
    protected NginxConfigService $nginxService;

    public function __construct(NginxConfigService $nginxService)
    {
        $this->nginxService = $nginxService;
    }

    public function ensureUniqueDomain(string $domain, ?int $ignoreId = null): void
    {
        $query = WebhookRelay::where('domain', $domain);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'domain' => ['This relay domain is already in use.']
            ]);
        }
    }

    public function deployProxies(): void
    {
        // Fetch all active proxies from the database
        $activeProxies = WebhookRelay::all()->toArray();

        // Connect to the remote relay server first
        $sshHost = env('RELAY_SSH_HOST');
        $sshUser = env('RELAY_SSH_USER', 'root');
        $sshPass = env('RELAY_SSH_PASS');

        $ssh = new SSH2($sshHost);
        if (!$ssh->login($sshUser, $sshPass)) {
            \Log::error('Failed to SSH into relay server to update Nginx configs.');
            return;
        }

        $finalPath = '/etc/nginx/sites-available/webhook-relays.conf';
        $enabledPath = '/etc/nginx/sites-enabled/webhook-relays.conf';

        // Check if there are no proxies to deploy
        if (empty($activeProxies)) {
            // Remove the configuration file and the symlink
            $ssh->exec("rm -f {$finalPath} {$enabledPath}");
            \Log::info('No active webhook relays found. Nginx configuration removed.');
        } else {
            // Generate the Nginx config string
            $nginxConfigString = $this->nginxService->generate($activeProxies);

            $tempPath = '/tmp/webhook-relays.conf';
            $ssh->exec('echo ' . escapeshellarg($nginxConfigString) . ' > ' . $tempPath);
            $ssh->exec("mv {$tempPath} {$finalPath}");
            $ssh->exec("ln -sf {$finalPath} {$enabledPath}");
        }

        // Test Nginx configuration
        $testOutput = $ssh->exec('nginx -t 2>&1');

        // Reload Nginx whether files were created or deleted
        if (str_contains($testOutput, 'syntax is ok')) {
            $ssh->exec('systemctl reload nginx');
        } else {
            \Log::error('Remote Nginx config syntax error: ' . $testOutput);
        }
    }
}
