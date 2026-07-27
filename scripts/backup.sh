#!/bin/bash
# ═══════════════════════════════════════════════════════════════
#  scripts/backup.sh
#  Dumps the MySQL database, compresses it, and uploads it to
#  S3-compatible object storage (Cloudflare R2, Backblaze B2, or
#  AWS S3 all work — anything rclone supports as an "s3" remote).
#
#  Runs as its own Railway service on a daily cron schedule.
#  See BACKUPS.md for the one-time setup steps.
#
#  Required environment variables (set as Railway secrets on the
#  backup service — NOT the web service):
#    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS   (same DB as the app)
#    BACKUP_S3_ENDPOINT   e.g. https://<account-id>.r2.cloudflarestorage.com
#    BACKUP_S3_BUCKET     e.g. cycleaudit-backups
#    BACKUP_S3_ACCESS_KEY
#    BACKUP_S3_SECRET_KEY
#    BACKUP_RETENTION_DAYS   (optional, default 7 — see note below)
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

DB_PORT="${DB_PORT:-3306}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
TIMESTAMP=$(date -u +%Y%m%d_%H%M%S)
DUMP_FILE="/tmp/cycleaudit_${TIMESTAMP}.sql.gz"
REMOTE="backupstore:${BACKUP_S3_BUCKET}"

echo "[$(date -u)] Starting backup for database '${DB_NAME}'..."

# ── 1. Dump + compress ──────────────────────────────────────────
# --single-transaction: consistent snapshot without locking tables
#   (safe for InnoDB, which this schema uses throughout)
# --routines --triggers: capture stored procedures/triggers too, if any
mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USER}" \
    --password="${DB_PASS}" \
    --single-transaction \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip > "${DUMP_FILE}"

DUMP_SIZE=$(du -h "${DUMP_FILE}" | cut -f1)
echo "[$(date -u)] Dump complete: ${DUMP_FILE} (${DUMP_SIZE})"

# ── 2. Configure rclone remote from env vars (no config file needed) ──
export RCLONE_CONFIG_BACKUPSTORE_TYPE=s3
export RCLONE_CONFIG_BACKUPSTORE_PROVIDER=Other
export RCLONE_CONFIG_BACKUPSTORE_ENDPOINT="${BACKUP_S3_ENDPOINT}"
export RCLONE_CONFIG_BACKUPSTORE_ACCESS_KEY_ID="${BACKUP_S3_ACCESS_KEY}"
export RCLONE_CONFIG_BACKUPSTORE_SECRET_ACCESS_KEY="${BACKUP_S3_SECRET_KEY}"

# ── 3. Upload ────────────────────────────────────────────────────
rclone copy "${DUMP_FILE}" "${REMOTE}/" --s3-no-check-bucket
echo "[$(date -u)] Uploaded to ${REMOTE}/$(basename "${DUMP_FILE}")"

# ── 4. Clean up the local temp file ─────────────────────────────
rm -f "${DUMP_FILE}"

# ── 5. Prune backups older than the retention window ────────────
# NOTE: this deletes remote files by name (name embeds the UTC
# timestamp), so it only works if nothing else writes into this
# bucket path. If your storage provider supports lifecycle rules
# (R2 and B2 both do), prefer configuring auto-expiry there instead
# of relying on this step — it's simpler and doesn't depend on the
# backup job running successfully to also do cleanup.
CUTOFF=$(date -u -d "${RETENTION_DAYS} days ago" +%Y%m%d)
rclone lsf "${REMOTE}/" | while read -r fname; do
    file_date=$(echo "$fname" | grep -oP '\d{8}' | head -1 || true)
    if [[ -n "$file_date" && "$file_date" < "$CUTOFF" ]]; then
        echo "[$(date -u)] Pruning old backup: $fname"
        rclone delete "${REMOTE}/${fname}"
    fi
done

echo "[$(date -u)] Backup job finished successfully."
