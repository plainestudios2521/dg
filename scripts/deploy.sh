#!/usr/bin/env bash
set -euo pipefail

REMOTE_USER="root"
REMOTE_HOST="198.46.87.163"
REMOTE_PATH="/home/plaine9/public_html/DG/"

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

rsync -avz --delete \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.claude' \
  --exclude 'node_modules' \
  --exclude 'scripts' \
  -e "ssh" \
  "$PROJECT_ROOT/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

# Fix ownership so Apache can serve files
ssh "${REMOTE_USER}@${REMOTE_HOST}" "chown -R plaine9:plaine9 ${REMOTE_PATH}"
