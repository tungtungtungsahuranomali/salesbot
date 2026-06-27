<?php
/**
 * State Manager — tracking percakapan per user (file-based)
 */
class StateManager
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    /**
     * Ambil state user, buat baru jika belum ada
     */
    public function getOrCreate(string $phone): array
    {
        $state = $this->get($phone);
        if ($state) return $state;

        return $this->create($phone);
    }

    /**
     * Ambil state user
     */
    public function get(string $phone): ?array
    {
        $file = $this->filePath($phone);
        if (!file_exists($file)) return null;

        $data = json_decode(file_get_contents($file), true);
        return $data ?: null;
    }

    /**
     * Buat state baru
     */
    public function create(string $phone): array
    {
        $state = [
            'phone' => $phone,
            'state' => 'start',
            'name' => '',
            'location' => null,
            'covered' => null,
            'messages_count' => 0,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $this->save($phone, $state);
        return $state;
    }

    /**
     * Update state user
     */
    public function update(string $phone, array $data): array
    {
        $state = $this->getOrCreate($phone);
        foreach ($data as $key => $value) {
            $state[$key] = $value;
        }
        $state['updated_at'] = date('c');
        $state['messages_count'] = ($state['messages_count'] ?? 0) + 1;
        $this->save($phone, $state);
        return $state;
    }

    /**
     * Simpan state ke file
     */
    private function save(string $phone, array $state): void
    {
        $file = $this->filePath($phone);
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * Path file state
     */
    private function filePath(string $phone): string
    {
        return $this->dir . '/' . $phone . '.json';
    }
}
