# Database Backups

CycleAudit's MySQL database is backed up automatically once per day using a
dedicated `backup-cron` service on Railway. Backups are dumped, compressed,
and uploaded to a private Backblaze B2 bucket.

## Overview

- **Schedule:** Daily at 02:00 AM UTC (Railway cron)
- **Pipeline:** `mysqldump` → `gzip` → `rclone` upload → Backblaze B2
- **Storage:** Backblaze B2 bucket `cycleaudit-backups` (private, SSE-B2 encryption at rest)
- **Retention:** 7 days (see `RETENTION_DAYS` below)
- **Script:** `scripts/backup.sh`

## Backblaze B2 setup

1. **Create a B2 account** (or use an existing one) at [backblaze.com/b2](https://www.backblaze.com/b2/cloud-storage.html).
2. **Create a bucket:**
   - Name: `cycleaudit-backups` (or your own — must be globally unique)
   - Files in bucket: **Private**
   - Default encryption: **SSE-B2** (server-side encryption)
3. **Create an application key:**
   - Go to **App Keys** → **Add a New Application Key**
   - Restrict it to the `cycleaudit-backups` bucket if possible
   - Save the **keyID** and **applicationKey** shown — the applicationKey is
     only displayed once
4. **Note your endpoint.** B2's S3-compatible endpoint format is:
   ```
   https://s3.<region>.backblazeb2.com
   ```
   For example: `https://s3.us-east-005.backblazeb2.com`. The region is shown
   on the bucket's details page in the B2 dashboard.

## Railway configuration

The `backup-cron` service reads the following environment variables. Database
credentials are wired in via Railway's **Variable Reference** picker
(`${{MySQL.VARNAME}}`) rather than typed by hand, so they can't pick up stray
whitespace — see the gotcha note below for why that matters.

```env
BACKUP_S3_ACCESS_KEY="<B2 keyID>"
BACKUP_S3_BUCKET="cycleaudit-backups"
BACKUP_S3_ENDPOINT="https://s3.us-east-005.backblazeb2.com"
BACKUP_S3_SECRET_KEY="<B2 applicationKey>"
DB_HOST="${{MySQL.MYSQLHOST}}"
DB_NAME="${{MySQL.MYSQLDATABASE}}"
DB_PASS="${{MySQL.MYSQLPASSWORD}}"
DB_PORT="${{MySQL.MYSQLPORT}}"
DB_USER="${{MySQL.MYSQLUSER}}"
RETENTION_DAYS="7"
```

`RETENTION_DAYS` controls how many days of backups are kept before older ones
are pruned (see `scripts/backup.sh`).

## How the backup runs

1. Railway's cron trigger starts the `backup-cron` service at 02:00 AM UTC.
2. `scripts/backup.sh` runs `mysqldump` against the credentials above and
   pipes the output through `gzip`.
3. The compressed dump is uploaded via `rclone` (configured entirely through
   `RCLONE_CONFIG_BACKUPSTORE_*` environment variables — no `rclone.conf` file
   is used, so seeing `Config file "/root/.config/rclone/rclone.conf" not
   found - using defaults` in the logs is expected and harmless).
4. Files older than `RETENTION_DAYS` are deleted from the bucket.

A successful run looks like this in Railway's Deploy Logs:

```
[... ] Starting backup for database 'railway'...
[... ] Dump complete: /tmp/cycleaudit_<timestamp>.sql.gz (1.1M)
[... ] Uploaded to backupstore:cycleaudit-backups/cycleaudit_<timestamp>.sql.gz
[... ] Backup job finished successfully.
```

## Verifying a backup

Check the Backblaze B2 dashboard directly:

- Bucket → file list should show a recently uploaded `cycleaudit_*.sql.gz`
- Encryption column should read **AES256 (SSE-B2)**
- Bucket should remain **Private**

## Restoring from a backup

1. Download the desired `cycleaudit_<timestamp>.sql.gz` from the B2 bucket
   (via the B2 dashboard or `rclone copy`).
2. Decompress it: `gunzip cycleaudit_<timestamp>.sql.gz`
3. Restore into MySQL:
   ```bash
   mysql -h <host> -P <port> -u <user> -p <database> < cycleaudit_<timestamp>.sql
   ```

## Troubleshooting

### `mysqldump: Got error: 2005: Unknown MySQL server host ... (-2)`

This error looks like a networking/DNS/private-networking problem but in
practice has one very common, very unglamorous cause: **stray leading or
trailing whitespace in an environment variable value.**

Railway's normal (non-raw) variable editor UI renders a value with a leading
space identically to a value without one — the whitespace is invisible there.
The only reliable way to check is:

1. Open the service's **Variables → Raw Editor** (plain-text `KEY="VALUE"` view).
2. Look for any value that isn't sitting flush against its opening quote.
3. If found, rewrite the Raw Editor contents with no leading/trailing spaces,
   click **Update Variables**, redeploy, and re-test.

This is most likely to happen on variables that were manually typed or pasted
(e.g. copied out of the B2 dashboard) rather than added via Railway's
point-and-click **Variable Reference** picker (`${{Service.VAR}}`), which
doesn't introduce whitespace.

To confirm this is the cause before touching the Raw Editor, add a temporary
debug line directly above the `mysqldump` call in `scripts/backup.sh`:

```bash
echo "DEBUG: DB_HOST=[$DB_HOST] DB_PORT=[$DB_PORT] DB_USER=[$DB_USER] DB_NAME=[$DB_NAME]"
```

Redeploy, trigger **Run now**, and check the Deploy Log — a visible gap after
`DB_HOST=[` compared to the other fields sitting flush is the tell. Remove the
debug line once confirmed and fixed.

**Rule of thumb:** if an error is unchanged after a fix that seemed clearly
relevant (e.g. toggling Serverless mode, waiting out DNS propagation), that's
a signal to check the mundane stuff — variable values, whitespace, typos —
before continuing down a deeper infrastructure rabbit hole.
