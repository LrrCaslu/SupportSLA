<?php

declare(strict_types=1);

namespace SupportSla\Support;

use PDO;

final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $email, string $password): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, organization_id, name, email, password_hash, role, active FROM users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        $user = $statement->fetch();

        if (!$user || !(bool) $user['active'] || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($user['password_hash']);
        self::$user = $user;
        self::audit((int) $user['id'], 'login', 'users', (int) $user['id'], null, null);
        return self::publicUser($user);
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::publicUser(self::$user);
        }

        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id < 1) {
            return null;
        }

        $statement = Database::connection()->prepare('SELECT id, organization_id, name, email, role, active FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user || !(bool) $user['active']) {
            self::logout(false);
            return null;
        }

        self::$user = $user;
        return self::publicUser($user);
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            Response::error('Autenticação necessária.', 401);
        }
        return $user;
    }

    public static function requireRole(array $roles): array
    {
        $user = self::requireUser();
        if (!in_array($user['role'], $roles, true)) {
            Response::error('Você não possui permissão para esta operação.', 403);
        }
        return $user;
    }

    public static function logout(bool $audit = true): void
    {
        if ($audit && self::$user) {
            self::audit((int) self::$user['id'], 'logout', 'users', (int) self::$user['id'], null, null);
        }
        $_SESSION = [];
        if (session_id() !== '') {
            session_destroy();
        }
        self::$user = null;
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    public static function checkCsrf(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$header || !hash_equals(self::csrf(), $header)) {
            Response::error('Token CSRF inválido.', 419);
        }
    }

    public static function canViewOrganization(?int $organizationId, array $user): bool
    {
        return $user['role'] === 'superadmin' || (int) $user['organization_id'] === (int) $organizationId;
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'organization_id' => $user['organization_id'] !== null ? (int) $user['organization_id'] : null,
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'active' => (bool) $user['active'],
            'csrf_token' => self::csrf(),
        ];
    }

    public static function audit(?int $userId, string $action, string $entity, ?int $entityId, ?array $before, ?array $after): void
    {
        try {
            $statement = Database::connection()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, before_data, after_data, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
            $statement->execute([
                $userId,
                $action,
                $entity,
                $entityId,
                $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (\Throwable) {
            // Auditoria não pode derrubar o fluxo principal em uma instalação inicial.
        }
    }
}

