<?php

/**
 * Geocoding functions for location search and reverse geocoding.
 * Uses OpenStreetMap Nominatim API.
 */

declare(strict_types=1);

/**
 * Geocode an address to coordinates.
 *
 * @param string $address Address to geocode
 * @return array{success: bool, lat?: string, lon?: string, display_name?: string, error?: string}
 */
function geocode_address(string $address): array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1,
    ]);

    $opts = ['http' => ['header' => 'User-Agent: Sun-Twilight-Calendar/9.0']];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        return ['success' => false, 'error' => 'Geocoding unavailable'];
    }

    $data = json_decode($response, true);
    if (empty($data)) {
        return ['success' => false, 'error' => 'Location not found'];
    }

    return [
        'success' => true,
        'lat' => $data[0]['lat'],
        'lon' => $data[0]['lon'],
        'display_name' => $data[0]['display_name'],
    ];
}

/**
 * Reverse geocode coordinates to a location name.
 *
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @return array{success: bool, name?: string}
 */
function reverse_geocode(float $lat, float $lon): array
{
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $lat,
        'lon' => $lon,
        'format' => 'json',
        'zoom' => 10,
    ]);

    $opts = ['http' => ['header' => 'User-Agent: Sun-Twilight-Calendar/9.0']];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        return ['success' => false];
    }

    $data = json_decode($response, true);
    if (empty($data)) {
        return ['success' => false];
    }

    $address = $data['address'] ?? [];
    $name_parts = [];

    if (!empty($address['city'])) {
        $name_parts[] = $address['city'];
    } elseif (!empty($address['town'])) {
        $name_parts[] = $address['town'];
    } elseif (!empty($address['village'])) {
        $name_parts[] = $address['village'];
    } elseif (!empty($address['municipality'])) {
        $name_parts[] = $address['municipality'];
    }

    if (!empty($address['state'])) {
        $name_parts[] = $address['state'];
    } elseif (!empty($address['province'])) {
        $name_parts[] = $address['province'];
    }

    return [
        'success' => true,
        'name' => !empty($name_parts) ? implode(', ', $name_parts) : ($data['display_name'] ?? ''),
    ];
}

/**
 * Handle geocode API request.
 */
function handle_geocode_request(string $address): void
{
    header('Content-Type: application/json');
    echo json_encode(geocode_address($address));
}

/**
 * Handle reverse geocode API request.
 */
function handle_reverse_geocode_request(float $lat, float $lon): void
{
    header('Content-Type: application/json');
    echo json_encode(reverse_geocode($lat, $lon));
}
