# Nginx

- **Last updated:** 2026-09-30 (production config, AW-004)

One site handles the marketing, app and admin hosts and every tenant subdomain; Laravel resolves the tenant
from the `Host` header (ADR-007). `www` redirects to the bare domain.

`/etc/nginx/sites-available/autowave-platform`, enabled with
`ln -s /etc/nginx/sites-available/autowave-platform /etc/nginx/sites-enabled/`:

```nginx
server {
    listen 80;
    server_name autowave.co.in *.autowave.co.in;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name www.autowave.co.in;
    ssl_certificate     /etc/letsencrypt/live/autowave.co.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/autowave.co.in/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
    return 301 https://autowave.co.in$request_uri;
}

server {
    listen 443 ssl;
    server_name autowave.co.in *.autowave.co.in;

    ssl_certificate     /etc/letsencrypt/live/autowave.co.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/autowave.co.in/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    root /var/www/autowave-platform/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.4-fpm-autowave-platform.sock;
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

Notes:

- **No `http2`.** The other sites on the shared server listen on 443 without it; mixing produced
  "protocol options redefined" warnings, and with HTTP/2 on, the marketing page (large `Link` preload
  header) failed with no response. Revisit when the server is dedicated.
- `$realpath_root` resolves the `current` symlink per request, so a deploy takes effect without an
  FPM reload and OPcache never serves the old release's files.
- `try_files $uri =404` in the PHP block answers requests for PHP files that do not exist (bots probing
  `/info.php`) without passing them to PHP-FPM.
- Specific `server_name`s mean this site never catches the other projects' hosts.
- Before the certificate existed, the same site ran on port 80 only (without the redirect block) to test
  with `curl -H "Host: app.autowave.co.in" http://127.0.0.1/login`.

Test and apply: `nginx -t && systemctl reload nginx`. Check the hosts from the server itself:

```bash
for h in autowave.co.in www.autowave.co.in app.autowave.co.in admin.autowave.co.in; do
  printf "%-24s " $h; curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" --resolve $h:443:127.0.0.1 https://$h/
done
```

Expected: `200`; `301` to the bare domain; `302` to `/dashboard`; `302` to `/login`.

Custom domains need their own certificates and a catch-all site — see [ssl.md](ssl.md) (future).
