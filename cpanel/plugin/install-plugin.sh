#!/bin/sh
# Installs Advanced Disk Analyzer on a cPanel & WHM server. Run as root:
#   sh install-plugin.sh
#
# - cPanel: icon on the Tools page (Files section) for every account
# - WHM:    Plugins → Advanced Disk Analyzer (all accounts, quota alerts)
# - cron:   hourly alert check and daily usage snapshot
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
[ "$(id -u)" = 0 ] || { echo "Run this as root (WHM server owner)."; exit 1; }
[ -x /usr/local/cpanel/scripts/install_plugin ] || { echo "This is not a cPanel & WHM server."; exit 1; }

# cPanel plugin (each user's own dashboard)
DEST=/usr/local/cpanel/base/frontend/jupiter/advanced_disk_analyzer
mkdir -p "$DEST"
cp -R "$HERE/app/." "$DEST/"
find "$DEST" -type d -exec chmod 755 {} +
find "$DEST" -type f -exec chmod 644 {} +
/usr/local/cpanel/scripts/install_plugin "$HERE/advanced_disk_analyzer-plugin.tar.gz" --theme jupiter

# WHM plugin (admin dashboard for all accounts)
WHM=/usr/local/cpanel/whostmgr/docroot/cgi/advanced_disk_analyzer
mkdir -p "$WHM"
cp "$HERE"/whm/* "$WHM/"
chown -R root:root "$WHM"
chmod 755 "$WHM" "$WHM/index.php" "$WHM/cron.php"
chmod 644 "$WHM/lib.php" "$WHM/dashboard.php" "$WHM/ui.html"
install -m 644 "$HERE/advanced_disk_analyzer.png" /usr/local/cpanel/whostmgr/docroot/addon_plugins/advanced_disk_analyzer.png
/usr/local/cpanel/bin/register_appconfig "$HERE/whm-appconfig.conf"

# Hourly alerts and daily snapshots
PHP=/usr/local/cpanel/3rdparty/bin/php
echo "17 * * * * root $PHP $WHM/cron.php >> /var/log/advanced_disk_analyzer.log 2>&1" > /etc/cron.d/advanced_disk_analyzer
chmod 644 /etc/cron.d/advanced_disk_analyzer
$PHP "$WHM/cron.php" || true   # first snapshot now

echo
echo "Done."
echo "  cPanel: Tools → Files → Advanced Disk Analyzer (turn on/off per package in WHM → Feature Manager)"
echo "  WHM:    Plugins → Advanced Disk Analyzer (set alert emails there)"
