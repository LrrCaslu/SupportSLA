@echo off
setlocal EnableExtensions
title Support SLA - Instalar e abrir

set "PROJECT_DIR=%~dp0"
cd /d "%PROJECT_DIR%"

echo.
echo ============================================================
echo              SUPPORT SLA - INSTALACAO LOCAL
echo ============================================================
echo.
echo Projeto: %PROJECT_DIR%
echo Banco: support_sla
echo Dominio esperado: http://system.test
echo.

set "PHP_EXE="
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"

if not defined PHP_EXE (
    for /f "delims=" %%D in ('dir /b /ad /o-n "C:\ServBay\package\php" 2^>nul') do (
        if exist "C:\ServBay\package\php\%%D\bin\php.exe" if not defined PHP_EXE set "PHP_EXE=C:\ServBay\package\php\%%D\bin\php.exe"
    )
)

if not defined PHP_EXE (
    echo [ERRO] PHP nao foi encontrado.
    echo Abra o ServBay, instale/ative o PHP 8.4 e tente novamente.
    pause
    exit /b 1
)

echo PHP encontrado em: %PHP_EXE%
echo.

call "%PHP_EXE%" -r "if (!extension_loaded('pdo')) { fwrite(STDERR, '[ERRO] A extensao PDO nao esta habilitada neste PHP.' . PHP_EOL); exit(10); } if (!extension_loaded('pdo_mysql')) { fwrite(STDERR, '[ERRO] A extensao pdo_mysql nao esta habilitada neste PHP.' . PHP_EOL); exit(11); } echo '[OK] PDO e pdo_mysql estao habilitadas.' . PHP_EOL;"
if errorlevel 1 (
    echo.
    echo O PHP do ServBay nao carregou as extensoes necessarias.
    echo Confira a aba Extensions do PHP selecionado no ServBay e reinicie o pacote.
    pause
    exit /b 1
)

if not exist "%PROJECT_DIR%.env" (
    echo [ERRO] O arquivo .env nao foi encontrado.
    pause
    exit /b 1
)

echo Criando banco, tabelas e dados de demonstracao...
call "%PHP_EXE%" "%PROJECT_DIR%scripts\install.php"
if errorlevel 1 (
    echo.
    echo [ERRO] A instalacao nao foi concluida.
    echo Confira se o MySQL do ServBay esta ativo e se a senha e ServBay.dev.
    pause
    exit /b 1
)

echo.
echo Instalacao concluida. Abrindo o sistema...
start "" "http://system.test"
echo.
echo Se o navegador nao abrir, acesse manualmente: http://system.test
pause
exit /b 0
