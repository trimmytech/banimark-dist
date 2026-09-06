<?php

namespace Banimark\Files;

/**
 * "Send a test file" - the only honest way to know a storage configuration
 * works, especially for S3, where a wrong region or a stray space in a secret
 * key looks exactly like everything being fine until a customer tries to send
 * a screenshot.
 */
final class SelfTest
{
    /** @return array{ok: bool, message: string} */
    public static function run(FileStore $store): array
    {
        $key = 'banimark/_selftest/'.bin2hex(random_bytes(8)).'.txt';
        $body = "Banimark storage test - written ".gmdate('c')."\n";

        if (!$store->put($key, $body, 'text/plain')) {
            return ['ok' => false, 'message' => 'Could not write the test file. '.($store->lastError() ?: 'No reason was given.')];
        }
        $read = $store->read($key);
        if ($read === null) {
            $store->delete($key);
            return ['ok' => false, 'message' => 'The file was written but could not be read back. '.($store->lastError() ?: '')];
        }
        if ($read !== $body) {
            $store->delete($key);
            return ['ok' => false, 'message' => 'The file came back different from what was written - something between here and the store is altering it.'];
        }
        $link = $store->temporaryUrl($key, 60) !== null;
        if (!$store->delete($key)) {
            return ['ok' => true, 'message' => 'Wrote and read a test file, but could not delete it - check that the key may also delete. '.($store->lastError() ?: '')];
        }
        return ['ok' => true, 'message' => 'Wrote, read back and deleted a test file successfully'
            .($link ? ', and signed links are working.' : '. Files will be streamed through this server.')];
    }
}
