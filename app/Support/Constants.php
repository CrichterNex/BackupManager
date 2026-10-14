<?php

namespace App\Support;

final class Constants
{
    public const BACKUP_METHODS = [
        'rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy', 'robocopy', 'manual_copy',
        'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup',
    ];

    /**
     * Methods where BMS-managed copies are written to a backup location (a destination BMS knows about). Restore
     * tests read from that location. Every other method belongs to a platform that has its own locations.
     */
    public const LOCATION_METHODS = ['rsync', 'windows_manual_copy', 'linux_manual_copy', 'robocopy', 'manual_copy'];

    public const TEST_TYPES = [
        'synthetic_file', 'random_restore', 'full_recovery', 'database_integrity',
        'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions', 'platform_validation',
    ];

    /**
     * What each test does, in plain words. Written to be true of the code: a test that is not built says so and what it would need,
     * because it is recorded as skipped and never as a pass. "Runs" says where: on the agent, on the server's own checks, or both.
     */
    public const TEST_HELP = [
        'random_restore' => [
            'title' => 'Random file restore',
            'summary' => 'Restores one random file from the backup and checks it is intact.',
            'details' => 'Picks a file at random from the job\'s backup folder, copies it to a scratch area and compares a SHA-256 fingerprint of the original and the copy. If the job says how its folders are laid out, it also compares the backup with the file on the source server, unless the source changed since the backup. A pass shows the backup is readable and copies back unchanged. It does not prove the whole backup can be restored.',
            'runs' => 'On the server\'s agent, or from BMS itself when BMS can read the location.',
        ],
        'synthetic_file' => [
            'title' => 'Location write and read-back',
            'summary' => 'Writes a known test file to the backup location, reads it back and checks it.',
            'details' => 'Creates a small hidden folder in the job\'s backup folder, writes a 256 KB file of random data, reads it back and compares fingerprints, reports the free space, then deletes what it made. A pass shows the location is reachable, writable and returns data unchanged: the share, its permissions and its disk. It does not test your backup software. If it cannot write, the location is read-only or the account has no write access.',
            'runs' => 'On the server\'s agent only.',
        ],
        'full_recovery' => [
            'title' => 'Full read-back',
            'summary' => 'Reads every file in the backup from start to end.',
            'details' => 'Walks the job\'s whole backup folder and reads every file completely and reports how many files and how much data were read and any file that could not be read. A pass shows every file can be read back in full, which catches damaged disks, truncated files and permission problems. It does not restore anything or show the server would boot, and it only reads the data, so it needs time and puts load on the storage: schedule it weekly or monthly, not daily.',
            'runs' => 'On the server\'s agent only.',
        ],
        'file_permissions' => [
            'title' => 'File permissions',
            'summary' => 'Compares permissions on sampled backup files with their source files.',
            'details' => 'Picks up to ten files from the backup whose source file can be found, and compares permissions: mode, owner and group on Linux, and the read-only, hidden and system flags on Windows. Windows access control lists are not compared, and the built-in robocopy does not copy them. If the job does not say how its folders are laid out, or no sampled file has a source this agent may read, the test is skipped rather than passed.',
            'runs' => 'On the server\'s agent only.',
        ],
        'recovery_point' => [
            'title' => 'Recovery point',
            'summary' => 'Checks a recent successful backup exists.',
            'details' => 'Looks at the latest successful backup recorded for the job and fails if it is older than expected. With a schedule on the job, the allowance is one and a half times the interval between runs plus two hours; without one it is 36 hours (BMS_RECOVERY_POINT_MAX_AGE_HOURS). It works for every backup method, including platforms such as Acronis. It confirms a backup ran and finished recently, not that its data is good: combine it with a restore test.',
            'runs' => 'In BMS, using the recorded backup results. No agent needed.',
        ],
        'platform_validation' => [
            'title' => 'Acronis validation',
            'summary' => 'Confirms Acronis\'s own validation of the backup passed.',
            'details' => 'Acronis can validate a backup itself, checking the backup archive is intact. BMS reads Acronis\'s records of those validation runs and judges each device by its newest run: it passes when every backup validated in that run is ok. A run that errors because Acronis could not open the backup (a wrong credential or archive password) is reported as access denied, not as damage, because the backup has not been confirmed either way. A device with no validation plan is skipped, never passed, and one whose validation stopped running fails. Acronis does the validating, not BMS: BMS only reads the result, so a validation plan has to be set up in Acronis. This checks the archive is intact, not that a restore works or a server would boot, so keep a restore test too. It applies to Acronis backups only.',
            'runs' => 'In Acronis (the validation itself). BMS only reads the result, no agent needed.',
        ],
        'database_integrity' => [
            'title' => 'Database integrity',
            'summary' => 'Restores a database backup to a scratch instance and runs its integrity checks.',
            'details' => 'BMS cannot do this itself: it would need to know each job\'s database engine and backup format. A person does it by hand from the Tests page, following a checklist and attaching evidence. If it is queued for BMS to run, it is recorded as skipped, never as a pass.',
            'runs' => 'By a person, by hand.',
        ],
        'incremental_chain' => [
            'title' => 'Incremental chain',
            'summary' => 'Checks every increment since the last full backup is present and readable.',
            'details' => 'BMS cannot do this itself: plain copies (rsync, robocopy) have no chain, and backup platforms do not report theirs to BMS. A person does it by hand from the Tests page, following a checklist and attaching evidence. If it is queued for BMS to run, it is recorded as skipped, never as a pass.',
            'runs' => 'By a person, by hand.',
        ],
        'm365_mailbox' => [
            'title' => 'Microsoft 365 mailbox',
            'summary' => 'Restores a sample mailbox item from a Microsoft 365 backup and checks it.',
            'details' => 'BMS has no way to restore from a Microsoft 365 backup itself. A person does it by hand from the Tests page: BMS picks a mailbox from the tenant\'s list, the tester follows a checklist in the backup console and attaches the fingerprints, notes and screenshots as evidence. If it is queued for BMS to run, it is recorded as skipped, never as a pass.',
            'runs' => 'By a person, by hand.',
        ],
    ];

    /** Test types an agent can run on the server that holds the backup. */
    public const AGENT_TEST_TYPES = ['random_restore', 'synthetic_file', 'full_recovery', 'file_permissions'];

    /** Same set: methods whose backup copy sits in a location a restore test can read. */
    public const FILESYSTEM_METHODS = self::LOCATION_METHODS;

    /** Test types the engine can actually run today. Everything else is recorded as skipped, never as a pass. */
    public const ENGINE_TEST_TYPES = ['random_restore', 'synthetic_file', 'full_recovery', 'file_permissions', 'recovery_point', 'platform_validation'];

    public const REPORT_TYPES = ['compliance', 'sla', 'security', 'dr_readiness'];

    public const JOB_STATUSES = ['scheduled', 'running', 'success', 'warning', 'failed'];
}
