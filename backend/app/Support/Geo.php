<?php

namespace App\Support;

final class Geo
{
    /**
     * Ray-casting point-in-polygon on lat/lng. Accurate enough for city-scale
     * zones; polygons are not expected to cross the antimeridian.
     *
     * @param  array<int, array{lat: float|int, lng: float|int}>  $polygon
     */
    public static function contains(array $polygon, float $lat, float $lng): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$yi, $xi] = [(float) $polygon[$i]['lat'], (float) $polygon[$i]['lng']];
            [$yj, $xj] = [(float) $polygon[$j]['lat'], (float) $polygon[$j]['lng']];

            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** Great-circle distance in metres. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
