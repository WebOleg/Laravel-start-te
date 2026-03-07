<?php

namespace App\Services;

use App\Models\WebhookRelay;
use Illuminate\Validation\ValidationException;
use phpseclib3\Net\SSH2;
use Illuminate\Support\Facades\Log;

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

        // Connect to the remote relay server
        $sshHost = env('RELAY_SSH_HOST');
        $sshUser = env('RELAY_SSH_USER', 'root');
        $sshPass = env('RELAY_SSH_PASS');

        $ssh = new SSH2($sshHost);
        if (!$ssh->login($sshUser, $sshPass)) {
            Log::error('Failed to SSH into relay server to update Nginx configs.');
            return;
        }

        // Create a sudo prefix that pipes the password in so it doesn't freeze asking for one
        // The '-p ""' prevents sudo from printing the password prompt text to our output logs
        $sudo = "echo " . escapeshellarg($sshPass) . " | sudo -S -p '' ";

        $finalPath = '/etc/nginx/sites-available/webhook-relays.conf';
        $enabledPath = '/etc/nginx/sites-enabled/webhook-relays.conf';

        if (empty($activeProxies)) {
            // Use $sudo here
            $ssh->exec("{$sudo}rm -f {$finalPath} {$enabledPath}");
            $ssh->exec("{$sudo}/usr/bin/systemctl reload nginx");
            Log::info('No active webhook relays found. Nginx configuration removed.');
            return;
        }

        foreach ($activeProxies as $key => $proxy) {
            $domain = $proxy['domain'];
            $email =  'admin@' . $domain;

            // Use $sudo because /etc/letsencrypt requires root to read
            $certCheck = trim($ssh->exec("{$sudo}test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'exists' || echo 'missing'"));

            if ($certCheck === 'missing') {
                Log::info("Generating new SSL certificate for domain: {$domain}");

                $tempNginx = "server { listen 80; server_name {$domain}; }";
                $tempConfPath = "/etc/nginx/sites-enabled/temp-{$domain}.conf";

                // Write temp config to /tmp first (standard users can write here), then sudo move it
                $ssh->exec("cat << 'EOF_NGINX' > /tmp/temp-{$domain}.conf\n{$tempNginx}\nEOF_NGINX");
                $ssh->exec("{$sudo}mv /tmp/temp-{$domain}.conf {$tempConfPath}");

                // Reload Nginx
                $ssh->exec("{$sudo}/usr/bin/systemctl reload nginx");

                // Run Certbot with sudo
                $certbotCmd = "certbot certonly --nginx -d {$domain} --non-interactive --agree-tos -m {$email}";
                $ssh->exec("{$sudo}{$certbotCmd}");

                // Clean up temp config
                $ssh->exec("{$sudo}rm -f {$tempConfPath}");

                $verifyCert = trim($ssh->exec("{$sudo}test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'exists' || echo 'missing'"));
                if ($verifyCert === 'missing') {
                    Log::error("Certbot failed for {$domain}. Skipping this relay to prevent Nginx crash.");
                    unset($activeProxies[$key]);
                }
            }
        }

        $activeProxies = array_values($activeProxies);

        if (empty($activeProxies)) {
            Log::error('All relays failed SSL generation. Aborting Nginx deployment.');
            return;
        }

        $nginxConfigString = $this->nginxService->generate($activeProxies);
        $tempPath = '/tmp/webhook-relays.conf';

        // Write the final config to /tmp (no sudo needed for /tmp)
        $ssh->exec("cat << 'EOF_NGINX' > {$tempPath}\n{$nginxConfigString}\nEOF_NGINX");

        // Move and symlink with sudo
        $ssh->exec("{$sudo}mv {$tempPath} {$finalPath}");
        $ssh->exec("{$sudo}ln -sf {$finalPath} {$enabledPath}");

        // Test Nginx configuration using the absolute path to avoid sudo PATH stripping
        $testCmd = "{$sudo}/usr/sbin/nginx -t 2>&1 && echo 'NGINX_OK'";
        $testOutput = $ssh->exec($testCmd);

        if (str_contains($testOutput, 'NGINX_OK')) {
            $ssh->exec("{$sudo}/usr/bin/systemctl reload nginx");
            Log::info('Nginx configuration successfully deployed and reloaded.');
        } else {
            Log::error('Remote Nginx config syntax error: ' . $testOutput);
        }
    }
}
