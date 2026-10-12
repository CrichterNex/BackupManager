<?php

namespace App\Support;

/** Validation and joining for the paths admins enter on backup jobs. */
final class BackupPaths
{
    /** Folders on the source server. Windows (C:\x, \\host\share) and Unix (/x) styles are both accepted. */
    public static function source(string $path): string
    {
        $path = trim($path);
        $norm = str_replace('\\', '/', $path);
        $absolute = str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:(/|$)#', $norm);
        if ($path === '' || strlen($path) > 512 || str_contains($path, "\0") || ! $absolute || in_array('..', explode('/', $norm), true)) {
            throw ApiException::badRequest("\"$path\" is not a valid folder. Use a full path such as /var/www or D:\\Data, with no \"..\".");
        }

        return $path;
    }

    /** @return list<string> */
    public static function sourceList(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out[] = self::source((string) $p);
        }

        return array_values(array_unique($out));
    }

    /**
     * A path made comparable: one slash style, no trailing slash, and lower case for Windows-style paths (drive
     * letters and UNC shares ignore case, Unix paths do not).
     */
    private static function key(string $path): string
    {
        $norm = rtrim(str_replace('\\', '/', trim($path)), '/');
        $windows = preg_match('#^[A-Za-z]:#', $norm) || str_starts_with($norm, '//');

        return $windows ? strtolower($norm) : $norm;
    }

    /** The last folder name of a path, comparable across Windows and Unix styles. */
    public static function baseName(string $path): string
    {
        $key = self::key($path);

        return substr($key, (int) strrpos($key, '/') + (str_contains($key, '/') ? 1 : 0));
    }

    /**
     * A layout says how the backup tool places the source inside the destination. Refuse combinations that cannot be
     * traced back: "contents" only works for one folder, "by folder name" needs different names, and any layout needs
     * a stated scope.
     *
     * @param  list<string>  $folders
     */
    public static function assertLayout(?string $layout, ?string $scopeType, array $folders): void
    {
        if ($layout === null) {
            return;
        }
        if ($scopeType === null) {
            throw ApiException::badRequest('Say what the job backs up (full server or specific folders) before choosing how it is laid out.');
        }
        if ($layout === 'contents' && ($scopeType !== 'folders' || count($folders) !== 1)) {
            throw ApiException::badRequest('The "contents directly in the destination" layout only works for a job that backs up exactly one folder.');
        }
        if ($layout === 'by_folder_name') {
            if ($scopeType !== 'folders') {
                throw ApiException::badRequest('The "one sub-folder per source folder" layout needs a list of folders.');
            }
            $names = array_map(fn ($f) => self::baseName($f), $folders);
            if (count($names) !== count(array_unique($names))) {
                throw ApiException::badRequest('Two of the folders end in the same name, so their backups could not be told apart in a sub-folder per folder.');
            }
        }
    }

    /** True when $child is strictly inside $parent (not the same folder). */
    public static function isBelow(string $child, string $parent): bool
    {
        $c = self::key($child);
        $p = self::key($parent);

        return $c !== $p && str_starts_with($c.'/', $p.'/');
    }

    /**
     * Folders to leave out of a backup, for example C:\Folder\Sub1 when C:\Folder is backed up. With a folder list, each
     * exclusion must sit strictly inside one of the folders being backed up. Otherwise it would change nothing, or it
     * would remove a folder that is meant to be backed up. For a full-server backup any absolute folder is allowed.
     *
     * @param  list<string>|null  $includes  the folders being backed up, or null for a full-server backup
     * @return list<string>
     */
    public static function exclusionList(array $excluded, ?array $includes): array
    {
        $out = [];
        foreach (self::sourceList($excluded) as $e) {
            if ($includes !== null) {
                foreach ($includes as $i) {
                    if (self::key($e) === self::key($i)) {
                        throw ApiException::badRequest("\"$e\" is a folder you are backing up. Remove it from the folder list instead of excluding it.");
                    }
                    if (self::isBelow($i, $e)) {
                        throw ApiException::badRequest("\"$e\" contains \"$i\", which is being backed up, so excluding it would remove that folder too.");
                    }
                }
                if (! collect($includes)->contains(fn ($i) => self::isBelow($e, $i))) {
                    throw ApiException::badRequest("\"$e\" is not inside any folder being backed up, so excluding it would change nothing.");
                }
            }
            $out[] = $e;
        }

        return $out;
    }

    /**
     * The folder inside a backup location where a job's data lands. Always relative, so it can never point outside the
     * location, and always with forward slashes so the same value works for a Windows or Linux agent.
     */
    public static function destination(string $path): ?string
    {
        $norm = trim(str_replace('\\', '/', $path), " \t/");
        if ($norm === '') {
            return null;
        }
        $bad = str_contains($norm, "\0") || strlen($norm) > 255 || str_starts_with(str_replace('\\', '/', trim($path)), '/')
            || preg_match('#^[A-Za-z]:#', $norm) || in_array('..', explode('/', $norm), true) || in_array('.', explode('/', $norm), true) || str_contains($norm, '//');
        if ($bad) {
            throw ApiException::badRequest('The destination folder must be relative to the location, such as web-01/daily, with no leading slash, drive letter or "..".');
        }

        return $norm;
    }

    /** Join a location root and a relative destination folder, for reading on the BMS server. */
    public static function join(string $root, ?string $destination): string
    {
        return $destination ? rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $destination) : $root;
    }

    /** True when $path, after resolving links and "..", is still inside $root. */
    public static function isInside(string $root, string $path): bool
    {
        $r = realpath($root);
        $p = realpath($path);
        if ($r === false || $p === false) {
            return false;
        }
        $r = rtrim($r, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $p.DIRECTORY_SEPARATOR === $r || str_starts_with($p.DIRECTORY_SEPARATOR, $r);
    }
}
