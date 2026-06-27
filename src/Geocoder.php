<?php
/**
 * Geocoder — ubah alamat teks ke koordinat via Nominatim API (OpenStreetMap)
 * 
 * Free, no API key needed. Max 1 request per detik (rate limit).
 * Hasil di-cache ke file untuk alamat yang sama.
 */
class Geocoder
{
    private string $cacheDir;
    private string $userAgent;
    private float $minRequestInterval = 1.1; // detik antar request

    public function __construct(string $cacheDir = '')
    {
        $this->cacheDir = $cacheDir ?: (defined('DATA_DIR') ? DATA_DIR . '/geocode_cache' : __DIR__ . '/../data/geocode_cache');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        $this->userAgent = 'LIGAT-SalesBot/1.0 (sales@ligat.com)';
    }

    /**
     * Geocode alamat ke koordinat
     * 
     * @return array|null ['lat' => float, 'lng' => float, 'display_name' => string] atau null jika gagal
     */
    public function geocode(string $address): ?array
    {
        $cacheKey = $this->cacheKey($address);
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->callNominatim($address);
        if ($result === null) {
            return null;
        }

        // Simpan ke cache
        $this->saveToCache($cacheKey, $result);
        return $result;
    }

    /**
     * Panggil Nominatim API
     */
    private function callNominatim(string $address): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $address . ', Batam, Indonesia',
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 0,
        ]);

        // Rate limit
        usleep($this->minRequestInterval * 1_000_000);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept-Language: id'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $this->logError("Nominatim error: $error (HTTP $httpCode)");
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
            'display_name' => $data[0]['display_name'] ?? '',
        ];
    }

    /**
     * Cache key dari alamat
     */
    private function cacheKey(string $address): string
    {
        return md5(strtolower(trim($address))) . '.json';
    }

    /**
     * Ambil dari cache (valid 24 jam)
     */
    private function getFromCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key;
        if (!file_exists($file)) return null;

        $data = json_decode(file_get_contents($file), true);
        if (!$data) return null;

        // Expired setelah 24 jam
        if (time() - ($data['cached_at'] ?? 0) > 86400) {
            unlink($file);
            return null;
        }

        return $data['result'] ?? null;
    }

    /**
     * Simpan ke cache
     */
    private function saveToCache(string $key, array $result): void
    {
        $file = $this->cacheDir . '/' . $key;
        file_put_contents($file, json_encode([
            'cached_at' => time(),
            'result' => $result,
        ]));
    }

    /**
     * Log error
     */
    private function logError(string $msg): void
    {
        $log = DATA_DIR . '/geocode_error.log';
        file_put_contents($log, date('c') . ' ' . $msg . "\n", FILE_APPEND);
    }

    // ─── Google Maps URL Parser ───

    /**
     * Extract coordinates from Google Maps URL
     */
    public function extractFromGoogleMaps(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
        ]);
        $response = curl_exec($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (!$redirectUrl) return null;

        // @lat,lng or @lat,lng,zoom
        if (preg_match('/@([\d\.\-]+),([\d\.\-]+)/', $redirectUrl, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }
        // ?q=lat,lng or ?ll=lat,lng
        if (preg_match('/[?&](?:q|ll)=([\d\.\-]+),([\d\.\-]+)/', $redirectUrl, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }
        // Format: /place/.../data=... (extract place name and geocode)
        if (preg_match('/\/place\/([^\/]+)/', $redirectUrl, $m)) {
            $placeName = urldecode(str_replace(['+', '%20'], ' ', $m[1]));
            // Ambil bagian yang lebih sederhana: setelah koma ke-2 atau ke-3
            $parts = array_map('trim', explode(',', $placeName));
            $parts = array_filter($parts, fn($p) => !preg_match('/^\d{5}$/', $p) && !preg_match('/^data=/i', $p));
            $parts = array_values($parts);
            // Coba kombinasi: bagian terakhir yang masuk akal
            $queries = [
                implode(', ', array_slice($parts, -3)), // Kec. Sekupang, Kota Batam
                implode(', ', array_slice($parts, -2)), // Kota Batam
            ];
            foreach ($queries as $q) {
                if (preg_match('/batam|sekupang|nongsa|lubuk|bengkong|seibeduk|batuampar|galang|bulang|belakangpadang/i', $q)) {
                    $result = $this->geocode($q);
                    if ($result) return $result;
                }
            }
            // Fallback: ambil 2 bagian terakhir yang mengandung lokasi
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $q = $parts[$i] . ', Batam';
                $result = $this->geocode($q);
                if ($result) return $result;
            }
        }
        return null;
    }

    /**
     * Cek apakah teks mengandung Google Maps URL
     */
    public function containsGoogleMapsUrl(string $text): ?string
    {
        if (preg_match('/https?:\/\/(?:maps\.google\.[a-z]+\/|maps\.app\.goo\.gl\/|goo\.gl\/maps\/)[^\s]+/', $text, $m)) {
            return $m[0];
        }
        return null;
    }
}
