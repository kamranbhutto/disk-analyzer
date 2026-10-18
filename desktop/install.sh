#!/bin/sh
# Installs Advanced Disk Analyzer as a desktop app for the current user (no sudo needed).
set -eu
cd "$(dirname "$0")"
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$HOME/.cache/disk-analyzer-target}"
cargo build --release
mkdir -p ~/.local/share/advanced-disk-analyzer ~/.local/bin ~/.local/share/applications ~/.local/share/icons/hicolor/scalable/apps
install -m 755 "$CARGO_TARGET_DIR/release/disk-analyzer" ~/.local/share/advanced-disk-analyzer/disk-analyzer
install -m 755 desktop/advanced-disk-analyzer ~/.local/bin/advanced-disk-analyzer
install -m 644 desktop/advanced-disk-analyzer.svg ~/.local/share/icons/hicolor/scalable/apps/advanced-disk-analyzer.svg
sed "s|^Exec=.*|Exec=$HOME/.local/bin/advanced-disk-analyzer|" desktop/advanced-disk-analyzer.desktop > ~/.local/share/applications/advanced-disk-analyzer.desktop
update-desktop-database ~/.local/share/applications 2>/dev/null || true
echo "Installed. Open 'Advanced Disk Analyzer' from your app menu, or run: advanced-disk-analyzer"
