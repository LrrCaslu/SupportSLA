@echo off
setlocal EnableExtensions
title Support SLA - Testar MySQL

set "PROJECT_DIR=%~dp0"
cd /d "%PROJECT_DIR%"
set "PHP_EXE="
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
if not defined PHP_EXE (
    for /f "delims=" %%D in ('dir /b /ad /o-n "C:\ServBay\package\php" 2^>nul') do (
        if exist "C:\ServBay\package\php\%%D\bin\php.exe" if not defined PHP_EXE set "PHP_EXE=C:\ServBay\package\php\%%D\bin\php.exe"
    )
)
if not defined PHP_EXE (
    echo [ERRO] PHP nao foi encontrado no PATH ou no ServBay.
    pause
    exit /b 1
)

call "%PHP_EXE%" "%PROJECT_DIR%scripts\test-connection.php"
echo.
pause
