<?php

namespace App\Services\Routes;

use App\Support\Geo;

/**
 * Turns raw GPS pings into a route a person can read.
 *
 * Phone GPS near buildings produces two kinds of noise: single readings that
 * jump hundreds of metres away and straight back, and a 10-30 m wander while
 * someone stands still. Drawn as-is, a short walk becomes a tangle. This:
 *
 *  1. drops readings whose own reported accuracy is worse than max_accuracy;
 *  2. drops out-and-back spikes and readings implying an impossible speed;
 *  3. collapses stretches spent within stop_radius for stop_min_seconds into
 *     one stop (drawn as a single point with its duration).
 *
 * Only what's drawn changes; the raw pings stay in the database untouched.
 *
 * Points in and out: ['lat' => float, 'lng' => float, 't' => int (unix ms), 'speed' => ?float, 'accuracy' => ?float]
 */
class RouteCleaner
{
    public function __construct(
        private readonly float $maxAccuracy = 50,      // metres; worse readings are discarded
        private readonly float $maxSpeed = 60,         // m/s (216 km/h); faster jumps are impossible
        private readonly float $spikeMinJump = 60,     // metres; a spike leaves and returns at least this far
        private readonly float $stopRadius = 35,       // metres
        private readonly int $stopMinSeconds = 120,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('smc.routes');

        return new self($c['max_accuracy'], $c['max_speed'], $c['spike_min_jump'], $c['stop_radius'], $c['stop_min_seconds']);
    }

    /**
     * @param  list<array{lat: float, lng: float, t: int, speed: ?float, accuracy: ?float}>  $raw  oldest first
     * @return array{points: list<array>, stops: list<array>, stats: array}
     */
    public function clean(array $raw): array
    {
        $inaccurate = 0;
        $accurate = [];
        foreach ($raw as $p) {
            if ($p['accuracy'] !== null && $p['accuracy'] > $this->maxAccuracy) {
                $inaccurate++;

                continue;
            }
            $accurate[] = $p;
        }

        [$kept, $spikes] = $this->removeSpikes($accurate);
        [$points, $stops] = $this->collapseStops($kept);

        $distance = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $distance += $this->metres($points[$i - 1], $points[$i]);
        }

        $stopped = array_sum(array_column($stops, 'durationSeconds'));
        $span = $raw ? (int) round((end($raw)['t'] - $raw[0]['t']) / 1000) : 0;

