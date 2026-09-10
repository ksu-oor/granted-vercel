#!/bin/sh
set -e

php /app/generate-config.php

# Optional: store PHP sessions in Redis instead of the container's ephemeral
# filesystem, since Vercel Functions can scale to multiple instances. Set
# REDIS_URL (e.g. from a Vercel/Upstash Redis integration) to enable this.
if [ -n "$REDIS_URL" ]; then
    php -r '
        $url = parse_url(getenv("REDIS_URL"));
        $host = $url["host"] ?? "127.0.0.1";
        $port = $url["port"] ?? 6379;
        $pass = $url["pass"] ?? "";
        $savePath = "tcp://{$host}:{$port}" . ($pass !== "" ? "?auth={$pass}" : "");
        $ini = "session.save_handler = redis\nsession.save_path = \"{$savePath}\"\n";
        file_put_contents("/usr/local/etc/php/conf.d/zz-sessions.ini", $ini);
    '
fi

exec "$@"
