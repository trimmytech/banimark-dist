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

    /**
     * Invite a colleague: the account exists but is PENDING - no password, no
     * login - until they open the emailed link and set their own. The raw token
     * is returned once for the link; only its hash is stored.
     *
     * @return array{id: int, token: string}|false false when the email is taken
     */
    public function invite(string $name, string $email, string $role, array $permissions): array|false
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $this->findByEmail($email)) {
            return false;
        }
        $token = bin2hex(random_bytes(24));
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}agents (name, email, password, role, enabled, status, invite_token, invited_at, permissions, created_at) VALUES (?, ?, ?, ?, 0, 'pending', ?, ?, ?, ?)");
        $st->execute([
            trim($name) ?: $email, $email,
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), // unusable until they set their own
            $role === 'owner' ? 'owner' : 'agent',
            hash('sha256', $token), date('Y-m-d H:i:s'),
            json_encode(Permissions::normalize($permissions)), date('Y-m-d H:i:s'),
        ]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'token' => $token];
    }

    /** A fresh link for someone who lost theirs; the old one stops working. */
    public function reinvite(int $id): ?string
    {
        $agent = $this->find($id);
        if (!$agent || ($agent['status'] ?? 'active') !== 'pending') {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        $this->pdo->prepare("UPDATE {$this->prefix}agents SET invite_token = ?, invited_at = ? WHERE id = ?")
            ->execute([hash('sha256', $token), date('Y-m-d H:i:s'), $id]);
        return $token;
    }

    /** Links are good for 7 days. */
    public const INVITE_TTL = 7 * 86400;

    public function findByInviteToken(string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}agents WHERE invite_token = ? AND status = 'pending'");
        $st->execute([hash('sha256', $token)]);
        $agent = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$agent || strtotime((string) $agent['invited_at']) < time() - self::INVITE_TTL) {
            return null;
        }
        return $agent;
    }

    /** The invitee sets their password: the account becomes usable. */
    public function activate(int $id, string $name, string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }
        $st = $this->pdo->prepare("UPDATE {$this->prefix}agents SET name = COALESCE(NULLIF(?, ''), name), password = ?, enabled = 1, status = 'active', invite_token = '', activated_at = ? WHERE id = ? AND status = 'pending'");
        $st->execute([trim($name), password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $id]);
        return $st->rowCount() === 1;
    }

    /** @param string[] $permissions */
    public function touch(int $id, ?int $now = null): void
    {
        try {
            $this->pdo->prepare("UPDATE {$this->prefix}agents SET last_active_at = ? WHERE id = ?")->execute([$now ?? time(), $id]);
        } catch (\Throwable $e) {
            // presence is decoration - never let it break a request
        }
    }

    public function setPermissions(int $id, array $permissions): void
    {
        $this->pdo->prepare("UPDATE {$this->prefix}agents SET permissions = ? WHERE id = ? AND role <> 'owner'")
            ->execute([json_encode(Permissions::normalize($permissions)), $id]);
    }

    public function setRole(int $id, string $role): void
    {
        $owners = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}agents WHERE role = 'owner'")->fetchColumn();
        $target = $this->find($id);
        if ($role !== 'owner' && $target && $target['role'] === 'owner' && $owners <= 1) {
            return; // never demote the last owner
        }
        $this->pdo->prepare("UPDATE {$this->prefix}agents SET role = ? WHERE id = ?")->execute([$role === 'owner' ? 'owner' : 'agent', $id]);
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
        return $this->pdo->query("SELECT id, name, email, role, enabled, totp_enabled, status, invited_at, activated_at, permissions FROM {$this->prefix}agents ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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
