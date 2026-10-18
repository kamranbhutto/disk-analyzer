#!/bin/sh
# One-command install of the latest Advanced Disk Analyzer release. Run as root:
#   curl -fsSL https://raw.githubusercontent.com/kamranbhutto/disk-analyzer/main/cpanel/plugin/get.sh | sh
set -eu
URL=https://github.com/kamranbhutto/disk-analyzer/releases/latest/download/advanced-disk-analyzer-plugin.zip
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
echo "Downloading $URL"
curl -fsSL -o "$TMP/plugin.zip" "$URL"
cd "$TMP"
unzip -q plugin.zip
sh advanced-disk-analyzer-plugin/install-plugin.sh
