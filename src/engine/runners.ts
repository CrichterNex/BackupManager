import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { copyFile, mkdtemp, opendir, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { pipeline } from 'node:stream/promises';
import type { TestResult } from '../services.js';

export interface RunnerOptions {
  stagingDir?: string;
  timeoutMs?: number;
  /** Stop counting candidates after this many files; the sample is still uniform over those seen. */
  maxFilesScanned?: number;
}

const sha256 = async (file: string, signal: AbortSignal): Promise<string> => {
  const hash = createHash('sha256');
  await pipeline(createReadStream(file), hash, { signal });
  return hash.digest('hex');
};

/** Pick one file uniformly at random from a tree without holding the listing in memory. Symlinks are skipped. */
async function pickRandomFile(root: string, signal: AbortSignal, max: number): Promise<string | undefined> {
  let chosen: string | undefined;
  let seen = 0;
  const stack = [root];
  while (stack.length && seen < max) {
    signal.throwIfAborted();
    const dir = stack.pop()!;
    let handle;
    try {
      handle = await opendir(dir);
    } catch (err) {
      if (dir === root) throw err; // an unreadable root is a failure; an unreadable subfolder is skipped
      continue;
    }
    for await (const entry of handle) {
      if (entry.isDirectory()) stack.push(path.join(dir, entry.name));
      else if (entry.isFile()) {
        seen++;
        if (Math.floor(Math.random() * seen) === 0) chosen = path.join(dir, entry.name);
        if (seen >= max) break;
      }
    }
  }
  return chosen;
}

const fail = (r: Omit<TestResult, 'success'>): TestResult => ({ success: false, ...r });

/**
 * Random file restore: choose a file in the backup location, copy it to a staging folder as a restore,
 * and compare SHA-256 of the backup copy and the restored copy. The staging folder is always removed.
 */
export async function runRandomRestore(backupPath: string, opts: RunnerOptions = {}): Promise<TestResult> {
  const signal = AbortSignal.timeout(opts.timeoutMs ?? 5 * 60_000);
  let staging: string | undefined;
  try {
    const file = await pickRandomFile(backupPath, signal, opts.maxFilesScanned ?? 200_000);
    if (!file) {
      return fail({ failure_type: 'file_missing', severity: 'high', failed_item: backupPath, error_message: 'The backup location contains no files to restore.' });
    }
    const original = await sha256(file, signal);
    staging = await mkdtemp(path.join(opts.stagingDir || os.tmpdir(), 'bms-restore-'));
    const restored = path.join(staging, 'restored');
    await copyFile(file, restored);
    const restoredHash = await sha256(restored, signal);
    if (original !== restoredHash) {
      return fail({
        failure_type: 'hash_mismatch', severity: 'critical', failed_item: file, hash_algorithm: 'sha256',
        hash_original: original, hash_restored: restoredHash, error_message: 'The restored file does not match the backup copy.',
      });
    }
    return { success: true, hash_algorithm: 'sha256', hash_original: original, hash_restored: restoredHash, failed_item: file };
  } catch (err) {
    const e = err as NodeJS.ErrnoException;
    if (e.name === 'TimeoutError' || e.name === 'AbortError') {
      return fail({ failure_type: 'timeout', severity: 'high', failed_item: backupPath, error_message: 'The restore test timed out.' });
    }
    if (e.code === 'EACCES' || e.code === 'EPERM') {
      return fail({ failure_type: 'access_denied', severity: 'high', failed_item: e.path ?? backupPath, error_message: 'Access was denied reading the backup location.' });
    }
    if (e.code === 'ENOENT' || e.code === 'ENOTDIR') {
      return fail({ failure_type: 'file_missing', severity: 'high', failed_item: e.path ?? backupPath, error_message: 'The backup location does not exist or is not reachable.' });
    }
    return fail({ failure_type: 'restore_failed', severity: 'high', failed_item: backupPath, error_message: `Restore failed: ${e.message}` });
  } finally {
    if (staging) await rm(staging, { recursive: true, force: true }).catch(() => undefined);
  }
}
