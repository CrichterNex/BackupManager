<?php

namespace App\Services\Engine;

use Throwable;

/**
 * Random file restore: choose a file in the backup location, copy it to a staging folder as a restore, and
 * compare SHA-256 of the backup copy and the restored copy. The staging folder is always removed.
 */
final class RandomRestoreRunner
{
    private float $deadline;

    private function __construct(private string $stagingRoot, private int $timeoutSeconds, private int $maxFiles) {}

    /** @return array{success: bool, hash_algorithm?: string, hash_original?: string, hash_restored?: string, failure_type?: string, severity?: string, failed_item?: string, error_message?: string} */
    public static function run(string $backupPath, string $stagingDir = '', int $timeoutSeconds = 300, int $maxFiles = 200_000): array
    {
        $runner = new self($stagingDir !== '' ? $stagingDir : sys_get_temp_dir(), $timeoutSeconds, $maxFiles);

        return $runner->execute($backupPath);
    }

    private static function fail(string $type, string $severity, string $item, string $message, array $extra = []): array
    {
        return ['success' => false, 'failure_type' => $type, 'severity' => $severity, 'failed_item' => $item, 'error_message' => $message] + $extra;
    }

    private function checkDeadline(): void
    {
        if (microtime(true) > $this->deadline) {
            throw new TestTimeout;
        }
    }

    private function execute(string $root): array
    {
        $this->deadline = microtime(true) + $this->timeoutSeconds;
        $staging = null;
        try {
            if (! is_dir($root)) {
                return self::fail('file_missing', 'high', $root, 'The backup location does not exist or is not reachable.');
            }
            if (! is_readable($root)) {
                return self::fail('access_denied', 'high', $root, 'Access was denied reading the backup location.');
            }
            $file = $this->pickRandomFile($root);
            if ($file === null) {
                return self::fail('file_missing', 'high', $root, 'The backup location contains no files to restore.');
            }
            $original = $this->hash($file);
            $staging = $this->stagingRoot.DIRECTORY_SEPARATOR.'bms-restore-'.bin2hex(random_bytes(6));
            if (! mkdir($staging, 0700, true)) {
                return self::fail('restore_failed', 'high', $root, 'Restore failed: could not create the staging folder.');
            }
            $restored = $staging.DIRECTORY_SEPARATOR.'restored';
            if (! @copy($file, $restored)) {
                return self::fail(is_readable($file) ? 'restore_failed' : 'access_denied', 'high', $file, is_readable($file) ? 'Restore failed: the file could not be copied.' : 'Access was denied reading the backup file.');
            }
            $restoredHash = $this->hash($restored);
            if ($original !== $restoredHash) {
                return self::fail('hash_mismatch', 'critical', $file, 'The restored file does not match the backup copy.', ['hash_algorithm' => 'sha256', 'hash_original' => $original, 'hash_restored' => $restoredHash]);
            }

            return ['success' => true, 'hash_algorithm' => 'sha256', 'hash_original' => $original, 'hash_restored' => $restoredHash, 'failed_item' => $file];
        } catch (TestTimeout) {
            return self::fail('timeout', 'high', $root, 'The restore test timed out.');
        } catch (Throwable $e) {
            return self::fail('restore_failed', 'high', $root, 'Restore failed: '.$e->getMessage());
        } finally {
            if ($staging !== null) {
                self::removeTree($staging);
            }
        }
    }

    /** Pick one file uniformly at random from a tree without holding the listing in memory. Symlinks are skipped. */
    private function pickRandomFile(string $root): ?string
    {
        $chosen = null;
        $seen = 0;
        $stack = [$root];
        while ($stack && $seen < $this->maxFiles) {
            $this->checkDeadline();
            $dir = array_pop($stack);
            $handle = @opendir($dir);
            if ($handle === false) {
                if ($dir === $root) {
                    throw new \RuntimeException('The backup location could not be read.');
                }

                continue; // an unreadable subfolder is skipped
            }
            while (($name = readdir($handle)) !== false && $seen < $this->maxFiles) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$name;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $stack[] = $path;
                } elseif (is_file($path)) {
                    $seen++;
                    if (random_int(1, $seen) === 1) {
                        $chosen = $path;
                    }
                }
            }
            closedir($handle);
        }

        return $chosen;
    }

    private function hash(string $file): string
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Access was denied reading '.$file);
        }
        $ctx = hash_init('sha256');
        while (! feof($in)) {
            $this->checkDeadline();
            hash_update($ctx, (string) fread($in, 1 << 20));
        }
        fclose($in);

        return hash_final($ctx);
    }

    private static function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $p = $dir.DIRECTORY_SEPARATOR.$name;
            is_dir($p) && ! is_link($p) ? self::removeTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

/** Internal signal for the test time limit. */
final class TestTimeout extends \Exception {}
