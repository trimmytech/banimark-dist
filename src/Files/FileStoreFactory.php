<?php

namespace Banimark\Files;

/**
 * Settings -> a store. Local unless the owner configured S3 on the Files page.
 * Readable in the shipped build so an integrator can swap in their own store.
 */
final class FileStoreFactory
{
    /** @param array<string,string> $settings the settings table */
    public static function make(array $settings, string $defaultLocalDir): FileStore
    {
        if (($settings['files_driver'] ?? 'local') === 's3') {
            return new S3FileStore([
                'bucket' => (string) ($settings['files_s3_bucket'] ?? ''),
                'region' => (string) ($settings['files_s3_region'] ?? 'us-east-1'),
                'key' => (string) ($settings['files_s3_key'] ?? ''),
                'secret' => (string) ($settings['files_s3_secret'] ?? ''),
                'endpoint' => (string) ($settings['files_s3_endpoint'] ?? ''),
                'prefix' => (string) ($settings['files_s3_prefix'] ?? ''),
                'path_style' => ($settings['files_s3_path_style'] ?? '0') === '1',
            ]);
        }
        $dir = trim((string) ($settings['files_local_path'] ?? ''));
        return new LocalFileStore($dir !== '' ? $dir : $defaultLocalDir);
    }

    public static function enabled(array $settings): bool
    {
        return ($settings['files_enabled'] ?? '1') === '1';
    }

    /** Is the configured store usable at all? (missing S3 fields are the common case) */
    public static function misconfigured(array $settings): string
    {
        if (($settings['files_driver'] ?? 'local') !== 's3') {
            return '';
        }
        foreach (['files_s3_bucket' => 'bucket', 'files_s3_region' => 'region', 'files_s3_key' => 'access key', 'files_s3_secret' => 'secret key'] as $k => $label) {
            if (trim((string) ($settings[$k] ?? '')) === '') {
                return 'S3 is selected but the '.$label.' is empty.';
            }
        }
        return '';
    }
}
