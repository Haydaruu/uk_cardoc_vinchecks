<?php

namespace Tests\Support;

class ConcurrencyBarrier
{
    public function __construct(protected string $dir)
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    public function mark(string $step): void
    {
        file_put_contents($this->dir.'/'.$step, (string) microtime(true));
    }

    public function has(string $step): bool
    {
        return file_exists($this->dir.'/'.$step);
    }

    public function getTimestamp(string $step): ?float
    {
        $file = $this->dir.'/'.$step;
        if (! file_exists($file)) {
            return null;
        }

        return (float) file_get_contents($file);
    }

    public function wait(string $step, float $timeoutSeconds = 8.0): float
    {
        $start = microtime(true);
        $file = $this->dir.'/'.$step;

        while (! file_exists($file)) {
            if ((microtime(true) - $start) > $timeoutSeconds) {
                throw new \RuntimeException("Barrier timeout waiting for step '{$step}' after {$timeoutSeconds}s");
            }
            usleep(2000); // 2ms polling
        }

        return (float) file_get_contents($file);
    }

    public function setMeta(string $key, array $data): void
    {
        file_put_contents($this->dir.'/'.$key.'.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function getMeta(string $key): ?array
    {
        $file = $this->dir.'/'.$key.'.json';
        if (! file_exists($file)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function cleanup(): void
    {
        if (is_dir($this->dir)) {
            $files = glob($this->dir.'/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->dir);
        }
    }
}
