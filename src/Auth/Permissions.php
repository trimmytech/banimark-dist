<?php

namespace Banimark\Auth;

/**
 * What a staff member may do. Owners may do everything; every other account
 * carries an explicit list. Owner-only areas (staff, licence, changelog, the
 * 2FA policy) are not permissions at all - they hang off the owner role.
 */
final class Permissions
{
    /** permission => plain-language label shown to the owner */
    public const ALL = [
        'dashboard.view' => 'See the dashboard and analytics',
        'inbox.view' => 'Open the inbox and read conversations',
        'inbox.reply' => 'Reply to visitors, take over and hand back to the AI',
        'inbox.close' => 'Close conversations',
        'inbox.delete' => 'Delete conversations and a visitor\'s history',
        'tools.manage' => 'Create, edit and delete tools',
        'rules.manage' => 'Edit rules and folders',
        'providers.manage' => 'Manage AI providers and API keys',
        'widget.manage' => 'Change the widget\'s look and embed settings',
        'files.manage' => 'Change where shared files are stored',
        'ai.manage' => 'Tune how the AI behaves (memory, reply length, tone)',
        'data.manage' => 'Retention, deleting all history, flood protection',
        'team.view' => 'See staff activity and response times',
        'notifications.manage' => 'Escalation, email settings and quick replies',
    ];

    /** Ready-made sets an owner can pick, then adjust. */
    public const PRESETS = [
        'viewer' => ['label' => 'View only', 'perms' => ['dashboard.view', 'inbox.view']],
        'agent' => ['label' => 'Agent - answers chats', 'perms' => ['dashboard.view', 'inbox.view', 'inbox.reply', 'inbox.close']],
        'editor' => ['label' => 'Editor - everything except staff, licence and billing', 'perms' => 'all'],
    ];

    /** @return string[] only known permissions, deduplicated, in matrix order */
    public static function normalize(array $list): array
    {
        return array_values(array_filter(array_keys(self::ALL), fn ($p) => in_array($p, $list, true)));
    }

    /** @return string[] */
    public static function preset(string $name): array
    {
        $p = self::PRESETS[$name]['perms'] ?? self::PRESETS['agent']['perms'];
        return $p === 'all' ? array_keys(self::ALL) : $p;
    }

    /**
     * @param array $agent a row from the agents table
     * A NULL permissions column is a pre-0.14 account: it keeps the access it
     * always had (everything a non-owner could reach), never silently less.
     */
    public static function of(array $agent): array
    {
        if (($agent['role'] ?? '') === 'owner') {
            return array_keys(self::ALL);
        }
        $raw = $agent['permissions'] ?? null;
        if ($raw === null || $raw === '') {
            return array_keys(self::ALL);
        }
        $list = json_decode((string) $raw, true);
        return is_array($list) ? self::normalize($list) : [];
    }

    public static function allows(array $agent, string $permission): bool
    {
        return in_array($permission, self::of($agent), true);
    }

    /**
     * Laravel route name → requirement (null = any staff, 'owner', or a permission).
     * Unknown names fall back to 'owner': a new page is closed until it is mapped.
     */
    public static function forRoute(string $name): ?string
    {
        $n = str_replace('banimark.admin.', '', $name);
        foreach ([
            '#^(login|logout|activate|security|asset)#' => null,
            '#^dashboard$#' => 'dashboard.view',
            '#^conversation\.(delete|forget)$#' => 'inbox.delete',
            '#^conversation\.reply$#' => 'inbox.reply',
            '#^conversation\.mode$#' => 'inbox.reply',
            '#^(inbox|conversation|events)#' => 'inbox.view',
            '#^tools#' => 'tools.manage',
            '#^rules#' => 'rules.manage',
            '#^providers#' => 'providers.manage',
            '#^widget#' => 'widget.manage',
            '#^files#' => 'files.manage',
            '#^ai#' => 'ai.manage',
            '#^data#' => 'data.manage',
            '#^team#' => 'team.view',
            '#^(escalation|quick)#' => 'notifications.manage',
            '#^(agents|license|changelog)#' => 'owner',
        ] as $pattern => $req) {
            if (preg_match($pattern, $n)) {
                return $req;
            }
        }
        return 'owner';
    }

    /** Standalone path → requirement, same table. */
    public static function forPath(string $path): ?string
    {
        foreach ([
            '#^/(login|logout|activate|security|assets)#' => null,
            '#^/?$#' => 'dashboard.view',
            '#^/conversation/[a-f0-9]+/(delete|forget)$#' => 'inbox.delete',
            '#^/conversation/[a-f0-9]+/(reply|mode)$#' => 'inbox.reply',
            '#^/(inbox|conversation|events)#' => 'inbox.view',
            '#^/tools#' => 'tools.manage',
            '#^/rules#' => 'rules.manage',
            '#^/providers#' => 'providers.manage',
            '#^/widget#' => 'widget.manage',
            '#^/files#' => 'files.manage',
            '#^/ai#' => 'ai.manage',
            '#^/data#' => 'data.manage',
            '#^/team#' => 'team.view',
            '#^/(escalation|quick-replies)#' => 'notifications.manage',
            '#^/(agents|license|changelog)#' => 'owner',
        ] as $pattern => $req) {
            if (preg_match($pattern, $path)) {
                return $req;
            }
        }
        return 'owner';
    }

    /** The preset a list matches exactly, or 'custom'. */
    public static function presetOf(array $perms): string
    {
        $perms = self::normalize($perms);
        foreach (self::PRESETS as $name => $preset) {
            if (self::normalize(self::preset($name)) === $perms) {
                return $name;
            }
        }
        return 'custom';
    }
}
