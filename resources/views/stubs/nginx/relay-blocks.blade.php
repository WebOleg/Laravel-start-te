@foreach ($proxies as $proxy)
    # HTTP Server Block: Redirect all port 80 traffic to HTTPS
    server {
        listen 80;
        listen [::]:80;
        server_name {{ $proxy['domain'] }};

        # Redirect to HTTPS using 308 to preserve POST method and payload
        return 308 https://$host$request_uri;
    }

    # HTTPS Server Block: Handle SSL and Reverse Proxy
    server {
        listen 443 ssl;
        listen [::]:443 ssl;
        server_name {{ $proxy['domain'] }};

        # SSL Certificate Paths (Dynamic based on domain)
        ssl_certificate /etc/letsencrypt/live/{{ $proxy['domain'] }}/fullchain.pem;
        ssl_certificate_key /etc/letsencrypt/live/{{ $proxy['domain'] }}/privkey.pem;
        include /etc/letsencrypt/options-ssl-nginx.conf;
        ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

        location / {
            proxy_pass {{ rtrim($proxy['target'], '/') }};

            # Required for HTTPS proxying to servers using SNI
            proxy_ssl_server_name on;

            proxy_set_header Host {{ parse_url($proxy['target'], PHP_URL_HOST) ?? $proxy['target'] }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme; # Tells the app it was accessed via HTTPS

            server_tokens off;
        }
    }
@endforeach
