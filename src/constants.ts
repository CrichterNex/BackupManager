export const BACKUP_METHODS = [
  'rsync', 'acronis', 'veeam', 'commvault', 'windows_manual_copy', 'linux_manual_copy',
  'acronis_m365', 'microsoft_native_365', 'aws_backup', 'azure_backup',
] as const;

export const TEST_TYPES = [
  'synthetic_file', 'random_restore', 'full_recovery', 'database_integrity',
  'incremental_chain', 'm365_mailbox', 'recovery_point', 'file_permissions',
] as const;
