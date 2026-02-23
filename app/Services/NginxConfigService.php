<?php

namespace App\Services;

use Illuminate\Support\Facades\View;

class NginxConfigService
{
    /**
     * Generate an Nginx configuration file containing multiple server blocks.
     *
     * @param array $proxies Array of arrays containing 'domain' and 'target' keys.
     * @return string The compiled Nginx configuration.
     */
    public function generate(array $proxies): string
    {
        // Render the Blade view, passing the entire array of proxies
        return View::make('stubs.nginx.relay-blocks', [
            'proxies' => $proxies
        ])->render();
    }
}
