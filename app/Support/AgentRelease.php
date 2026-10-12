<?php

namespace App\Support;

/**
 * The agent file this BMS serves to servers (agent/bms_agent.py). Its version and SHA-256 are read from the file
 * itself, so there is nothing to keep in step: replace the file and the Agents page shows servers running an older one.
 */
final class AgentRelease
{
    public const URL_PATH = '/agent/bms_agent.py';

    public static function path(): string
    {
        return base_path('agent/bms_agent.py');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function version(): ?string
    {
        if (! self::exists()) {
            return null;
        }

        return preg_match('/^VERSION\s*=\s*"([^"]+)"/m', (string) file_get_contents(self::path()), $m) ? $m[1] : null;
    }

    public static function sha256(): ?string
    {
        return self::exists() ? hash_file('sha256', self::path()) : null;
    }

    public static function isOutdated(?string $agentVersion): bool
    {
        $current = self::version();

        return $agentVersion !== null && $current !== null && version_compare($agentVersion, $current, '<');
    }

    /** What the Agents page shows next to the download link. */
    public static function summary(): array
    {
        return ['version' => self::version(), 'sha256' => self::sha256(), 'path' => self::URL_PATH, 'available' => self::exists()];
    }

    /**
     * Copy-and-run install commands for a freshly enrolled agent. The secret appears here and nowhere else: it is read
     * from an environment variable, so it does not show in the process list. $locationKeys are this company's backup
     * location keys, so the admin only has to fill in the folder on that server.
     *
     * @param  list<string>  $locationKeys
     * @return array{linux: string, windows: string}
     */
    public static function installCommands(string $baseUrl, string $secret, array $locationKeys): array
    {
        $insecure = str_starts_with($baseUrl, 'http://') ? ' --allow-insecure-http' : '';
        $keys = $locationKeys ?: ['nas-main'];
        $linuxLocs = implode(' ', array_map(fn ($k) => "--location $k=/path/to/backups", $keys));
        $winLocs = implode(' ', array_map(fn ($k) => "--location $k=D:\\Backups", $keys));
        $url = $baseUrl.self::URL_PATH;

        return [
            'linux' => "curl -fsSL $url -o bms_agent.py\nsudo BMS_AGENT_TOKEN='$secret' python3 bms_agent.py --install --url $baseUrl $linuxLocs$insecure",
            'windows' => "Invoke-WebRequest $url -OutFile bms_agent.py\n\$env:BMS_AGENT_TOKEN='$secret'; python bms_agent.py --install --url $baseUrl $winLocs$insecure",
        ];
    }
}
