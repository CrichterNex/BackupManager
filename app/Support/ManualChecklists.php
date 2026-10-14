<?php

namespace App\Support;

/**
 * The steps a person follows to perform each test by hand. A step can ask for evidence: a note, the fingerprint (hash) of an original and
 * its restored copy, and screenshots. A step with required evidence cannot be passed without it, so a pass always comes with the proof the
 * checklist asks for.
 *
 * Each step: id, text, evidence ('none', 'note' or 'hash'), required (is that evidence needed to pass), and optionally files (how many
 * screenshots the step needs before the test can pass) and reference (the step where the restored fingerprint is compared with one BMS holds).
 */
final class ManualChecklists
{
    private const ALL = [
        // The Microsoft 365 test is: restore a randomly chosen item and confirm it is complete and opens. There is no original to compare with and nobody
        // should read a real person's mail to find one. The item's details go in structured fields that BMS checks (see fields()), with screenshots as proof.
        'm365_mailbox' => [
            'target' => 'The mailbox to test',
            'steps' => [
                ['id' => 'find', 'text' => 'Open the backup console and find the mailbox shown above.', 'evidence' => 'none', 'required' => false],
                ['id' => 'point', 'text' => 'Choose the newest recovery point and enter its date and time in the details below.', 'evidence' => 'none', 'required' => false],
                ['id' => 'locate', 'text' => 'Find the item described under Which item. If there is no such item, follow the fallback rule shown there and say so in the details.', 'evidence' => 'none', 'required' => false],
                ['id' => 'restore', 'text' => 'Restore that item to a different location, never over the live mailbox. Screenshot the restore completing.', 'evidence' => 'none', 'required' => false, 'files' => 1],
                ['id' => 'open', 'text' => 'Open the restored item. Check it is complete and that any attachment opens. Screenshot it showing its date, then fill in the details below.', 'evidence' => 'none', 'required' => false, 'files' => 1],
                ['id' => 'errors', 'text' => 'Check the restore activity shows no errors or warnings.', 'evidence' => 'none', 'required' => false],
                ['id' => 'cleanup', 'text' => 'Remove the restored item from the alternate location.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'random_restore' => [
            'target' => 'The folder or file area to sample',
            'steps' => [
                ['id' => 'pick', 'text' => 'Choose a file at random from the backup. Note its full path.', 'evidence' => 'note', 'required' => true],
                ['id' => 'restore', 'text' => 'Restore that file to a scratch location, not over the original. Screenshot the restore.', 'evidence' => 'none', 'required' => false, 'files' => 1],
                ['id' => 'compare', 'text' => 'Record the SHA-256 of the original and of the restored copy.', 'evidence' => 'hash', 'required' => true],
                ['id' => 'open', 'text' => 'Open the restored file and confirm it is usable.', 'evidence' => 'none', 'required' => false],
                ['id' => 'cleanup', 'text' => 'Delete the restored copy.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'synthetic_file' => [
            'target' => 'The location or folder to test',
            'steps' => [
                ['id' => 'create', 'text' => 'Create a small test file with known contents in the protected area. Note its name and size.', 'evidence' => 'note', 'required' => true],
                ['id' => 'hash', 'text' => 'Record its SHA-256 before it is backed up.', 'evidence' => 'hash', 'required' => false],
                ['id' => 'backup', 'text' => 'Run or wait for a backup that includes it. Note the backup time.', 'evidence' => 'note', 'required' => true],
                ['id' => 'restore', 'text' => 'Restore the file to a scratch location. Screenshot the restore.', 'evidence' => 'none', 'required' => false, 'files' => 1],
                ['id' => 'compare', 'text' => 'Record the SHA-256 of the restored file next to the original.', 'evidence' => 'hash', 'required' => true],
                ['id' => 'cleanup', 'text' => 'Delete the test file and the restored copy.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'full_recovery' => [
            'target' => 'What to recover in full',
            'steps' => [
                ['id' => 'scope', 'text' => 'Confirm what is being recovered and the recovery point used. Note both.', 'evidence' => 'note', 'required' => true],
                ['id' => 'restore', 'text' => 'Restore everything to a scratch location or isolated machine. Note how long it took. Screenshot the result.', 'evidence' => 'note', 'required' => true, 'files' => 1],
                ['id' => 'counts', 'text' => 'Compare the number of files and the total size with the source. Note both.', 'evidence' => 'note', 'required' => true],
                ['id' => 'spot', 'text' => 'Spot check a few files: record the SHA-256 of the original and the restored copy.', 'evidence' => 'hash', 'required' => true],
                ['id' => 'works', 'text' => 'Start the key application or service from the restored data, and confirm it works.', 'evidence' => 'none', 'required' => false],
                ['id' => 'cleanup', 'text' => 'Remove the restored data.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'database_integrity' => [
            'target' => 'The database to test',
            'steps' => [
                ['id' => 'restore', 'text' => 'Restore the database backup to a scratch instance, not the live one. Note the recovery point.', 'evidence' => 'note', 'required' => true],
                ['id' => 'check', 'text' => 'Run the engine\'s integrity check (for example DBCC CHECKDB, mysqlcheck or pg_amcheck). Note the command and its result. Screenshot the output.', 'evidence' => 'note', 'required' => true, 'files' => 1],
                ['id' => 'counts', 'text' => 'Compare row counts of a few important tables with the live database.', 'evidence' => 'note', 'required' => false],
                ['id' => 'cleanup', 'text' => 'Drop the scratch instance.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'incremental_chain' => [
            'target' => 'The backup chain to check',
            'steps' => [
                ['id' => 'list', 'text' => 'List the chain from the last full backup to the newest increment. Note the first and last points and how many there are. Screenshot the list.', 'evidence' => 'note', 'required' => true, 'files' => 1],
                ['id' => 'gaps', 'text' => 'Confirm there are no gaps or failed points in the chain.', 'evidence' => 'none', 'required' => false],
                ['id' => 'restore', 'text' => 'Restore the newest point to a scratch location.', 'evidence' => 'none', 'required' => false],
                ['id' => 'verify', 'text' => 'Verify a restored file against its original: record the SHA-256 of each.', 'evidence' => 'hash', 'required' => false],
                ['id' => 'cleanup', 'text' => 'Remove the restored data.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'recovery_point' => [
            'target' => 'The backup to check',
            'steps' => [
                ['id' => 'newest', 'text' => 'Open the backup console and find the newest successful recovery point. Note its date and time. Screenshot it.', 'evidence' => 'note', 'required' => true, 'files' => 1],
                ['id' => 'rpo', 'text' => 'Confirm it is within the recovery point objective for this backup.', 'evidence' => 'none', 'required' => false],
                ['id' => 'retention', 'text' => 'Confirm older points exist as far back as the retention policy says. Note the oldest.', 'evidence' => 'note', 'required' => false],
            ],
        ],
        'platform_validation' => [
            'target' => 'The Acronis device to check',
            'steps' => [
                ['id' => 'open', 'text' => 'Open the Acronis console and find the validation results for the device shown above.', 'evidence' => 'none', 'required' => false],
                ['id' => 'latest', 'text' => 'Note the newest validation run: when it started, whether it finished, and its result. Screenshot it.', 'evidence' => 'note', 'required' => true, 'files' => 1],
                ['id' => 'errors', 'text' => 'Check no backup in that run errored. If any did, note the error and whether it is a credential problem or damage.', 'evidence' => 'note', 'required' => false],
                ['id' => 'plan', 'text' => 'Confirm the validation plan is applied to the device and scheduled to run again.', 'evidence' => 'none', 'required' => false],
            ],
        ],
        'file_permissions' => [
            'target' => 'The folder to sample',
            'steps' => [
                ['id' => 'pick', 'text' => 'Choose a few files and note their paths.', 'evidence' => 'note', 'required' => true],
                ['id' => 'source', 'text' => 'Record the permissions and owner of each on the source.', 'evidence' => 'note', 'required' => true],
                ['id' => 'restore', 'text' => 'Restore them to a scratch location.', 'evidence' => 'none', 'required' => false],
                ['id' => 'restored', 'text' => 'Record the permissions and owner of each restored file. Screenshot them.', 'evidence' => 'note', 'required' => true, 'files' => 1],
                ['id' => 'compare', 'text' => 'Compare the two. Any difference is a failure.', 'evidence' => 'none', 'required' => false],
                ['id' => 'cleanup', 'text' => 'Delete the restored copies.', 'evidence' => 'none', 'required' => false],
            ],
        ],
    ];

    /**
     * The details the tester records as fields rather than free text, so BMS can check them (dates in order, the item matches what was asked
     * for, the recovery point is recent). Only the Microsoft 365 test has them.
     */
    private const FIELDS = [
        'm365_mailbox' => [
            ['id' => 'recovery_point_date', 'label' => 'Recovery point: date', 'type' => 'date', 'required' => true],
            ['id' => 'recovery_point_time', 'label' => 'Recovery point: time', 'type' => 'time', 'required' => true],
            ['id' => 'item_day', 'label' => 'Item: date received', 'type' => 'date', 'required' => true],
            ['id' => 'item_time', 'label' => 'Item: time received', 'type' => 'time', 'required' => true],
            ['id' => 'position_used', 'label' => 'Which email that day it was (1 = earliest)', 'type' => 'number', 'required' => true],
            ['id' => 'item_subject', 'label' => 'Item: subject', 'type' => 'text', 'required' => true],
            ['id' => 'item_size_kb', 'label' => 'Item: size in KB', 'type' => 'number', 'required' => true],
            ['id' => 'attachment', 'label' => 'Attachment', 'type' => 'select', 'required' => true, 'options' => ['none' => 'The item has no attachment', 'opens' => 'The attachment opens', 'fails' => 'The attachment does not open']],
            ['id' => 'fallback_note', 'label' => 'If you could not use the item asked for, say why and what you used', 'type' => 'text', 'required' => false],
        ],
    ];

    /**
     * @param  bool  $hasReference  the tenant has reference items: known files with a fingerprint BMS holds, restored and compared as an extra step
     * @return array{target: string, steps: list<array{id: string, text: string, evidence: string, required: bool, files?: int, reference?: bool}>}
     */
    public static function for(string $testType, bool $hasReference = false): array
    {
        $tpl = self::ALL[$testType] ?? ['target' => 'What to test', 'steps' => []];
        if ($testType === 'm365_mailbox' && $hasReference) {
            // An extra, optional-by-design check for tenants that keep reference items: BMS holds the expected fingerprint, so the tester enters only the restored one.
            $extra = ['id' => 'fingerprint', 'text' => 'Also restore the reference item shown above and enter the fingerprint of the restored copy. BMS compares it with the expected one, so you never need the mailbox owner or the original.', 'evidence' => 'hash', 'required' => true, 'reference' => true];
            array_splice($tpl['steps'], count($tpl['steps']) - 2, 0, [$extra]);
        }

        return $tpl;
    }

    /** The ids of the steps in a checklist. */
    public static function stepIds(string $testType, bool $hasReference = false): array
    {
        return array_column(self::for($testType, $hasReference)['steps'], 'id');
    }

    /** @return list<array{id: string, label: string, type: string, required: bool, options?: array<string, string>}> */
    public static function fields(string $testType): array
    {
        return self::FIELDS[$testType] ?? [];
    }
}
