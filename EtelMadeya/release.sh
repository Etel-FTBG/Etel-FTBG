#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# ETEL MADEYA — Release Script
# Run this script every time you make changes and want to push
# an update to all installed sites.
#
# Usage:
#   ./release.sh 3.1
#   ./release.sh 3.2 "Fixed driver map; added bulk import CSV"
#
# What it does:
#   1. Updates the version number in madeya-gas-queue.php
#   2. Updates madeya-update.json with new version + changelog
#   3. Builds madeya-gas-queue.zip (without dev files)
#   4. Tells you exactly what to upload to your server
# ═══════════════════════════════════════════════════════════════

set -e

NEW_VERSION="${1}"
CHANGELOG_NOTE="${2:-Bug fixes and improvements}"
DATE=$(date +%Y-%m-%d)

if [ -z "$NEW_VERSION" ]; then
  echo "Usage: ./release.sh <version> [changelog note]"
  echo "Example: ./release.sh 3.1 \"Fixed fuel quota; added priority tiers\""
  exit 1
fi

echo ""
echo "═══════════════════════════════════════════════════"
echo "  ETEL MADEYA — Releasing v${NEW_VERSION}"
echo "═══════════════════════════════════════════════════"

# ── Step 1: Update version in main plugin file ─────────────────
sed -i "s/ \* Version: .*/ * Version: ${NEW_VERSION}/" madeya-gas-queue.php
sed -i "s/define( 'MADEYA_VERSION', '.*' );/define( 'MADEYA_VERSION', '${NEW_VERSION}' );/" madeya-gas-queue.php
echo "✓ Version updated to ${NEW_VERSION} in madeya-gas-queue.php"

# ── Step 2: Update the JSON manifest ──────────────────────────
cat > madeya-update.json << EOF
{
  "version"      : "${NEW_VERSION}",
  "download_url" : "https://ethelmarket.com/updates/madeya-gas-queue.zip",
  "requires"     : "5.5",
  "tested"       : "6.6",
  "requires_php" : "7.4",
  "last_updated" : "${DATE}",
  "changelog"    : "<h4>Version ${NEW_VERSION} — ${DATE}</h4><ul><li>${CHANGELOG_NOTE}</li></ul>"
}
EOF
echo "✓ madeya-update.json updated"

# ── Step 3: Build the release zip ─────────────────────────────
cd ..
rm -f madeya-gas-queue.zip
zip -r madeya-gas-queue.zip madeya-gas-queue/ \
  --exclude "*.DS_Store" \
  --exclude "*/.git/*" \
  --exclude "*/release.sh" \
  --exclude "*/__pycache__/*"
cd madeya-gas-queue

echo "✓ madeya-gas-queue.zip built ($(du -sh ../madeya-gas-queue.zip | cut -f1))"

# ── Step 4: Instructions ───────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════════"
echo "  RELEASE READY — Upload these 2 files to your server:"
echo "═══════════════════════════════════════════════════"
echo ""
echo "  1. ../madeya-gas-queue.zip"
echo "     → Upload to: https://ethelmarket.com/updates/madeya-gas-queue.zip"
echo ""
echo "  2. madeya-update.json"
echo "     → Upload to: https://ethelmarket.com/updates/madeya-update.json"
echo ""
echo "  After uploading, every WordPress site with the plugin"
echo "  will see the update within 12 hours, or immediately"
echo "  when they click 'Check for Updates' in their Plugins list."
echo ""
echo "═══════════════════════════════════════════════════"
