<?php defined('BRENDY') or die('Brendy CMS.');

/** Exact disk-backed UUID uniqueness; private temporary handles, no persistent index authority. */
final class RecoveryUuidSpool
{
    private array $handles = [];
    private const BUCKET_LIMIT = 8192;
    private const MAX_DEPTH = 8;

    private static function temporary() {
        $handle=@tmpfile();
        if ($handle===false) return false;
        $stat=fstat($handle);
        if (!is_array($stat) || ($stat['mode'] & 0170077)!==0100000 || ($stat['nlink']??0)!==1) { fclose($handle); return false; }
        return $handle;
    }

    public function add(string $id): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return false;
        $bucket = substr(hash('sha256', $id), 0, 1);
        if (!isset($this->handles[$bucket])) {
            $handle = self::temporary();
            if ($handle === false) return false;
            $this->handles[$bucket] = $handle;
        }
        return @fwrite($this->handles[$bucket], $id . "\n") === 33;
    }

    private static function verifyPartition($handle,int $depth): bool
    {
        if (!@fflush($handle) || !@rewind($handle)) return false;
        $stat=fstat($handle);
        if (!is_array($stat) || $stat['size']%33!==0) return false;
        if (intdiv($stat['size'],33)<=self::BUCKET_LIMIT) {
            $seen=[]; $count=0;
            while (($line=fgets($handle,34))!==false) {
                if (!preg_match('/^[a-f0-9]{32}\n$/D',$line) || isset($seen[$line]) || ++$count>self::BUCKET_LIMIT) return false;
                $seen[$line]=true;
            }
            return feof($handle) && $count===intdiv($stat['size'],33);
        }
        if ($depth>=self::MAX_DEPTH) return false;
        $children=[]; $count=0;
        try {
            while (($line=fgets($handle,34))!==false) {
                if (!preg_match('/^[a-f0-9]{32}\n$/D',$line)) return false;
                $bucket=substr(hash('sha256',substr($line,0,32)),$depth,1);
                if (!isset($children[$bucket])) {
                    $child=self::temporary(); if ($child===false) return false;
                    $children[$bucket]=$child;
                }
                if (@fwrite($children[$bucket],$line)!==33) return false;
                ++$count;
            }
            if (!feof($handle) || $count!==intdiv($stat['size'],33)) return false;
            foreach ($children as $child) if (!self::verifyPartition($child,$depth+1)) return false;
            return true;
        } finally { foreach ($children as $child) fclose($child); }
    }
    public function verify(): bool
    {
        foreach ($this->handles as $handle) if (!self::verifyPartition($handle,1)) return false;
        return true;
    }

    public function __destruct() { $this->close(); }

    public function close(): void
    {
        foreach ($this->handles as $handle) fclose($handle);
        $this->handles = [];
    }
}

