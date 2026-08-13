@echo off
setlocal
set ROOT=%~dp0
php "%ROOT%artisan" prism:check %*
