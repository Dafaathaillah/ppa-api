# Production deployment

The inspection APIs accept individual images up to 10 MB. A tower inspection can contain multiple images, so the web server and PHP request limits must allow the complete multipart request.

After deploying the application, install the supplied runtime configuration and reload both services:

```bash
sudo cp deploy/nginx/ppa-api.conf /etc/nginx/sites-available/ppa-api.conf
sudo cp deploy/php/99-ppa-api.ini /etc/php/8.2/fpm/conf.d/99-ppa-api.ini
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm
php artisan migrate --force
```

If the VM uses a different PHP-FPM version, change `8.2` in the destination path and service name. Verify the active values rather than the CLI values:

```bash
php-fpm8.2 -i | grep -E 'post_max_size|upload_max_filesize'
```

Expected values:

- Nginx `client_max_body_size`: `128m`
- PHP `post_max_size`: `128M`
- PHP `upload_max_filesize`: `10M`

Any load balancer or reverse proxy in front of this Nginx server must also allow at least 128 MB, otherwise it can still return HTTP 413 before Laravel receives the request.
