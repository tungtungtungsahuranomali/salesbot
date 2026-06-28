<?php
/**
 * Geocoder — ubah alamat teks ke koordinat via Google Geocoding API
 * 
 * Primary: Google Geocoding API (akurat, cepat, 40rb req/bln gratis)
 * Fallback: Nominatim OpenStreetMap (gratis, rate limit 1 req/detik)
 * Hasil di-cache ke file untuk alamat yang sama (24 jam).
 */
class Geocoder
{
    private string $cacheDir;
    private string $userAgent;
    private float $minRequestInterval = 1.1; // detik antar request (Nominatim)
    private ?string $googleApiKey;

    public function __construct(string $cacheDir = '', ?string $googleApiKey = null)
    {
        $this->cacheDir = $cacheDir ?: (defined('DATA_DIR') ? DATA_DIR . '/geocode_cache' : __DIR__ . '/../data/geocode_cache');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        $this->userAgent = 'LIGAT-SalesBot/1.0 (sales@ligat.com)';
        $this->googleApiKey = $googleApiKey ?: (defined('GOOGLE_GEOCODE_API_KEY') ? GOOGLE_GEOCODE_API_KEY : null);
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

        // Coba Google Geocoding API dulu
        if ($this->googleApiKey) {
            $result = $this->callGoogle($address);
            if ($result !== null) {
                $this->saveToCache($cacheKey, $result);
                return $result;
            }
        }

        // Fallback: Nominatim
        $result = $this->callNominatim($address);
        if ($result === null) {
            return null;
        }

        $this->saveToCache($cacheKey, $result);
        return $result;
    }

    /**
     * Panggil Google Geocoding API
     */
    private function callGoogle(string $address): ?array
    {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address . ', Batam, Indonesia',
            'key' => $this->googleApiKey,
            'language' => 'id',
            'region' => 'id',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $this->logError("Google Geocoding error: $error (HTTP $httpCode)");
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || ($data['status'] ?? '') !== 'OK' || !isset($data['results'][0]['geometry']['location'])) {
            $status = $data['status'] ?? 'NO_RESULTS';
            if ($status !== 'ZERO_RESULTS') {
                $this->logError("Google Geocoding status: $status");
            }
            return null;
        }

        $loc = $data['results'][0]['geometry']['location'];
        return [
            'lat' => (float) $loc['lat'],
            'lng' => (float) $loc['lng'],
            'display_name' => $data['results'][0]['formatted_address'] ?? '',
        ];
    }

    /**
     * Panggil Nominatim API (fallback)
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
     * Extract coordinates from Google Maps URL
     */
    public function extractFromGoogleMaps(string $url): ?array
    {
        // Ikuti redirect pakai GET untuk dapet URL final
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (!$redirectUrl) return null;

        // @lat,lng or @lat,lng,zoom
        if (preg_match('/@(-?[\d\.]+),(-?[\d\.]+)/', $redirectUrl, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }
        // ?q=lat,lng or ?ll=lat,lng or ?daddr=lat,lng or ?saddr=lat,lng
        if (preg_match('/[?&](?:q|ll|daddr|saddr|center)=(-?[\d\.]+),(-?[\d\.]+)/', $redirectUrl, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }
        // !1m5!1m4!1s...!2d106.845!3d-6.214 (Google Maps encoded)
        if (preg_match('/!2d(-?[\d\.]+)!3d(-?[\d\.]+)/', $redirectUrl, $m)) {
            return ['lat' => (float)$m[2], 'lng' => (float)$m[1]];
        }
        // /data=...!3d1.1054!4d103.9835
        if (preg_match('/!3d(-?[\d\.]+)!4d(-?[\d\.]+)/', $redirectUrl, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }
        // /place/... — geocode nama tempat via Google API
        if (preg_match('/\/place\/([^\/]+)/', $redirectUrl, $m)) {
            $placeName = urldecode(str_replace(['+', '%20'], ' ', $m[1]));
            $parts = array_map('trim', explode(',', $placeName));
            $parts = array_filter($parts, fn($p) => !preg_match('/^\d{5}$/', trim($p)) && !preg_match('/^data=/i', $p));
            $parts = array_values($parts);

            // Coba dari yang paling spesifik (full) ke generik
            for ($i = count($parts); $i >= max(2, intval(count($parts) * 0.4)); $i--) {
                $q = implode(', ', array_slice($parts, 0, $i));
                $result = $this->geocode($q);
                if ($result) return $result;
            }
        }

        return null;
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
        $log = defined('DATA_DIR') ? DATA_DIR . '/geocode_error.log' : __DIR__ . '/../data/geocode_error.log';
        file_put_contents($log, date('c') . ' ' . $msg . "\n", FILE_APPEND);
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