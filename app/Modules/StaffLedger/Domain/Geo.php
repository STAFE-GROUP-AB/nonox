<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/** Distances on the earth's surface, for the geofence around a location. */
final class Geo
{
    private const float EARTH_RADIUS_METERS = 6_371_000.0;

    /** Great-circle distance in metres (haversine). */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return (int) round(2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a))));
    }
}
