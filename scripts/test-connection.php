<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$env = [];
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
    }
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$database = $env['DB_DATABASE'] ?? 'support_sla';
$username = $env['DB_USERNAME'] ?? 'root';
$password = $env['DB_PASSWORD'] ?? '';

echo "Support SLA - diagnóstico do MySQL" . PHP_EOL;
echo "Host: {$host}" . PHP_EOL;
echo "Porta: {$port}" . PHP_EOL;
echo "Banco: {$database}" . PHP_EOL;
echo "Usuário: {$username}" . PHP_EOL . PHP_EOL;

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "[OK] Servidor MySQL acessível." . PHP_EOL;
    $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "[OK] O banco {$database} existe ou foi criado." . PHP_EOL;
    $pdo->exec('USE ' . $quotedDatabase);
    $table = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    echo $table ? "[OK] O schema já foi instalado." . PHP_EOL : "[AVISO] O banco está vazio. Execute 1-INSTALAR-E-ABRIR.bat para instalar o schema." . PHP_EOL;
    exit(0);
} catch (PDOException $exception) {
    echo "[ERRO] " . $exception->getMessage() . PHP_EOL;
    echo PHP_EOL . "Verifique: MySQL ativo no ServBay, porta {$port}, usuário {$username} e senha do .env." . PHP_EOL;
    exit(1);
}
