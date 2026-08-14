<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/StreakCalculator.php
//  Turns a list of distinct "Y-m-d" activity dates (DESC order, as
//  returned by SegmentRepository::auditDatesForUser) into a current
//  streak count — consecutive days with at least one segment audit.
//
//  Duolingo-style grace: a streak audited yesterday but not yet
//  today still counts as "alive" (today isn't over), so it's not
//  zeroed out until a full day is missed with no activity at all.
// ═══════════════════════════════════════════════════════════════

class StreakCalculator
{
    /**
     * @param list<string> $dates Distinct "Y-m-d" dates, most recent first.
     */
    public static function current(array $dates): int
    {
        if (empty($dates)) {
            return 0;
        }

        $tz    = new DateTimeZone('Asia/Kolkata');
        $today = new DateTime('now', $tz);
        $set   = array_flip($dates); // O(1) lookup

        $todayStr     = $today->format('Y-m-d');
        $yesterdayStr = (clone $today)->modify('-1 day')->format('Y-m-d');

        if (isset($set[$todayStr])) {
            $cursor = $today;
        } elseif (isset($set[$yesterdayStr])) {
            $cursor = (clone $today)->modify('-1 day');
        } else {
            return 0; // missed a full day — streak is broken
        }

        $streak = 0;
        while (isset($set[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor->modify('-1 day');
        }

        return $streak;
    }
}
