#!/usr/bin/env bash
# Deploys members-site to Hostinger (members.zachalcasid.com).
# Plain PHP, no build/composer step — packages the working tree into a
# tarball, ships it over SSH, and extracts it on top of the existing site.
# Extracting only overwrites files present in the tarball, so anything on
# the server that isn't tracked locally (backup config copies, etc.) is left
# untouched — there's no delete step. Never packages:
#   - production secrets (includes/config.php, includes/config.local.php)
#   - user-uploaded data (uploads/audio|avatars|proofs)
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

SSH_KEY="$HOME/.ssh/konstack_hostinger"
SSH_HOST="194.5.156.155"
SSH_PORT="65002"
SSH_USER="u427714701"
REMOTE_APP_DIR="/home/u427714701/domains/members.zachalcasid.com/public_html"

SSH_OPTS=(-i "$SSH_KEY" -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=10)
SCP_OPTS=(-i "$SSH_KEY" -P "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=10)

STAMP="$(date +%Y%m%d%H%M%S)"
TARBALL="/tmp/members-site-deploy-${STAMP}.tar.gz"

echo "==> Packaging app"
tar czf "$TARBALL" \
    --exclude='.git' \
    --exclude='.claude' \
    --exclude='dev-notes' \
    --exclude='scripts' \
    --exclude='loadtest' \
    --exclude='node_modules' \
    --exclude='includes/config.php' \
    --exclude='includes/config.local.php' \
    --exclude='includes/config.sample.php' \
    --exclude='uploads/audio/*' \
    --exclude='uploads/avatars/*' \
    --exclude='uploads/proofs/*' \
    --exclude='*.sql' \
    --exclude='*.log' \
    --exclude='_opcache-check.php' \
    --exclude='_diag.php' \
    --exclude='_min.php' \
    --exclude='_local-preview-*.html' \
    --exclude='signuptest.php' \
    --exclude='*.exe' \
    --exclude='DEPLOY*.md' \
    --exclude='OAUTH-SETUP.md' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    .

echo "==> Uploading"
scp "${SCP_OPTS[@]}" "$TARBALL" "${SSH_USER}@${SSH_HOST}:/tmp/ms-deploy.tar.gz"
rm "$TARBALL"

echo "==> Extracting on server"
ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" bash -s <<REMOTE
set -e
tar xzf /tmp/ms-deploy.tar.gz -C "$REMOTE_APP_DIR"
rm /tmp/ms-deploy.tar.gz
REMOTE

echo "==> Deploy complete: https://members.zachalcasid.com/"
