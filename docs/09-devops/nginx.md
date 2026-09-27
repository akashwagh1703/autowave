# Nginx

> Draft (AW-004).

One server block handles platform hosts, tenant subdomains and custom domains; Laravel resolves the tenant
from the `Host` header (ADR-007).

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name autowave.in *.autowave.in;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name autowave.in *.autowave.in;

    ssl_certificate     /etc/letsencrypt/live/autowave.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/autowave.in/privkey.pem;

    root /var/www/autowave/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(?:css|js|woff2?|svg|png|jpg|jpeg|webp|ico)$ {
        expires 30d;
        access_log off;
        try_files $uri /index.php?$query_string;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Custom domains: add a separate server block (default_server on 443) using on-demand certificates — see [ssl.md](ssl.md).

Use `$realpath_root` so the `current` symlink switch takes effect without stale OPcache paths.
Test with `sudo nginx -t && sudo systemctl reload nginx`.
