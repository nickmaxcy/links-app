#!/bin/bash
# Fixes ownership/permissions on the app files and restarts the php
# container (PHP code is interpreted there now, not in the nginx "web"
# container, which only proxies to it). Run this any time after copying
# new files into /opt/links-app/html.
#
# Usage: ./deploy.sh

set -e

echo "Fixing ownership and permissions..."
sudo chown -R dllinks:dllinks /opt/links-app/html
sudo chmod -R o+rX /opt/links-app/html
# The php-fpm container runs as its own fixed user (not dllinks), so it
# needs write access specifically to assets/ (where uploads land) beyond
# the read-only access the rest of the codebase needs.
sudo mkdir -p /opt/links-app/html/assets
sudo chmod -R o+w /opt/links-app/html/assets

echo "Restarting php container..."
cd /opt/links-app
docker compose up -d --force-recreate php

echo "Done. Site should be live with the latest files."
