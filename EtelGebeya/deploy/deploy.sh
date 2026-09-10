#!/usr/bin/env bash
# Automated deploy -- for when SSH access or cPanel's "Git Version
# Control" feature is available on this hosting account. Right now,
# deploys go through the in-app File Updater one file/zip at a time;
# this script is what replaces that manual process once shell access
# to the server exists (either a real SSH terminal, or cPanel's Git
# Version Control feature, which runs a deploy command server-side
# without needing a terminal).
#
# What it does, in order -- and stops at the first failure so a broken
# state is never deployed:
#   1. Preflight-check the changes (see preflight.sh) -- refuses to
#      continue if a .env file or a PHP syntax error is in the diff.
#   2. Pull the target branch.
#   3. Re-run preflight on the files that just changed, as a second,
#      post-pull safety net.
#   4. Nothing here ever touches the live .env -- it's gitignored and
#      deploy never writes to it.
#
# Usage (run FROM the server, in the EtelGebeya directory):
#   deploy/deploy.sh [branch]     # defaults to "main"

set -euo pipefail
cd "$(dirname "$0")/.."

BRANCH="${1:-main}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "== Deploying $BRANCH =="

echo "-- Preflight on working tree changes --"
CHANGED=$(git diff --name-only HEAD 2>/dev/null || true)
if [ -n "$CHANGED" ]; then
  "$SCRIPT_DIR/preflight.sh" $CHANGED
fi

echo "-- Pulling $BRANCH --"
git fetch origin "$BRANCH"
BEFORE=$(git rev-parse HEAD)
git merge --ff-only "origin/$BRANCH"
AFTER=$(git rev-parse HEAD)

if [ "$BEFORE" = "$AFTER" ]; then
  echo "Already up to date -- nothing to deploy."
  exit 0
fi

echo "-- Preflight on files that changed in this pull --"
PULLED=$(git diff --name-only "$BEFORE" "$AFTER")
"$SCRIPT_DIR/preflight.sh" $PULLED

echo
echo "Deployed $BEFORE -> $AFTER"
echo "Remember: .env is NOT part of this repo (gitignored on purpose)."
echo "If this is a fresh checkout, copy .env.example to .env and fill in real values first."
