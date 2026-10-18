# Changelog

## 1.1.0 (2026-10-18)

- **WHM admin dashboard** (WHM → Plugins → Advanced Disk Analyzer): every
  account's disk and inode usage, % of limit, 30-day growth, owner, plan and
  suspended status; server disks; usage-over-time chart; search, filters, sorting
  and CSV export.
- **Quota alerts**: hourly check that emails the admin, and optionally the
  account owner, when an account reaches 80% / 90% (configurable) of its disk
  space or inodes. Each level is reported once.
- **One-command install** for cPanel & WHM servers (`get.sh`).
- Released under the **MIT license**.

## 1.0.0 (2026-10-18)

- First release: cPanel dashboard (tree view, treemap, file types, largest
  files, duplicates, Move to Trash, CSV export, quota, inodes, databases, email
  accounts) and the Linux desktop edition.
