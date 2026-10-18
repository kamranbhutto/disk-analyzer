#!/bin/sh
# Installs Advanced Disk Analyzer as a cPanel plugin (icon on the Tools page, Files section).
# Run as root on the cPanel server:  sh install-plugin.sh
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
[ "$(id -u)" = 0 ] || { echo "Run this as root (WHM server owner)."; exit 1; }
[ -x /usr/local/cpanel/scripts/install_plugin ] || { echo "This is not a cPanel server."; exit 1; }
for THEME in jupiter; do
  DEST=/usr/local/cpanel/base/frontend/$THEME/advanced_disk_analyzer
  mkdir -p "$DEST"
  cp -R "$HERE/app/." "$DEST/"
  find "$DEST" -type d -exec chmod 755 {} +
  find "$DEST" -type f -exec chmod 644 {} +
  /usr/local/cpanel/scripts/install_plugin "$HERE/advanced_disk_analyzer-plugin.tar.gz" --theme "$THEME"
done
echo "Done. Advanced Disk Analyzer now appears in cPanel → Tools → Files."
echo "To hide or show it per package: WHM → Feature Manager → 'Advanced Disk Analyzer'."
