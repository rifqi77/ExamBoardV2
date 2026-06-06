# Database migrations

The `secure_exam` database is **shared** with the original Next.js app (which
created it via Prisma). To bring it under Laravel migrations without
endangering the live data, the schema is captured as a **baseline (squash)**
migration rather than a stack of incremental `create_table` calls.

## Files

| Path | Purpose |
|---|---|
| `database/schema/baseline.sql` | The exact current schema (17 domain tables), captured with `mysqldump --no-data`, rewritten to `CREATE TABLE IF NOT EXISTS`. |
| `database/migrations/2024_01_01_000000_baseline_secure_exam_schema.php` | Replays `baseline.sql` (FK checks off). Idempotent — a no-op on a database that already has the tables. |

Prisma's own `_prisma_migrations` table is intentionally **excluded** — it stays
owned by the Next.js app.

## Fresh environment (new machine / empty DB)

```bash
# 1. create an empty database named in .env (DB_DATABASE)
# 2. build the whole schema from the baseline:
php artisan migrate
```

`migrate` runs the baseline (creating all 17 tables) plus any newer migrations.

## Existing / live database (already populated)

The live DB was **baselined** so `migrate` does nothing destructive there:

```bash
php artisan migrate:install   # creates the `migrations` bookkeeping table
# mark the baseline as already-applied WITHOUT running it:
php artisan tinker --execute='DB::table("migrations")->updateOrInsert(["migration"=>"2024_01_01_000000_baseline_secure_exam_schema"],["batch"=>1]);'
php artisan migrate            # -> "Nothing to migrate."
```

This was already done on this server. You should not need to repeat it.

## Adding a schema change from now on

Use normal, idiomatic Blueprint migrations — **do not** edit the baseline:

```bash
php artisan make:migration add_xyz_to_exams_table
# edit up()/down() with Schema::table(...)
php artisan migrate
```

### ⚠️ Shared-database rule

Because the Next.js (Prisma) app reads the same tables, any schema change must
stay compatible with **both** apps:

1. Make the change in **one** place first (a Laravel migration here, or the
   Prisma schema there) and apply it.
2. Mirror it in the other app's schema definition so the two never drift.
3. Re-capture the baseline when convenient so a fresh build stays current:
   ```bash
   "/c/xampp/mysql/bin/mysqldump" -u root --no-data --compact --skip-set-charset \
     --ignore-table=secure_exam._prisma_migrations secure_exam \
     | sed -e '/^\/\*!40101/d' -e 's/^CREATE TABLE /CREATE TABLE IF NOT EXISTS /' \
     > database/schema/baseline.sql
   ```

## Verifying a baseline rebuilds the real schema (safe, throwaway DB)

```bash
mysql -u root -e "CREATE DATABASE secure_exam_migcheck"
DB_DATABASE=secure_exam_migcheck php artisan migrate --force
# diff should be empty:
diff <(mysqldump -u root --no-data --compact --skip-set-charset --ignore-table=secure_exam._prisma_migrations secure_exam) \
     <(mysqldump -u root --no-data --compact --skip-set-charset --ignore-table=secure_exam_migcheck.migrations secure_exam_migcheck)
mysql -u root -e "DROP DATABASE secure_exam_migcheck"
```

> The framework's default `users`/`cache`/`jobs` scaffold migrations were
> removed — `users` is owned by the baseline, and cache/queue/session drivers
> are `file`/`sync`. If you later switch the queue or cache to the `database`
> driver, add those tables with `php artisan queue:table` / `cache:table`.
