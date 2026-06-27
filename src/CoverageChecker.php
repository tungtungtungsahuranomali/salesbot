<?php
/**
 * Coverage Checker — cek apakah titik koordinat berada dalam area coverage
 * 
 * Menggunakan algoritma Ray Casting untuk point-in-polygon.
 * Data coverage disimpan sebagai GeoJSON FeatureCollection (polygon).
 */
class CoverageChecker
{
    private array $areas;

    public function __construct(string $coverageFile)
    {
        if (!file_exists($coverageFile)) {
            $this->areas = [];
            return;
        }
        $json = json_decode(file_get_contents($coverageFile), true);
        $this->areas = $json['features'] ?? [];
    }

    /**
     * Cek apakah titik (lat, lng) berada dalam area coverage
     * 
     * @return array ['covered' => bool, 'area_name' => string, 'areas' => [...]]
     */
    public function check(float $lat, float $lng): array
    {
        $coveredAreas = [];

        foreach ($this->areas as $feature) {
            $geometry = $feature['geometry'] ?? [];
            $props = $feature['properties'] ?? [];

            if ($geometry['type'] === 'Polygon') {
                foreach ($geometry['coordinates'] as $ring) {
                    if ($this->pointInPolygon($lng, $lat, $ring)) {
                        $coveredAreas[] = $props['name'] ?? 'Area LIGAT';
                    }
                }
            } elseif ($geometry['type'] === 'MultiPolygon') {
                foreach ($geometry['coordinates'] as $polygon) {
                    foreach ($polygon as $ring) {
                        if ($this->pointInPolygon($lng, $lat, $ring)) {
                            $coveredAreas[] = $props['name'] ?? 'Area LIGAT';
                        }
                    }
                }
            }
        }

        // Hapus duplikat
        $coveredAreas = array_unique($coveredAreas);

        if (count($coveredAreas) > 0) {
            return [
                'covered' => true,
                'area_name' => implode(', ', $coveredAreas),
                'areas' => $coveredAreas,
            ];
        }

        return [
            'covered' => false,
            'area_name' => '',
            'areas' => [],
        ];
    }

    /**
     * Point-in-Polygon menggunakan Ray Casting algorithm
     * 
     * @param float $x longitude
     * @param float $y latitude
     * @param array $polygon array of [longitude, latitude]
     */
    private function pointInPolygon(float $x, float $y, array $polygon): bool
    {
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) $inside = !$inside;
        }

        return $inside;
    }

    /**
     * Daftar nama area yang terdaftar
     */
    public function getAreaNames(): array
    {
        $names = [];
        foreach ($this->areas as $f) {
            if (isset($f['properties']['name'])) {
                $names[] = $f['properties']['name'];
            }
        }
        return $names;
    }
}
