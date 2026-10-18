#!/bin/sh
# Builds the two packages in dist/:
#   advanced-disk-analyzer.zip          for any cPanel account (upload + extract in File Manager)
#   advanced-disk-analyzer-plugin.zip   real cPanel plugin for server owners (root/WHM)
set -eu
cd "$(dirname "$0")"
rm -rf dist && mkdir -p dist/tmp/disk-analyzer dist/tmp/plugin/app dist/tmp/plugin-tar
cp app/*.php app/ui.html app/.htaccess dist/tmp/disk-analyzer/
rm dist/tmp/disk-analyzer/*.live.php
cp INSTALL.md dist/tmp/disk-analyzer/
(cd dist/tmp && zip -qr ../advanced-disk-analyzer.zip disk-analyzer)

cp app/*.php app/ui.html dist/tmp/plugin/app/
mkdir -p dist/tmp/plugin/whm
cp whm/*.php whm/ui.html dist/tmp/plugin/whm/
cp plugin/whm-appconfig.conf plugin/advanced_disk_analyzer.png dist/tmp/plugin/
cp plugin/install.json plugin/advanced_disk_analyzer.svg dist/tmp/plugin-tar/
(cd dist/tmp/plugin-tar && tar -czf ../plugin/advanced_disk_analyzer-plugin.tar.gz install.json advanced_disk_analyzer.svg)
cp plugin/install-plugin.sh plugin/uninstall-plugin.sh INSTALL.md dist/tmp/plugin/
(cd dist/tmp && mv plugin advanced-disk-analyzer-plugin && zip -qr ../advanced-disk-analyzer-plugin.zip advanced-disk-analyzer-plugin)
rm -rf dist/tmp
ls -l dist
