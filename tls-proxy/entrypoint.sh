#!/bin/bash
set -e

: "${DOMAIN:?DOMAIN environment variable must be set}"
: "${LETSENCRYPT_EMAIL:?LETSENCRYPT_EMAIL environment variable must be set}"
: "${DNSMADEEASY_API_KEY:?DNSMADEEASY_API_KEY environment variable must be set}"
: "${DNSMADEEASY_SECRET_KEY:?DNSMADEEASY_SECRET_KEY environment variable must be set}"

CRED_FILE="/etc/letsencrypt/dnsmadeeasy.ini"

mkdir -p /etc/letsencrypt
cat > "$CRED_FILE" << EOF
dns_dnsmadeeasy_api_key = ${DNSMADEEASY_API_KEY}
dns_dnsmadeeasy_secret_key = ${DNSMADEEASY_SECRET_KEY}
EOF
chmod 600 "$CRED_FILE"

# Obtain a certificate only if we don't already have one for this domain.
# The cert lives on a named volume, so this only actually runs once, the
# first time the container starts against an empty volume.
if [ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
    echo "No existing certificate found for ${DOMAIN}. Requesting one via DNS-01..."
    certbot certonly \
        --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" \
        --dns-dnsmadeeasy --dns-dnsmadeeasy-credentials "$CRED_FILE" \
        --dns-dnsmadeeasy-propagation-seconds 60 \
        -d "$DOMAIN"
fi

# Start nginx in the background so this script can also run the renewal loop.
nginx -g 'daemon off;' &
NGINX_PID=$!

# Check for renewal twice a day (Certbot itself only actually renews once
# the certificate is within 30 days of expiring). --deploy-hook only fires
# nginx -s reload when a certificate was actually renewed.
(
    while true; do
        sleep 12h
        certbot renew --quiet \
            --dns-dnsmadeeasy --dns-dnsmadeeasy-credentials "$CRED_FILE" \
            --dns-dnsmadeeasy-propagation-seconds 60 \
            --deploy-hook "nginx -s reload"
    done
) &

wait "$NGINX_PID"
