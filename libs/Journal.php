<?php
declare(strict_types=1);

/** One FIFO per source mapping and variable. Acknowledged files are reclaimed immediately. */
final class RemoteSyncJournalV2
{
    public function __construct(private string $directory) {}

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) return;
        $parent = dirname($directory);
        if ($parent === $directory) throw new RuntimeException('Journal directory unavailable.');
        self::ensureDirectory($parent);
        if (!@mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('Cannot create journal directory.');
        self::syncDirectory($parent);
    }

    private static function syncDirectory(string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') return;
        $handle = fopen($directory, 'r');
        if (!$handle) throw new RuntimeException('Cannot open journal directory.');
        try {
            if (!fsync($handle)) throw new RuntimeException('Cannot flush journal directory.');
        } finally { fclose($handle); }
    }

    public static function finish(string $pending, string $receipt): void
    {
        if (!rename($pending, $receipt)) throw new RuntimeException('Cannot commit durable state.');
        self::syncDirectory(dirname($receipt));
    }

    public static function durableWrite(string $path, string $bytes): void
    {
        self::ensureDirectory(dirname($path));
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temp, 'xb');
        if (!$handle) throw new RuntimeException('Cannot create journal file.');
        try {
            for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!$written) throw new RuntimeException('Journal write failed.');
            }
            if (!fflush($handle) || !fsync($handle)) throw new RuntimeException('Journal flush failed.');
        } catch (Throwable $failure) {
            fclose($handle);
            if (is_file($temp)) unlink($temp);
            throw $failure;
        }
        fclose($handle);
        try { self::finish($temp, $path); }
        finally { if (is_file($temp)) unlink($temp); }
    }

    private function locked(string $stream, callable $operation)
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $stream)) throw new RuntimeException('Invalid stream.');
        $dir = $this->directory . '/streams/' . $stream;
        self::ensureDirectory($dir);
        $lock = fopen($dir . '/lock', 'c+b');
        if (!$lock) throw new RuntimeException('Cannot open history lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock history.');
            $path = $dir . '/state.json';
            $state = is_file($path) ? json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR)
                : ['head' => 1, 'tail' => 0, 'after' => 0, 'bytes' => 0];
            if (!isset($state['head'], $state['tail'], $state['after'], $state['bytes']) || $state['head'] < 1 || $state['tail'] < $state['head'] - 1) {
                throw new RuntimeException('Invalid history cursor. Preserve the journal.');
            }
            // A committed cursor is authoritative even if a restart interrupted physical cleanup.
            $reclaim = $state['reclaim'] ?? $state['head'];
            for ($i = $reclaim; $i < $state['head']; $i++) {
                $old = $dir . '/' . $i . '.json';
                if (is_file($old) && !unlink($old)) throw new RuntimeException('Cannot reclaim acknowledged history.');
            }
            $state['reclaim'] = $state['head'];
            // Finish an append interrupted between its event write and cursor write.
            $recovered = false;
            while (is_file($dir . '/' . ($state['tail'] + 1) . '.json')) {
                $state['tail']++;
                $state['bytes'] += filesize($dir . '/' . $state['tail'] . '.json');
                $recovered = true;
            }
            if ($recovered) self::durableWrite($path, json_encode($state, JSON_THROW_ON_ERROR));
            return $operation($dir, $path, $state);
        } finally { fclose($lock); }
    }

    public function append(array $event): void
    {
        $stream = hash('sha256', $event['MappingID'] . '/' . $event['LocalID']);
        $this->locked($stream, function ($dir, $path, $state) use ($event, $stream) {
            $marker = $this->directory . '/active/' . $stream;
            if (!is_file($marker)) self::durableWrite($marker, '');
            $event['Stream'] = $stream;
            $event['Sequence'] = ++$state['tail'];
            $event['Reliable'] = true;
            $record = json_encode($event, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            self::durableWrite($dir . '/' . $state['tail'] . '.json', $record);
            $state['bytes'] += strlen($record);
            self::durableWrite($path, json_encode($state, JSON_THROW_ON_ERROR));
        });
    }

    private function streams(): array
    {
        $entries = glob($this->directory . '/active/*');
        if ($entries === false) throw new RuntimeException('Cannot enumerate history streams.');
        return array_map('basename', $entries);
    }

    public function summary(): array
    {
        $summary = ['count' => 0, 'bytes' => 0, 'due' => null];
        foreach ($this->streams() as $stream) {
            $state = $this->locked($stream, static fn($dir, $path, $state) => $state);
            if ($state['head'] > $state['tail']) continue;
            $summary['count'] += $state['tail'] - $state['head'] + 1;
            $summary['bytes'] += $state['bytes'];
            $summary['due'] = min($summary['due'] ?? PHP_INT_MAX, $state['after']);
        }
        return $summary;
    }

    public function pending(): bool { return $this->summary()['count'] > 0; }

    public function containsStream(string $mapping, int $localID): bool
    {
        return is_file($this->directory . '/active/' . hash('sha256', $mapping . '/' . $localID));
    }

    public function peek(int $limit = 200, bool $includeDelayed = false): array
    {
        $rows = [];
        $streams = $this->streams();
        if (!$includeDelayed) shuffle($streams);
        foreach ($streams as $stream) {
            $remaining = $limit - count($rows);
            if ($remaining <= 0) break;
            if (!$includeDelayed) $remaining = min($remaining, max(1, (int)ceil($limit / count($streams))));
            $chunk = $this->locked($stream, static function ($dir, $path, $state) use ($remaining, $includeDelayed) {
                if (!$includeDelayed && $state['after'] > time()) return [];
                $batch = [];
                for ($i = $state['head']; $i <= $state['tail'] && count($batch) < $remaining; $i++) {
                    $raw = file_get_contents($dir . '/' . $i . '.json');
                    if ($raw === false) throw new RuntimeException('Pending history record missing.');
                    $batch[] = ['payload' => json_decode($raw, true, 64, JSON_THROW_ON_ERROR)];
                }
                return $batch;
            });
            array_push($rows, ...$chunk);
        }
        return $rows;
    }

    /** Only contiguous accepted prefixes advance within each variable. Other variables advance independently. */
    public function settle(array $rows, array $accepted): void
    {
        $streams = [];
        foreach ($rows as $row) $streams[$row['payload']['Stream']][] = $row['payload'];
        $ids = array_fill_keys($accepted, true);
        foreach ($streams as $stream => $events) {
            $this->locked($stream, function ($dir, $path, $state) use ($events, $ids, $stream) {
                $first = $state['head'];
                $state['after'] = 0;
                foreach ($events as $event) {
                    if ($event['Sequence'] < $state['head']) continue;
                    if ($event['Sequence'] !== $state['head']) throw new RuntimeException('History acknowledgement out of order.');
                    if (!isset($ids[$event['EventID']])) {
                        $state['after'] = time() + 30;
                        break;
                    }
                    $state['bytes'] -= filesize($dir . '/' . $state['head'] . '.json');
                    $state['head']++;
                }
                self::durableWrite($path, json_encode($state, JSON_THROW_ON_ERROR));
                for ($i = $first; $i < $state['head']; $i++) {
                    if (!unlink($dir . '/' . $i . '.json')) throw new RuntimeException('Cannot reclaim acknowledged history.');
                }
                if ($state['head'] > $state['tail']) {
                    $marker = $this->directory . '/active/' . $stream;
                    if (is_file($marker) && !unlink($marker)) throw new RuntimeException('Cannot retire empty stream.');
                }
            });
        }
    }
}
