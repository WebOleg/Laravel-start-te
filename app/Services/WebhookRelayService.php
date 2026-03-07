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

        $finalPath = '/etc/nginx/sites-available/webhook-relays.conf';
        $enabledPath = '/etc/nginx/sites-enabled/webhook-relays.conf';

        if (empty($activeProxies)) {
            $ssh->exec("rm -f {$finalPath} {$enabledPath}");
            $ssh->exec('systemctl reload nginx');
            Log::info('No active webhook relays found. Nginx configuration removed.');
            return;
        }

        foreach ($activeProxies as $key => $proxy) {
            $domain = $proxy['domain'];
            $email =  'admin@' . $domain;

            // Check if the certificate file already exists
            $certCheck = trim($ssh->exec("test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'exists' || echo 'missing'"));

            if ($certCheck === 'missing') {
                Log::info("Generating new SSL certificate for domain: {$domain}");

                // Use Heredoc to safely write the temporary config
                $tempNginx = "server { listen 80; server_name {$domain}; }";
                $tempConfPath = "/etc/nginx/sites-enabled/temp-{$domain}.conf";
                $ssh->exec("cat << 'EOF_NGINX' > {$tempConfPath}\n{$tempNginx}\nEOF_NGINX");

                // Reload Nginx to serve the temporary block
                $ssh->exec('systemctl reload nginx');

                // Run Certbot via the Nginx plugin
                $certbotCmd = "certbot certonly --nginx -d {$domain} --non-interactive --agree-tos -m {$email}";
                $ssh->exec($certbotCmd);

                // Remove the temporary config
                $ssh->exec("rm -f {$tempConfPath}");

                $verifyCert = trim($ssh->exec("test -f /etc/letsencrypt/live/{$domain}/fullchain.pem && echo 'exists' || echo 'missing'"));
                if ($verifyCert === 'missing') {
                    Log::error("Certbot failed for {$domain}. Skipping this relay to prevent Nginx crash.");
                    unset($activeProxies[$key]);
                }
            }
        }

        // Re-index the array in case any failed domains were unset
        $activeProxies = array_values($activeProxies);

        if (empty($activeProxies)) {
            Log::error('All relays failed SSL generation. Aborting Nginx deployment.');
            return;
        }

        // Generate the final Nginx config string with SSL included
        $nginxConfigString = $this->nginxService->generate($activeProxies);
        $tempPath = '/tmp/webhook-relays.conf';

        // Use a Bash Heredoc with single quotes around the delimiter to safely
        // write multiline text containing '$' variables exactly as they are.
        $ssh->exec("cat << 'EOF_NGINX' > {$tempPath}\n{$nginxConfigString}\nEOF_NGINX");

        $ssh->exec("mv {$tempPath} {$finalPath}");
        $ssh->exec("ln -sf {$finalPath} {$enabledPath}");

        // Fix 2 & 3: Inject /usr/sbin into the PATH, and use '&& echo "NGINX_OK"'
        // to deterministically check the exit status rather than parsing log strings.
        $testCmd = 'export PATH=$PATH:/usr/sbin:/sbin; nginx -t 2>&1 && echo "NGINX_OK"';
        $testOutput = $ssh->exec($testCmd);

        if (str_contains($testOutput, 'NGINX_OK')) {
            $ssh->exec('systemctl reload nginx');
            Log::info('Nginx configuration successfully deployed and reloaded.');
        } else {
            Log::error('Remote Nginx config syntax error: ' . $testOutput);
        }
    }
}
