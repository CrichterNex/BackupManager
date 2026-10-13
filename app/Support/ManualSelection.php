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
    /** @return array{folder: string, day: string, position: int, text: string, fallback: string} */
    public static function generate(string $timezone): array
    {
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
            'folder' => 'Inbox', 'day' => $day->format('Y-m-d'), 'position' => $position,
            'text' => 'Open the '.self::ordinal($position).' email received on '.$day->format('l j F Y').' in the Inbox, counting from the earliest that day.',
            'fallback' => 'If that day has fewer emails, take the last one that day. If it has none, use the nearest earlier weekday that has email. Either way, say what you used in the details.',
        ];
    }

    public static function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';

        return $n.$suffix;
    }
}
