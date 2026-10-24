<?php

namespace App\Support;

/**
 * Live / lagging / offline for a technician's pin (spec 4.4).
 *
 * The phone pings every 4s while moving and every 15s once stationary, so a
 * parked technician must not turn amber just because their healthy 15s
 * rhythm is slower than a moving one. The last ping's speed tells us which
 * rhythm to expect; unknown speed is treated as moving (the stricter case).
 */
final class Freshness
{
    public const LIVE = 'LIVE';

    public const LAGGING = 'LAGGING';

    public const OFFLINE = 'OFFLINE';

    public static function of(?int $ageSeconds, ?float $lastSpeed): string
    {
        if ($ageSeconds === null) {
            return self::OFFLINE;
        }

        $t = config('smc.telemetry.freshness.'.self::rhythm($lastSpeed));

        return match (true) {
            $ageSeconds <= $t['live'] => self::LIVE,
            $ageSeconds <= $t['lagging'] => self::LAGGING,
            default => self::OFFLINE,
        };
    }

    /** 'moving' or 'stationary': which ping rhythm the phone should be on. */
    public static function rhythm(?float $lastSpeed): string
    {
        return $lastSpeed !== null && $lastSpeed <= config('smc.telemetry.moving_speed') ? 'stationary' : 'moving';
    }
}
