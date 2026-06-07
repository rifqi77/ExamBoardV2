@echo off
REM ExamBoard queue worker. Run this at startup (Task Scheduler "At log on" /
REM "At startup", or as an NSSM service) so async AI jobs are processed.
REM --max-time recycles the worker hourly so code/config changes are picked up.
cd /d "%~dp0.."
:loop
php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=3 --rest=1
REM If the worker exits (max-time, fatal), wait briefly and restart.
timeout /t 5 /nobreak >nul
goto loop
