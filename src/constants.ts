export const BACKUP_METHODS = [
  'rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy',
  'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup',
] as const;

export const TEST_TYPES = [
  'synthetic_file', 'random_restore', 'full_recovery', 'database_integrity',
  'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions',
] as const;

/** Methods whose backup copy sits on a path the BMS host can read (servers.backup_location_path). */
export const FILESYSTEM_METHODS = ['rsync', 'windows_manual_copy', 'linux_manual_copy'] as const;

/** Test types the engine can actually run today. Everything else is recorded as skipped, never as a pass. */
export const ENGINE_TEST_TYPES = ['random_restore'] as const;

export const REPORT_TYPES = ['compliance', 'sla', 'security', 'dr_readiness'] as const;
export type ReportType = (typeof REPORT_TYPES)[number];
