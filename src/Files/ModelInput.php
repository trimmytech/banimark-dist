<?php

namespace Banimark\Files;

use Banimark\Storage\Attachments;

/**
 * Hands attachment CONTENTS to a model that can read them.
 *
 * Until 2026-09-24 a file reached the model only as a marker line
 * ([attached: name](banimark:token)), never its bytes - the right rule for a
 * text-only model, which would otherwise invent what a receipt says. A
 * multimodal model (every current Gemini) can read images, PDFs and plain
 * text, so when the ACTIVE model is flagged as reading files
 * (ProviderPresets::readsAttachments) and the owner has not switched it off
 * (files_ai_read), the driver asks this resolver for the bytes behind each
 * marker and sends them inline.
 *
 * The bytes are read from the FileStore on every turn they are needed and
 * never stored in the conversation - history stays text. Follow-ups work
 * because the marker is still in the stored message; the last few files are
 * re-sent, capped, so a long thread does not grow into a huge request.
 *
 * File contents go to the AI provider. That is what the owner's toggle is for.
 */
final class ModelInput
{
    /** What is handed over as bytes. Anything else stays a marker line only. */
    public const READABLE = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/heic',
        'application/pdf',
        'text/plain', 'text/csv',
    ];

    /** Per file. A phone photo is 2-5 MB; a scanned PDF a few more. */
    public const MAX_FILE_BYTES = 8 * 1024 * 1024;
    /** Per request, all files together - Gemini refuses requests past ~20 MB. */
    public const MAX_REQUEST_BYTES = 15 * 1024 * 1024;
    /** How many of the most recent attachments are re-sent on later turns. */
    public const MAX_FILES = 6;

    /** The owner's switch; on unless explicitly off. */
    public static function enabled(array $settings): bool
    {
        return (string) ($settings['files_ai_read'] ?? '1') !== '0';
    }

    public static function readable(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::READABLE, true);
    }

    /**
     * A per-request resolver: token -> ['mime' => ..., 'b64' => ...] or null
     * (unknown token, unreadable type, over a cap, or bytes not on this store).
     * Keeps a running budget, so the driver can simply ask for every marker it
     * meets - newest turns first - and stop getting bytes once the request is full.
     * Returns null altogether when the owner switched reading off.
     */
    public static function resolver(Attachments $attachments, FileStore $files, array $settings): ?callable
    {
        if (!self::enabled($settings)) {
            return null;
        }
        $spent = 0;
        $count = 0;
        $seen = [];
        return function (string $token) use ($attachments, $files, &$spent, &$count, &$seen): ?array {
            if (isset($seen[$token])) {
                return $seen[$token];
            }
            $seen[$token] = null;
            if ($count >= self::MAX_FILES) {
                return null;
            }
            $row = $attachments->findByToken($token);
            if ($row === null || !self::readable((string) ($row['mime'] ?? ''))) {
                return null;
            }
            $size = (int) ($row['size'] ?? 0);
            if ($size <= 0 || $size > self::MAX_FILE_BYTES || $spent + $size > self::MAX_REQUEST_BYTES) {
                return null;
            }
            // only the store the bytes actually live on (the FileEndpoint's own rule)
            if ($files->name() !== (string) ($row['disk'] ?? '')) {
                return null;
            }
            $bytes = $files->read((string) $row['path']);
            if ($bytes === null || $bytes === '') {
                return null;
            }
            $spent += strlen($bytes);
            $count++;
            return $seen[$token] = ['mime' => strtolower((string) $row['mime']), 'b64' => base64_encode($bytes)];
        };
    }
}
