<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleMapsService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $apiKey = null
    ) {
    }

    /**
     * @return array<int,array{name:string,address:string,lat:float,lng:float,rating:float|null,types:array<int,string>}>
     */
    public function findNearby(string $query, float $lat, float $lng, int $radius = 5000): array
    {
        return $this->findNearbyWithMeta($query, $lat, $lng, $radius)['places'];
    }

    /**
     * @return array{
     *   source: string,
     *   places: array<int,array{name:string,address:string,lat:float,lng:float,rating:float|null,types:array<int,string>}>,
     *   message: string
     * }
     */
    public function findNearbyWithMeta(string $query, float $lat, float $lng, int $radius = 5000): array
    {
        if ($this->apiKey) {
            try {
                $resp = $this->httpClient->request('GET', 'https://maps.googleapis.com/maps/api/place/nearbysearch/json', [
                    'query' => [
                        'key' => $this->apiKey,
                        'location' => sprintf('%F,%F', $lat, $lng),
                        'radius' => $radius,
                        'keyword' => $query,
                        'language' => 'fr',
                    ],
                    'timeout' => 12,
                ]);

                $data = $resp->toArray(false);
                $status = (string) ($data['status'] ?? 'UNKNOWN');
                if (in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                    return [
                        'source' => 'google',
                        'places' => $this->mapGoogleResults($data['results'] ?? []),
                        'message' => $status === 'ZERO_RESULTS' ? 'No results from Google Places.' : 'Results from Google Places.',
                    ];
                }
            } catch (\Throwable) {
                // Fallback to OSM below.
            }
        }

        try {
            $places = $this->findNearbyFromOsm($query, $lat, $lng, $radius);
            return [
                'source' => 'osm',
                'places' => $places,
                'message' => 'Fallback OpenStreetMap used.',
            ];
        } catch (\Throwable $e) {
            return [
                'source' => 'none',
                'places' => [],
                'message' => 'Maps unavailable: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array{name:string,address:string,lat:float,lng:float,rating:float|null,types:array<int,string>}>
     */
    private function mapGoogleResults(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[] = [
                'name' => (string) ($r['name'] ?? ''),
                'address' => (string) ($r['vicinity'] ?? ''),
                'lat' => (float) ($r['geometry']['location']['lat'] ?? 0.0),
                'lng' => (float) ($r['geometry']['location']['lng'] ?? 0.0),
                'rating' => isset($r['rating']) ? (float) $r['rating'] : null,
                'types' => is_array($r['types'] ?? null) ? $r['types'] : [],
            ];
        }

        return array_slice($out, 0, 8);
    }

    /**
     * @return array<int,array{name:string,address:string,lat:float,lng:float,rating:float|null,types:array<int,string>}>
     */
    private function findNearbyFromOsm(string $query, float $lat, float $lng, int $radius): array
    {
        $profile = $this->buildOsmProfile($query);
        $q = $this->buildOsmOverpassQuery($profile['clauses'], $lat, $lng, $radius);

        $resp = $this->httpClient->request('POST', 'https://overpass-api.de/api/interpreter', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => ['data' => $q],
            'timeout' => 18,
        ]);
        $data = $resp->toArray(false);
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];

        $keywords = $profile['keywords'];
        $mapped = [];
        foreach ($elements as $el) {
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $name = (string) ($tags['name'] ?? $tags['brand'] ?? '');
            $amenity = (string) ($tags['amenity'] ?? '');
            $shop = (string) ($tags['shop'] ?? '');
            $office = (string) ($tags['office'] ?? '');
            $craft = (string) ($tags['craft'] ?? '');
            $description = (string) ($tags['description'] ?? '');
            $text = mb_strtolower(trim($name . ' ' . $amenity . ' ' . $shop . ' ' . $office . ' ' . $craft . ' ' . $description));

            // Keep only places relevant to the case profile.
            if (!$this->matchesKeywords($text, $keywords)) {
                continue;
            }

            $elLat = isset($el['lat']) ? (float) $el['lat'] : (float) ($el['center']['lat'] ?? 0);
            $elLng = isset($el['lon']) ? (float) $el['lon'] : (float) ($el['center']['lon'] ?? 0);
            if ($elLat === 0.0 && $elLng === 0.0) {
                continue;
            }

            $mapped[] = [
                'name' => $name !== '' ? $name : 'Service nearby',
                'address' => (string) ($tags['addr:full'] ?? $tags['addr:street'] ?? $tags['addr:city'] ?? ''),
                'lat' => $elLat,
                'lng' => $elLng,
                'rating' => null,
                'types' => array_values(array_filter([$amenity, $shop, $office])),
            ];
        }

        return array_slice($mapped, 0, 8);
    }

    /**
     * @return array{clauses: string[], keywords: string[]}
     */
    private function buildOsmProfile(string $query): array
    {
        $q = mb_strtolower($query);

        if (str_contains($q, 'garage') || str_contains($q, 'automobile') || str_contains($q, 'voiture')) {
            return [
                'clauses' => [
                    'node["shop"="car_repair"]',
                    'node["amenity"="car_rental"]',
                    'node["amenity"="fuel"]',
                    'way["shop"="car_repair"]',
                    'way["amenity"="fuel"]',
                ],
                'keywords' => ['garage', 'auto', 'car', 'mecan', 'pneu', 'batter', 'fuel'],
            ];
        }

        if (str_contains($q, 'pharmacie') || str_contains($q, 'hopital') || str_contains($q, 'sante')) {
            return [
                'clauses' => [
                    'node["amenity"="pharmacy"]',
                    'node["amenity"="hospital"]',
                    'node["amenity"="clinic"]',
                    'node["amenity"="doctors"]',
                    'way["amenity"="pharmacy"]',
                    'way["amenity"="hospital"]',
                    'way["amenity"="clinic"]',
                ],
                'keywords' => ['pharma', 'med', 'clinic', 'hospital', 'doctor', 'sante'],
            ];
        }

        if (str_contains($q, 'electromenager') || str_contains($q, 'telephone') || str_contains($q, 'reparation')) {
            return [
                'clauses' => [
                    'node["shop"="electronics"]',
                    'node["shop"="mobile_phone"]',
                    'node["craft"="electronics_repair"]',
                    'way["shop"="electronics"]',
                    'way["shop"="mobile_phone"]',
                    'way["craft"="electronics_repair"]',
                ],
                'keywords' => ['phone', 'mobile', 'electro', 'repair', 'service', 'tel'],
            ];
        }

        if (str_contains($q, 'ecole') || str_contains($q, 'librairie') || str_contains($q, 'papeterie')) {
            return [
                'clauses' => [
                    'node["amenity"="school"]',
                    'node["amenity"="college"]',
                    'node["amenity"="university"]',
                    'node["shop"="books"]',
                    'node["shop"="stationery"]',
                    'way["amenity"="school"]',
                    'way["amenity"="college"]',
                    'way["amenity"="university"]',
                    'way["shop"="books"]',
                    'way["shop"="stationery"]',
                ],
                'keywords' => ['school', 'college', 'university', 'book', 'stationery', 'ecole', 'librairie'],
            ];
        }

        return [
            'clauses' => [
                'node["amenity"="pharmacy"]',
                'node["amenity"="clinic"]',
                'node["shop"="car_repair"]',
                'node["shop"="mobile_phone"]',
                'way["amenity"="pharmacy"]',
                'way["shop"="car_repair"]',
                'way["shop"="mobile_phone"]',
            ],
            'keywords' => ['service', 'repair', 'pharma', 'garage', 'phone'],
        ];
    }

    /**
     * @param string[] $clauses
     */
    private function buildOsmOverpassQuery(array $clauses, float $lat, float $lng, int $radius): string
    {
        $parts = [];
        foreach ($clauses as $clause) {
            $parts[] = sprintf('%s(around:%d,%F,%F);', $clause, $radius, $lat, $lng);
        }

        return sprintf('[out:json][timeout:25];(%s);out center 30;', implode('', $parts));
    }

    /**
     * @param string[] $keywords
     */
    private function matchesKeywords(string $haystack, array $keywords): bool
    {
        if ($haystack === '' || !$keywords) {
            return false;
        }

        foreach ($keywords as $kw) {
            if (str_contains($haystack, mb_strtolower($kw))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{lat:float,lng:float}|null
     */
    public function geocode(string $address): ?array
    {
        if (!$this->apiKey || trim($address) === '') {
            return null;
        }

        $resp = $this->httpClient->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json', [
            'query' => [
                'key' => $this->apiKey,
                'address' => $address,
                'language' => 'fr',
            ],
            'timeout' => 12,
        ]);

        $data = $resp->toArray(false);
        $first = $data['results'][0]['geometry']['location'] ?? null;
        if (!is_array($first)) {
            return null;
        }

        return [
            'lat' => (float) ($first['lat'] ?? 0.0),
            'lng' => (float) ($first['lng'] ?? 0.0),
        ];
    }
}
