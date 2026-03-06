#!/usr/bin/env bash
set -euo pipefail

REMOTE_USER="root"
REMOTE_HOST="198.46.87.163"
REMOTE_PATH="/home/dreamg/public_html/org-chart/"

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

rsync -avz --delete \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.claude' \
  --exclude 'node_modules' \
  --exclude 'scripts' \
  --exclude 'data/org.json' \
  --exclude 'data/uploads/' \
  -e "ssh" \
  "$PROJECT_ROOT/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

# Ensure data directories exist on server (first deploy)
ssh "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p ${REMOTE_PATH}data/uploads"

# Fix ownership so Apache can serve files and PHP can write
ssh "${REMOTE_USER}@${REMOTE_HOST}" "chown -R dreamg:dreamg ${REMOTE_PATH}"
