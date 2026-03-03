#!/usr/bin/env bash
# One-time script: push org.json and uploads to server for initial CMS setup
# After this, deploy.sh will NOT overwrite server data (org.json + uploads are excluded)
set -euo pipefail

REMOTE_USER="root"
REMOTE_HOST="198.46.87.163"
REMOTE_PATH="/home/plaine9/public_html/DG/"

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "Pushing initial data to server..."
ssh "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p ${REMOTE_PATH}data/uploads"
rsync -avz -e "ssh" \
  "$PROJECT_ROOT/data/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}data/"

ssh "${REMOTE_USER}@${REMOTE_HOST}" "chown -R plaine9:plaine9 ${REMOTE_PATH}data/"
echo "Done. Server data initialized."
