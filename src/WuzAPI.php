<?php
/**
 * WuzAPI HTTP Client
 */
class WuzAPI
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /**
     * Kirim pesan text
     */
    public function sendText(string $phone, string $body, ?string $id = null): array
    {
        $payload = [
            'Phone' => $phone,
            'Body' => $body,
        ];
        if ($id) $payload['Id'] = $id;

        return $this->post('/chat/send/text', $payload);
    }

    /**
     * Kirim template dengan tombol
     */
    public function sendTemplate(string $phone, string $template, array $buttons): array
    {
        return $this->post('/chat/send/template', [
            'Phone' => $phone,
            'Template' => $template,
            'buttons' => $buttons,
        ]);
    }

    /**
     * Kirim lokasi
     */
    public function sendLocation(string $phone, float $lat, float $lng, string $name = ''): array
    {
        return $this->post('/chat/send/location', [
            'Phone' => $phone,
            'latitude' => $lat,
            'longitude' => $lng,
            'name' => $name,
        ]);
    }

    /**
     * Set webhook URL
     */
    public function setWebhook(string $url, array $events = ['Message']): array
    {
        return $this->post('/webhook', [
            'url' => $url,
            'events' => $events,
        ]);
    }

    /**
     * Cek status session
     */
    public function sessionStatus(): array
    {
        return $this->get('/session/status');
    }

    /**
     * HTTP POST request
     */
    private function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    /**
     * HTTP GET request
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * Raw HTTP request
     */
    private function request(string $method, string $path, ?array $data = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Token: ' . $this->token,
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'CURL Error: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!$decoded) {
            return ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $response];
        }

        $decoded['http_code'] = $httpCode;
        return $decoded;
    }
}
