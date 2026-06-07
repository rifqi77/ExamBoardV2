@echo off
REM Build all Laravel caches for production performance. Run this AFTER deploy
REM and AFTER any change to .env, config, or routes. To undo (for local
REM editing) run:  php artisan optimize:clear
cd /d "%~dp0.."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
echo.
echo Caches built. Re-run after changing .env/config/routes.
