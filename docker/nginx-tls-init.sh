#!/bin/sh
# Runs from nginx's /docker-entrypoint.d/ before nginx starts. Every install mints its own
# self-signed pair on first start, into the tls-data volume, so no private key ever ships in the
# public image. A cert mounted over these paths (certbot / bring-your-own) is left untouched.
set -eu
dir=/etc/ssl/shoemoneyx
crt=$dir/selfsigned.crt
key=$dir/selfsigned.key

if [ -s "$crt" ] && [ -s "$key" ]; then
  echo "shoemoneyx-tls: using existing certificate in $dir"
  exit 0
fi

echo "shoemoneyx-tls: generating a per-install self-signed certificate"
mkdir -p "$dir"
umask 077
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -keyout "$key" -out "$crt" -subj "/CN=shoemoneyx-desk"
chmod 644 "$crt"