        return [
            'points' => $points,
            'stops' => $stops,
            'stats' => [
                'rawPoints' => count($raw),
                'droppedInaccurate' => $inaccurate,
                'droppedSpikes' => $spikes,
                'keptPoints' => count($kept),
                'distanceMeters' => (int) round($distance),
                'stops' => count($stops),
                'stoppedSeconds' => $stopped,
                'movingSeconds' => max(0, $span - $stopped),
            ],
        ];
    }

    /** @return array{0: list<array>, 1: int} */
    private function removeSpikes(array $points): array
    {
        $kept = [];
        $dropped = 0;
        $n = count($points);

        for ($i = 0; $i < $n; $i++) {
            $p = $points[$i];
            $prev = $kept ? end($kept) : null;
            $next = $points[$i + 1] ?? null;

            if ($prev) {
                $seconds = max(0.001, ($p['t'] - $prev['t']) / 1000);
                $jump = $this->metres($prev, $p);

                // Impossible speed from the last good point.
                if ($jump / $seconds > $this->maxSpeed && $jump > $this->spikeMinJump) {
                    $dropped++;

                    continue;
                }

                // Out-and-back: far from both neighbours, while they are close to each other.
                if ($next && $jump > $this->spikeMinJump) {
                    $back = $this->metres($p, $next);
                    $across = $this->metres($prev, $next);
                    if ($back > $this->spikeMinJump && $across < 0.5 * min($jump, $back)) {
                        $dropped++;

                        continue;
                    }
                }
            }

            $kept[] = $p;
        }

        return [$kept, $dropped];
    }

    /**
     * Replace each long stay in one place with its centre, so wandering GPS
     * while standing still draws as a single stop instead of a scribble.
     *
     * @return array{0: list<array>, 1: list<array>}
     */
    private function collapseStops(array $points): array
    {
        $out = [];
        $stops = [];
        $n = count($points);
        $i = 0;

        while ($i < $n) {
            // Grow a window of points that all stay within the radius of its running centre.
            $j = $i;
            $sumLat = $points[$i]['lat'];
            $sumLng = $points[$i]['lng'];
            while ($j + 1 < $n) {
                $centre = ['lat' => $sumLat / ($j - $i + 1), 'lng' => $sumLng / ($j - $i + 1)];
                if ($this->metres($centre, $points[$j + 1]) > $this->stopRadius) {
                    break;
                }
                $j++;
                $sumLat += $points[$j]['lat'];
                $sumLng += $points[$j]['lng'];
            }

            $seconds = ($points[$j]['t'] - $points[$i]['t']) / 1000;

            if ($j > $i && $seconds >= $this->stopMinSeconds) {
                $count = $j - $i + 1;
                $centre = ['lat' => $sumLat / $count, 'lng' => $sumLng / $count];
                $last = $stops ? count($stops) - 1 : null;

                // GPS wander can briefly step outside the radius and split one stay in two.
                // Same place (within 1.5x the radius) and back within the minimum stop time: one stop.
                if ($last !== null
                    && ($points[$i]['t'] - $stops[$last]['to']) / 1000 <= $this->stopMinSeconds
                    && $this->metres($stops[$last], $centre) <= 1.5 * $this->stopRadius) {
                    $weight = $stops[$last]['count'];
                    $stops[$last]['lat'] = round(($stops[$last]['lat'] * $weight + $centre['lat'] * $count) / ($weight + $count), 7);
                    $stops[$last]['lng'] = round(($stops[$last]['lng'] * $weight + $centre['lng'] * $count) / ($weight + $count), 7);
                    $stops[$last]['to'] = $points[$j]['t'];
                    $stops[$last]['durationSeconds'] = (int) round(($stops[$last]['to'] - $stops[$last]['from']) / 1000);
                    $stops[$last]['count'] += $count;

                    // Drop the brief "move" drawn between the two halves and re-point the stop's ends.
                    $out = array_slice($out, 0, $stops[$last]['departure'] + 1);
                    $arrival = $stops[$last]['departure'] - 1;
                    foreach ([$arrival, $stops[$last]['departure']] as $k) {
                        $out[$k]['lat'] = $stops[$last]['lat'];
                        $out[$k]['lng'] = $stops[$last]['lng'];
                    }
                    $out[$stops[$last]['departure']]['t'] = $stops[$last]['to'];
                } else {
                    $lat = round($centre['lat'], 7);
                    $lng = round($centre['lng'], 7);
                    // Arrive at the stop and leave from it; the route passes through its centre.
                    $out[] = ['lat' => $lat, 'lng' => $lng, 't' => $points[$i]['t'], 'speed' => 0.0, 'stop' => count($stops)];
                    $out[] = ['lat' => $lat, 'lng' => $lng, 't' => $points[$j]['t'], 'speed' => 0.0, 'stop' => count($stops)];
                    $stops[] = [
                        'lat' => $lat,
                        'lng' => $lng,
                        'from' => $points[$i]['t'],
                        'to' => $points[$j]['t'],
                        'durationSeconds' => (int) round($seconds),
                        'count' => $count,
                        'departure' => count($out) - 1,
                    ];
                }
                $i = $j + 1;
            } else {
                $out[] = $points[$i] + ['stop' => null];
                $i++;
            }
        }

        // Internal bookkeeping only.
        $stops = array_map(fn ($s) => array_diff_key($s, ['count' => 1, 'departure' => 1]), $stops);

        return [$out, $stops];
    }

    private function metres(array $a, array $b): float
    {
        return Geo::distanceMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
    }
}
