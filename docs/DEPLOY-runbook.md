# Deploy runbook — `feature/spec-gaps-elite yar-cap-ticketing`

Run these on the server **after** the new code is in place (after your usual pull/push-deploy
to `188.212.22.118`). All commands run from the `api/` directory. **Take a DB backup first** —
two migrations move uploaded files on disk.

## 0. Backup (once, before anything)
```bash
# DB dump
mysqldump -u <user> -p <db_name> > backup-before-spec-gaps.sql
# (optional) snapshot the storage dir
tar czf storage-public-backup.tgz storage/app/public
```

## 1. Dependencies (only if composer.lock changed — it did not, but safe)
```bash
composer install --no-dev --optimize-autoloader
```

## 2. Ensure the private storage dir exists and is writable
The new private `financial` disk lives at `storage/app/financial`. The file-move migrations
need it writable by the same user that runs PHP/queue.
```bash
mkdir -p storage/app/financial
# match ownership to your web/worker user (example):
chown -R www-data:www-data storage/app/financial
chmod -R 775 storage/app/financial
```

## 3. Public symlink (idempotent)
```bash
php artisan storage:link   # ensures public/storage exists; `financial` is intentionally NOT linked
```

## 4. Run migrations  ⚠️ includes two file-move data migrations
```bash
php artisan migrate --force
```
This adds the schema (badge_purchase_amount, ticketing fields, ticket_notes,
ticket_files.ticket_id) **and** relocates existing receipts + invoice PDFs from
`storage/app/public/{wallet_receipts,supplier_receipts,order_receipts,invoices}` to
`storage/app/financial/…`. The moves are idempotent and preserve relative paths, so stored
`file_path` / `receipt_path` values keep working.

## 5. Rebuild caches (set any new env vars BEFORE this step)
```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
> Never read env() outside config — after `config:cache`, only `config()` values are live.

## 6. Restart the queue worker  ⚠️ required
All event listeners and SMS/notification jobs implement `ShouldQueue`. The worker must run and
must reload the new code, or notifications/SMS/emails silently won't send.
```bash
php artisan queue:restart       # tells running workers to gracefully restart with new code
# ensure a worker is actually running (supervisor / systemd / horizon):
# php artisan queue:work --tries=3
```

## 7. Confirm the scheduler is running (cron)
These jobs rely on the Laravel scheduler being wired to system cron:
`CheckReservationExpiryJob`, `CheckSupplierBalancesJob`, RFM / inventory / product-view jobs,
and Podium deposit polling. Verify the single cron entry exists:
```
* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Smoke-check
```bash
php artisan migrate:status      # all green
php artisan about               # env, cache, queue driver sane
```
- Confirm no files remain under `storage/app/public/{wallet_receipts,supplier_receipts,order_receipts,invoices}`.
- A wallet-receipt download still works (it now reads the private disk).
- An order invoice download still works (regenerated on the fly).

## Rollback (if needed)
```bash
php artisan migrate:rollback --step=6   # reverses the 6 migrations of this release
```
The two file-move migrations' `down()` move the files back from `financial` → `public`.
Then deploy the previous code revision and re-run steps 5–6.

## Notes
- **New env vars** are all optional (safe defaults) — see `docs/CHANGELOG-spec-gaps.md`.
- **Pasargad** stays inert until its credentials are set; money-in keeps working via
  شناسه‌واریز / Podium direct deposit.
- **EliteYAR cap** stays dormant until `users.badge_purchase_amount` is populated
  (Saze Nod import — future).
