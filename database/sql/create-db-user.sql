-- ExamBoard — least-privilege database account
-- =================================================================
-- The app ships pointing at MySQL `root` with no password, which is convenient
-- for first boot but unsafe in production: root can read EVERY database and run
-- server-admin commands. This script creates a dedicated account scoped to ONLY
-- the ExamBoard schema(s).
--
-- HOW TO RUN (XAMPP, from an elevated shell at the project root):
--   1. Change the password below to a long random string.
--   2. C:\xampp\mysql\bin\mysql.exe -u root < database\sql\create-db-user.sql
--   3. Put the same credentials in .env:
--          DB_USERNAME=examboard
--          DB_PASSWORD=<the password you chose>
--   4. php artisan config:clear && php artisan app:doctor    (expect all PASS)
--
-- `ALL PRIVILEGES ON <db>.*` grants full DML+DDL on that ONE schema only — it
-- does NOT include FILE, PROCESS, SUPER, SHUTDOWN, CREATE USER or GRANT, so a
-- compromised app cannot reach other databases or administer the server.
-- To lock down further, split into a DML-only runtime user and a separate
-- migration user (see docs/HARDENING.md).
-- =================================================================

CREATE USER IF NOT EXISTS 'examboard'@'localhost'
    IDENTIFIED BY 'CHANGE_ME_to_a_long_random_password';

-- Production schema.
GRANT ALL PRIVILEGES ON `secure_exam`.* TO 'examboard'@'localhost';

-- Test schema (so `php artisan test` can create/drop tables). Optional.
GRANT ALL PRIVILEGES ON `secure_exam_test`.* TO 'examboard'@'localhost';

FLUSH PRIVILEGES;
