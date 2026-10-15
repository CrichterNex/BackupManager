<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The item a tester has to open: "the 3rd email received on Wednesday 7 October", chosen at random when the test is created. The tester cannot
 * pick an easy one, and over time the tests cover different days and positions. Weekdays only, because weekends have too little mail to pick from.
 */
final class ManualSelection
{
    /** What each test samples: the noun, where it is, and what "that day" means for it. */
    private const KINDS = [
        'm365_mailbox' => ['noun' => 'email', 'where' => 'Inbox', 'verb' => 'received', 'place' => 'in the Inbox'],
        'onedrive_file' => ['noun' => 'file', 'where' => 'OneDrive', 'verb' => 'last changed', 'place' => 'in this OneDrive, in any folder'],
        'teams_channel' => ['noun' => 'message', 'where' => 'General channel', 'verb' => 'posted', 'place' => 'in this team\'s General channel'],
        'sharepoint_site' => ['noun' => 'document', 'where' => 'Document libraries', 'verb' => 'last changed', 'place' => 'in this site\'s document libraries'],
    ];

    /** @return array{folder: string, day: string, position: int, text: string, fallback: string} */
    public static function generate(string $timezone, string $testType = 'm365_mailbox'): array
    {
        $k = self::KINDS[$testType] ?? self::KINDS['m365_mailbox'];
        $tz = new DateTimeZone($timezone);
        $today = new DateTimeImmutable('today', $tz);
        $window = max(2, (int) config('bms.manual_pick_window_days'));
        $days = [];
        for ($i = 1; $i <= $window; $i++) {
            $d = $today->modify("-$i day");
            if ((int) $d->format('N') <= 5) {
                $days[] = $d;
            }
        }
        $day = $days[random_int(0, count($days) - 1)];
        $position = random_int(1, max(1, (int) config('bms.manual_pick_max_position')));

        return [
            'folder' => $k['where'], 'day' => $day->format('Y-m-d'), 'position' => $position,
            'text' => 'Open the '.self::ordinal($position)." {$k['noun']} {$k['verb']} on ".$day->format('l j F Y')." {$k['place']}, counting from the earliest that day.",
            'fallback' => "If that day has fewer {$k['noun']}s, take the last one that day. If it has none, use the nearest earlier weekday that has one. Either way, say what you used in the details.",
        ];
    }

    public static function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';

        return $n.$suffix;
    }
}
