#!/bin/sh
# Removes Advanced Disk Analyzer from a cPanel & WHM server. Run as root.
# Saved settings and history in /var/cpanel/advanced_disk_analyzer and users'
# ~/.diskanalyzer folders are kept; delete them by hand if you want.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
[ "$(id -u)" = 0 ] || { echo "Run this as root."; exit 1; }
/usr/local/cpanel/scripts/uninstall_plugin "$HERE/advanced_disk_analyzer-plugin.tar.gz" --theme jupiter || true
rm -rf /usr/local/cpanel/base/frontend/jupiter/advanced_disk_analyzer
/usr/local/cpanel/bin/unregister_appconfig advanced_disk_analyzer || true
rm -rf /usr/local/cpanel/whostmgr/docroot/cgi/advanced_disk_analyzer
rm -f /usr/local/cpanel/whostmgr/docroot/addon_plugins/advanced_disk_analyzer.png /etc/cron.d/advanced_disk_analyzer
echo "Removed."
