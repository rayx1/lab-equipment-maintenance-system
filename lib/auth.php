<?php
declare(strict_types=1);

const ROLES = ['LAB_USER', 'TECHNICIAN', 'LAB_MANAGER', 'ADMIN'];

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' || !str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return trim(substr($header, 7));
}

function auth_user(PDO $pdo): ?array
{
    $token = bearer_token();
    if ($token === null || $token === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role
        FROM user_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.token = :token AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_auth(PDO $pdo): array
{
    $user = auth_user($pdo);
    if ($user === null) {
        json_response(401, ['message' => 'Unauthorized']);
    }
    return $user;
}

function require_roles(array $user, array $allowed): void
{
    if (!in_array($user['role'], $allowed, true)) {
        json_response(403, ['message' => 'Forbidden']);
    }
}

function issue_token(PDO $pdo, string $userId, int $ttlHours): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("
        INSERT INTO user_tokens (id, user_id, token, expires_at, created_at)
        VALUES (UUID(), :user_id, :token, DATE_ADD(NOW(), INTERVAL :ttl HOUR), NOW())
    ");
    $stmt->bindValue(':user_id', $userId);
    $stmt->bindValue(':token', $token);
    $stmt->bindValue(':ttl', $ttlHours, PDO::PARAM_INT);
    $stmt->execute();
    return $token;
}

