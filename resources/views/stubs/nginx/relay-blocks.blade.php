@foreach ($proxies as $proxy)
    server {
        listen 80;
        server_name {{ $proxy['domain'] }};

        location / {
            proxy_pass {{ rtrim($proxy['target'], '/') }};

            proxy_set_header Host {{ parse_url($proxy['target'], PHP_URL_HOST) ?? $proxy['target'] }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

            server_tokens off;
        }
    }
@endforeach
