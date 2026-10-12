<?php

namespace App\Support;

final class Constants
{
    public const BACKUP_METHODS = [
        'rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy',
        'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup',
    ];

    public const TEST_TYPES = [
        'synthetic_file', 'random_restore', 'full_recovery', 'database_integrity',
        'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions',
    ];

    /** Methods whose backup copy sits on a path the BMS host can read (servers.backup_location_path). */
    public const FILESYSTEM_METHODS = ['rsync', 'windows_manual_copy', 'linux_manual_copy'];

    /** Test types the engine can actually run today. Everything else is recorded as skipped, never as a pass. */
    public const ENGINE_TEST_TYPES = ['random_restore'];

    public const REPORT_TYPES = ['compliance', 'sla', 'security', 'dr_readiness'];

    public const JOB_STATUSES = ['scheduled', 'running', 'success', 'warning', 'failed'];
}
