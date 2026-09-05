<?php

namespace Banimark\Auth;

/** Staff accounts (PDO repo). The first agent is the 'owner' - only owners
 *  may add or remove other staff. */
class Agents
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /** @return int|string|false new id, or false when the email already exists */
    public function create(string $name, string $email, string $password, string $role = 'agent'): int|string|false
    {
        $email = strtolower(trim($email));
        if ($this->findByEmail($email)) {
            return false;
        }
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}agents (name, email, password, role, enabled, created_at) VALUES (?, ?, ?, ?, 1, ?)");
        $st->execute([trim($name) ?: $email, $email, password_hash($password, PASSWORD_DEFAULT), $role === 'owner' ? 'owner' : 'agent', date('Y-m-d H:i:s')]);
        return $this->pdo->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}agents WHERE email = ?");
        $st->execute([strtolower(trim($email))]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}agents WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int, array> */
    public function all(): array
    {
        return $this->pdo->query("SELECT id, name, email, role, enabled, totp_enabled FROM {$this->prefix}agents ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}agents")->fetchColumn();
    }

    public function delete(int $id): void
    {
        // never leave the desk with zero owners
        $owners = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}agents WHERE role = 'owner'")->fetchColumn();
        $target = $this->find($id);
        if ($target && $target['role'] === 'owner' && $owners <= 1) {
            return;
        }
        $st = $this->pdo->prepare("DELETE FROM {$this->prefix}agents WHERE id = ?");
        $st->execute([$id]);
    }

    /* ---------------- two-factor (TOTP) ---------------- */

    /** Start enrolment: a fresh secret, NOT yet enforced until confirmed. */
    public function beginTotp(int $id): string
    {
        $secret = Totp::generateSecret();
        $this->pdo->prepare("UPDATE {$this->prefix}agents SET totp_secret = ?, totp_enabled = 0 WHERE id = ?")->execute([$secret, $id]);
        return $secret;
    }

    /** Confirm enrolment with a code from the app - only then does 2FA switch on. */
    public function confirmTotp(int $id, string $code): bool
    {
        $agent = $this->find($id);
        if (!$agent || (string) ($agent['totp_secret'] ?? '') === '' || !Totp::verify((string) $agent['totp_secret'], $code)) {
            return false;
        }
        $this->pdo->prepare("UPDATE {$this->prefix}agents SET totp_enabled = 1 WHERE id = ?")->execute([$id]);
        return true;
    }

    /** Switch 2FA off and forget the secret (self-service, or an owner resetting a locked-out colleague). */
    public function resetTotp(int $id): void
    {
        $this->pdo->prepare("UPDATE {$this->prefix}agents SET totp_secret = '', totp_enabled = 0 WHERE id = ?")->execute([$id]);
    }

    public function totpEnabled(int $id): bool
    {
        $agent = $this->find($id);
        return $agent !== null && (int) ($agent['totp_enabled'] ?? 0) === 1;
    }

    /** @return string[] every enabled agent's email - the default escalation audience */
    public function emails(): array
    {
        return $this->pdo->query("SELECT email FROM {$this->prefix}agents WHERE enabled = 1")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }
}
