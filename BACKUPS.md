# Automated Database Backups

CycleAudit's MySQL database was not backed up automatically before this —
if something went wrong (bad migration, accidental delete, application bug),
there was no way to recover. This sets up daily automated backups at
effectively zero cost, without changing anything about how or where the
app itself is hosted.

## How it works

`scripts/backup.sh` dumps the database with `mysqldump`, compresses it,
and uploads it to S3-compatible object storage (Cloudflare R2 recommended —
10 GB/month free, no egress fees). It keeps the last 7 days of backups by
default and prunes older ones automatically.

This runs as a **separate Railway service** in the same project — not
inside the main web app — on a daily cron schedule. It shares the same
database credentials as the app, but doesn't affect the app's uptime or
performance at all.

## One-time setup

### 1. Create a Cloudflare R2 bucket (free)

1. Sign up / log in at [dash.cloudflare.com](https://dash.cloudflare.com) → R2
2. Create a bucket, e.g. `cycleaudit-backups`
3. Go to **Manage R2 API Tokens** → create a token with **read + write** access to that bucket
4. Note down: the Account ID, Access Key ID, and Secret Access Key —
   you'll need these in step 3 below

(Backblaze B2 or AWS S3 work the same way if you'd rather use one of those
instead — just swap the endpoint format.)

### 2. Add a new service in Railway

1. In your Railway project (the same one running the CycleAudit app), click
   **+ New** → **GitHub Repo** → select the same `Parisar` repo again
2. This creates a second service from the same codebase — that's expected,
   we just point it at a different start command (step 4)

### 3. Set environment variables on the new (backup) service

In the new service's **Variables** tab, add:

| Variable | Value |
|---|---|
| `DB_HOST` | same as the main app's `DB_HOST` |
| `DB_PORT` | same as the main app's `DB_PORT` |
| `DB_NAME` | same as the main app's `DB_NAME` |
| `DB_USER` | same as the main app's `DB_USER` |
| `DB_PASS` | same as the main app's `DB_PASS` |
| `BACKUP_S3_ENDPOINT` | `https://<account-id>.r2.cloudflarestorage.com` |
| `BACKUP_S3_BUCKET` | `cycleaudit-backups` |
| `BACKUP_S3_ACCESS_KEY` | from step 1 |
| `BACKUP_S3_SECRET_KEY` | from step 1 |
| `BACKUP_RETENTION_DAYS` | `7` (optional, this is the default) |

### 4. Set the start command and cron schedule

In the new service's **Settings** tab:

- **Custom Start Command:** `bash scripts/backup.sh`
- **Cron Schedule:** `0 2 * * *` (runs daily at 2:00 AM UTC — adjust as needed)

Railway will now build the same Docker image, but instead of starting
Apache, it runs the backup script once per day and then exits until the
next scheduled run. This does **not** count against your app's uptime and
uses negligible compute (a few seconds per day).

### 5. Test it once manually

Before trusting the schedule, trigger a manual run from the Railway
dashboard (**Deployments → Trigger a run**, or redeploy this service) and
check the logs for `Backup job finished successfully.` Then confirm the
`.sql.gz` file actually appears in your R2 bucket.

### 6. (Optional but recommended) Set a bucket lifecycle rule

R2 and B2 both support automatic object expiry. Setting a 7-day lifecycle
rule directly on the bucket is a good belt-and-suspenders backup to the
script's own pruning step (`BACKUP_RETENTION_DAYS`), in case that step is
ever skipped for any reason.

## Restoring from a backup

```bash
# Download the backup file from your R2/B2 bucket first, then:
gunzip -c cycleaudit_YYYYMMDD_HHMMSS.sql.gz | mysql \
    --host=<DB_HOST> --port=<DB_PORT> --user=<DB_USER> --password \
    <DB_NAME>
```

Always restore to a **test database first** to verify the dump is valid
before ever restoring over the live database.
