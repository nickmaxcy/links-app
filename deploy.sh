#!/bin/bash
# Fixes ownership/permissions on the app files and restarts the web
# container. Run this any time after copying new files into
# /opt/links-app/html — whether or not you needed to fix permissions
# first to overwrite them.
#
# Usage: ./deploy.sh

set -e

echo "Fixing ownership and permissions..."
sudo chown -R dllinks:dllinks /opt/links-app/html
sudo chmod -R o+rX /opt/links-app/html

echo "Restarting web container..."
cd /opt/links-app
docker compose up -d --force-recreate web

echo "Done. Site should be live with the latest files."
