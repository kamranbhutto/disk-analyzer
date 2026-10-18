#!/bin/sh
# Removes the Advanced Disk Analyzer cPanel plugin. Run as root. Users' scan data in ~/.diskanalyzer is left alone.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
[ "$(id -u)" = 0 ] || { echo "Run this as root."; exit 1; }
/usr/local/cpanel/scripts/uninstall_plugin "$HERE/advanced_disk_analyzer-plugin.tar.gz" --theme jupiter
rm -rf /usr/local/cpanel/base/frontend/jupiter/advanced_disk_analyzer
echo "Removed."
