<?php defined('BRENDY') or die('Brendy CMS.');

/** Exact disk-backed UUID uniqueness; private temporary handles, no persistent index authority. */
final class RecoveryUuidSpool
{
    private array $handles = [];
    private const BUCKET_LIMIT = 32768;

    public function add(string $id): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return false;
        $bucket = substr(hash('sha256', $id), 0, 1);
        if (!isset($this->handles[$bucket])) {
            $handle = @tmpfile();
            if ($handle === false) return false;
            $stat = fstat($handle);
            if (!is_array($stat) || ($stat['mode'] & 0170077) !== 0100000) { fclose($handle); return false; }
            $this->handles[$bucket] = $handle;
        }
        return @fwrite($this->handles[$bucket], $id . "\n") === 33;
    }

    public function verify(): bool
    {
        foreach ($this->handles as $handle) {
            if (!@fflush($handle) || !@rewind($handle)) return false;
            $seen = [];
            while (($line = fgets($handle, 34)) !== false) {
                if (!preg_match('/^[a-f0-9]{32}\n$/D', $line) || isset($seen[$line]) || count($seen) >= self::BUCKET_LIMIT) return false;
                $seen[$line] = true;
            }
            if (!feof($handle)) return false;
            unset($seen);
        }
        return true;
    }

    public function __destruct() { $this->close(); }

    public function close(): void
    {
        foreach ($this->handles as $handle) fclose($handle);
        $this->handles = [];
    }
}

