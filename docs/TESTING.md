# Automated tests

A PHPUnit suite covering the highest-risk logic. It runs on **MySQL** (the
schema uses enums + `CHECK(json_valid())`, so SQLite can't represent it) against
a dedicated **`secure_exam_test`** database — never the live `secure_exam`.

## One-time setup

```bash
# create the throwaway test database (schema is built by migrations)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS secure_exam_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

`phpunit.xml` already points the test connection at `secure_exam_test`
(`DB_CONNECTION=mysql`, `DB_DATABASE=secure_exam_test`). `RefreshDatabase`
rebuilds the schema from the migrations (baseline + audit_logs) on each run.

## Run

```bash
php artisan test                 # whole suite
php artisan test --filter Auth   # one class / method
```

## Safety

`tests/TestCase::setUp()` aborts **before** anything touches the database if
`DB_DATABASE` isn't a `*_test` name — so the destructive suite can never wipe
`secure_exam`. (Verified: pointing it elsewhere throws "Refusing to run tests
against non-test database".)

## Coverage

| Suite | Locks in |
|---|---|
| `Unit/ScoringTest` | grading: single/multi (partial credit) / numeric / essay-pending / percent |
| `Unit/ShuffleTest` | seeded shuffle determinism (anchored to the Next.js output) |
| `Unit/CapabilitiesTest` | capability gating (admin bypass, default-on, explicit deny) |
| `Unit/CryptoSecretsTest` | AES encrypt/decrypt round-trips + wire format |
| `Feature/AuthTest` | login success / wrong / deactivated / 5-strike lockout |
| `Feature/ExamFlowTest` | show → submit scoring + anti-cheat consolidation; strict 1-attempt block |
| `Feature/ItemAnalysisTest` | difficulty p-value, too-easy flag, topic mastery |

## Adding tests

Put unit tests (no DB) in `tests/Unit`, DB/HTTP tests in `tests/Feature` with
`use Illuminate\Foundation\Testing\RefreshDatabase;`. Create rows with the
models or `DB::table()` (UUID string PKs). CI suggestion: provision a MySQL
service, create `secure_exam_test`, then `php artisan test`.
