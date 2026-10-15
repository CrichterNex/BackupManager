<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\Rule;

/**
 * Checks what a tester recorded about the item they restored. BMS cannot see the mailbox, but it can check the record is consistent: the item
 * is the one it asked for (or the tester said why not), it is not later than the recovery point it was restored from, and that recovery point
 * is recent. Screenshots are the proof a person can look at. These checks are what make a made-up record harder to get past.
 */
final class ManualFindings
{
    /** Laravel validation rules for the fields a tester may save. */
    public static function rules(string $testType): array
    {
        $rules = [];
        foreach (ManualChecklists::fields($testType) as $f) {
            $rules[$f['id']] = ['sometimes', 'nullable'] + match ($f['type']) {
                'date' => [2 => 'date_format:Y-m-d'],
                'time' => [2 => 'date_format:H:i'],
                'number' => [2 => 'integer', 3 => 'min:0', 4 => 'max:100000000'],
                'select' => [2 => Rule::in(array_keys($f['options']))],
                default => [2 => 'string', 3 => 'max:500'],
            };
        }

        return $rules;
    }

    /**
     * Throws a message the tester can act on if the findings cannot support a pass.
     *
     * @param  array<string, mixed>  $findings
     * @param  array<string, mixed>|null  $selection  the item the test asked for
     * @param  int  $allowedHours  how old the recovery point may be
     */
    public static function assertPassable(array $findings, ?array $selection, string $timezone, int $allowedHours, ?DateTimeImmutable $now = null, string $testType = 'm365_mailbox'): void
    {
        $noun = ['onedrive_file' => 'file', 'sharepoint_site' => 'document', 'teams_channel' => 'message'][$testType] ?? 'email';
        $fields = ManualChecklists::fields($testType);
        $missing = [];
        foreach ($fields as $f) {
            if ($f['required'] && (! isset($findings[$f['id']]) || $findings[$f['id']] === '')) {
                $missing[] = $f['label'];
            }
        }
        if ($missing) {
            throw ApiException::badRequest('Fill in the details before passing the test: '.implode('; ', $missing).'.');
        }
        $tz = new DateTimeZone($timezone);
        $now ??= new DateTimeImmutable('now', $tz);
        $at = fn (string $d, string $t) => DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$d $t", $tz);
        $point = $at($findings['recovery_point_date'], $findings['recovery_point_time']);
        $item = $at($findings['item_day'], $findings['item_time']);
        if (! $point || ! $item) {
            throw ApiException::badRequest('A date or time in the details is not valid.');
        }
        // Was it the item that was asked for? Said first, because a wrong item also tends to look wrong against the dates, and the item is the real problem.
        if ($selection) {
            $sameDay = $findings['item_day'] === $selection['day'];
            $samePosition = (int) $findings['position_used'] === (int) $selection['position'];
            if ($findings['item_day'] > $selection['day']) {
                throw ApiException::badRequest("You were asked for an item from {$selection['day']}. An item from a later day is not allowed.");
            }
            if ($sameDay && (int) $findings['position_used'] > (int) $selection['position']) {
                throw ApiException::badRequest("You were asked for $noun number ".$selection['position'].' that day. A later one is not allowed.');
            }
            if (! ($sameDay && $samePosition) && trim((string) ($findings['fallback_note'] ?? '')) === '') {
                throw ApiException::badRequest('You were asked to open: '.$selection['text'].' If you used a different item, say why and what you used in the details.');
            }
        }
        if ($point->getTimestamp() > $now->getTimestamp() + 300) {
            throw ApiException::badRequest('The recovery point is in the future. Check its date and time.');
        }
        if ($item->getTimestamp() > $point->getTimestamp()) {
            throw ApiException::badRequest('The item is dated after the recovery point, so it cannot have been restored from it. Check the dates, or use a later recovery point.');
        }
        $age = (int) floor(($now->getTimestamp() - $point->getTimestamp()) / 3600);
        if ($age > $allowedHours) {
            throw ApiException::badRequest("The recovery point you used is $age hours old, more than the $allowedHours hours allowed. Use the newest one. If there is no newer one, the backup is not keeping up: complete the test as failed.");
        }
        if ($findings['attachment'] === 'fails') {
            throw ApiException::badRequest($noun === 'email' ? 'An attachment that does not open is a failed restore. Complete the test as failed.' : "A $noun that does not open, or is damaged, is a failed restore. Complete the test as failed.");
        }
        if (($findings['permissions'] ?? null) === 'lost') {
            throw ApiException::badRequest('Permissions that were not restored are a failed restore. Complete the test as failed.');
        }
    }
}
