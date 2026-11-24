<?php defined('BRENDY') or die('Brendy CMS.');
require_once __DIR__.'/recovery-uuid-spool.class.php';

/** Authenticated, bounded atomic state. Exclusive restore gate required for writes.
 * The key is installation-local, outside the replaced content tree (tmp).
 * Restoring both the key and ledger from an older filesystem image is not detectable. */
final class RecoveryLedger
{
    const FILE = 'restore-ledger.json';
    const MIRROR = 'restore-ledger-mirror.json';
    const KEY = 'restore-ledger-key.php';
    const LOOKUP = 'restore-ledger-lookup.json';
    const RETIREMENT = 'restore-ledger-retirement.json';
    const LIMIT = 16777216;
    const MAX_HISTORY = 100000;
    const INDEX_PAGE_SIZE = 64;
    const INDEX_THRESHOLD = 16;
    const SHARD_SIZE = 256;
    const SHARD_LIMIT = 524288;
    private const PREFIX = '<?php exit; ?>' . "\n";
    public function __construct(private string $tmp) {}
    private function path(string $name): string { return $this->tmp . DS . $name; }
    public function exists(): bool
    {
        if (RestoreJournal::identity($this->path(self::KEY)) !== null || RestoreJournal::identity($this->path(self::FILE)) !== null || RestoreJournal::identity($this->path(self::MIRROR)) !== null || RestoreJournal::identity($this->path(self::RETIREMENT)) !== null || RestoreJournal::identity($this->path(self::LOOKUP)) !== null) return true;
        // A configured external checkpoint is itself evidence of an installation.
        $checkpoint=getenv('BRENDY_RECOVERY_CHECKPOINT');
        if (($checkpoint!==false && $checkpoint!=='') || (($pub=getenv('BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY'))!==false && $pub!=='')) return true;
        if (!is_dir($this->tmp)) return false;
        // Missing main artifacts must not silently reset authenticated state when
        // shard or GC evidence remains. No scan on the ordinary initialized path.
        try {
            $count=0;
            foreach (new FilesystemIterator($this->tmp,FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$count>10000 || preg_match('/^restore-(?:ledger-(?:shard|page)-(?:copy-)?[a-f0-9]{64}|ledger-lookup-bucket-[a-f0-9]{2}|gc-[a-f0-9]{32}(?:-cursor)?)\.json$/D',$entry->getFilename())) return true;
            }
        } catch (Throwable $e) { return true; }
        return false;
    }
    private function privateRead(string $name, int $max)
    {
        $path=$this->path($name); $id=RestoreJournal::identity($path);
        if (!is_array($id) || $id[2] !== 0100000 || (@fileperms($path)&0777)!==0600) return false;
        $raw=Filesystem::readFileBounded($path,$max,1);
        return is_string($raw) && RestoreJournal::identity($path)===$id ? $raw : false;
    }
    private function keyState()
    {
        $raw=$this->privateRead(self::KEY,1024);
        if (!is_string($raw) || !str_starts_with($raw,self::PREFIX)) return false;
        $data=substr($raw,strlen(self::PREFIX));
        if (preg_match('/^[a-f0-9]{64}$/D',$data)) return ['schema'=>1,'installation'=>hash('sha256',hex2bin($data)),'active'=>$data,'previous'=>null];
        try { $ring=json_decode($data,true,8,JSON_THROW_ON_ERROR); } catch (Throwable $e) { return false; }
        if (!is_array($ring) || ($ring['schema']??null)!==2 || !array_key_exists('previous',$ring) ||
            !is_string($ring['installation']??null) || !preg_match('/^[a-f0-9]{64}$/D',$ring['installation']) ||
            !is_string($ring['active']??null) || !preg_match('/^[a-f0-9]{64}$/D',$ring['active']) ||
            ($ring['previous']!==null && (!is_string($ring['previous']) || !preg_match('/^[a-f0-9]{64}$/D',$ring['previous']) || $ring['previous']===$ring['active']))) return false;
        return $ring;
    }
    private function key()
    {
        $ring=$this->keyState(); return $ring===false ? false : hex2bin($ring['active']);
    }
    private function keys()
    {
        $ring=$this->keyState(); if ($ring===false) return false;
        $keys=[hex2bin($ring['active'])]; if ($ring['previous']!==null) $keys[]=hex2bin($ring['previous']); return $keys;
    }
    private function saveKeyState(array $ring): bool
    {
        return Filesystem::atomicWrite($this->path(self::KEY),self::PREFIX.self::encode($ring),0600);
    }
    public function keyStatus(): string
    {
        $ring=$this->keyState(); return $ring===false ? 'unavailable' : ($ring['previous']===null ? 'active' : 'rotation-pending');
    }
    // Exclusive gate and maintenance mode required. Preserve immutable GC
    // payloads and cursor digests while migrating their authentication.
    public function rotateKey(): bool
    {
        $ring=$this->keyState(); $state=$this->query();
        if ($ring===false || $state===false || $state['active']!==null || (new RestoreJournal(dirname($this->tmp)))->pending()) return false;
        $evidence=$this->gcEvidence();
        if ($evidence===false) return false;
        // Atomic ring publication preserves the previous key until all new
        // state is published. A retry resumes the same pending generation.
        if ($ring['previous']===null) {
            $ring=['schema'=>2,'installation'=>$ring['installation'],'active'=>bin2hex(random_bytes(32)),'previous'=>$ring['active']];
            if (!$this->saveKeyState($ring)) return false;
        }
        // A crash between individual evidence writes is safe: both keys remain
        // available and the unchanged payload keeps the cursor binding stable.
        foreach ($evidence as $name=>$domain) {
            $raw=$this->privateRead($name,self::LIMIT);
            $value=is_string($raw)?$this->open($raw,$domain):false;
            if ($value===false) return false;
            $sealed=$this->seal($value,$domain);
            if (!is_string($sealed) || strlen($sealed)>self::LIMIT ||
                ($sealed!==$raw && !Filesystem::atomicWrite($this->path($name),$sealed,0600))) return false;
        }
        if (!$this->recordRetirement() || !$this->republishWithActiveKey() || $this->mirrorStatus()!=='in-sync') return false;
        // Verify both indexes and every referenced shard using ONLY the new
        // key before retiring the old key. Do not rely on compatibility reads.
        $active=hex2bin($ring['active']);
        foreach ([self::FILE,self::MIRROR] as $name) {
            $raw=$this->privateRead($name,self::LIMIT);
            $index=is_string($raw)?$this->decodeIndex($raw,[$active]):false;
            if ($index===false || $index['active']!==null || $index['records']!==$state['total']) return false;
            foreach ($index['shards'] as $i=>$digest) {
                $degraded=false;
                if ($this->segmentEntries($digest,$i,[$active],$degraded,true)===false || $degraded) return false;
            }
        }
        foreach ($evidence as $name=>$domain) {
            $raw=$this->privateRead($name,self::LIMIT);
            if (!is_string($raw) || self::openWithKey($raw,$domain,$active)===false) return false;
        }
        $proof=$this->privateRead(self::RETIREMENT,self::LIMIT);
        if (!is_string($proof) || self::openWithKey($proof,'shard-retirement-v1',$active)===false) return false;
        $ring['previous']=null;
        if (!$this->saveKeyState($ring)) return false;
        if (RestoreJournal::identity($this->path(self::LOOKUP))!==null) {
            try { $this->rebuildLookup(); } catch (Throwable $e) { /* derived cache is optional */ }
        }
        return true;
    }
    // Scan names first, then validate plans one at a time against the signed
    // receipt and cursor. Do not hold all media manifests in memory.
    private function gcEvidence()
    {
        $files=[]; $count=0;
        try {
            foreach (new FilesystemIterator($this->tmp,FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$count>10000) return false;
                $name=$entry->getFilename();
                if (preg_match('/^restore-gc-([a-f0-9]{32})(-cursor)?\.json$/D',$name,$m)) $files[$name]=isset($m[2])?'gc-cursor-v1':'gc-plan-v1';
            }
        } catch (Throwable $e) { return false; }
        $planIds=[];
        foreach ($files as $name=>$domain) if ($domain==='gc-plan-v1') $planIds[]=substr($name,11,32);
        $receipts=[]; $offset=0;
        foreach ($files as $name=>$domain) {
            if ($domain==='gc-cursor-v1') {
                if (!isset($files[str_replace('-cursor.json','.json',$name)])) return false;
                continue;
            }
            $id=substr($name,11,32);
            if ($offset%100===0) {
                $q=$this->query(null,array_slice($planIds,$offset,100));
                if ($q===false) return false;
                $receipts=$q['matches']; unset($q);
            }
            ++$offset; $receipt=$receipts[$id]??null;
            if (($receipt['result']??null)!=='committed') return false;
            $raw=$this->privateRead($name,self::LIMIT); $plan=is_string($raw)?$this->open($raw,$domain):false;
            if (!is_array($receipt) || !is_array($plan) || ($plan['schema']??null)!==1 ||
                ($plan['name']??null)!=='backup-restore-rollback-'.$id || ($plan['root']??null)!==RestoreJournal::identity($this->tmp) ||
                !is_array($plan['entries']??null) || !array_is_list($plan['entries']) || !$plan['entries'] || count($plan['entries'])>10000) return false;
            $last=$plan['entries'][count($plan['entries'])-1];
            if (($last['path']??null)!=='' || ($last['id']??null)!==$receipt['garbage']['id'] || ($last['digest']??null)!==$receipt['garbage']['digest']) return false;
            $cursorName=substr($name,0,-5).'-cursor.json';
            if (isset($files[$cursorName])) {
                $raw=$this->privateRead($cursorName,self::LIMIT); $cursor=is_string($raw)?$this->open($raw,'gc-cursor-v1'):false;
                if (!is_array($cursor) || ($cursor['plan']??null)!==self::digest($plan) || !is_int($cursor['next']??null) || $cursor['next']<0 || $cursor['next']>count($plan['entries'])) return false;
            }
        }
        return $files;
    }

    // Certificates attest to the exact bytes of previously authenticated
    // shards, never to arbitrary paths. Keep them after deletion for audit.
    private function retirement()
    {
        if (RestoreJournal::identity($this->path(self::RETIREMENT))===null) return ['schema'=>1,'digests'=>[]];
        $raw=$this->privateRead(self::RETIREMENT,self::LIMIT);
        $value=is_string($raw)?$this->open($raw,'shard-retirement-v1'):false;
        if (!is_array($value) || ($value['schema']??null)!==1 || !is_array($value['digests']??null) || count($value['digests'])>50000) return false;
        foreach ($value['digests'] as $digest=>$time) if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest) || !is_int($time) || $time<=0) return false;
        return $value;
    }
    private function recordRetirement(): bool
    {
        $ring=$this->keyState(); $proof=$this->retirement();
        if ($ring===false || $ring['previous']===null || $proof===false) return false;
        $old=hex2bin($ring['previous']); $count=0;
        try {
            foreach (new FilesystemIterator($this->tmp,FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$count>10000) return false;
                $name=$entry->getFilename();
                if (!preg_match('/^restore-ledger-shard-(?:copy-)?([a-f0-9]{64})\.json$/D',$name,$m) || isset($proof['digests'][$m[1]])) continue;
                $raw=$this->privateRead($name,self::SHARD_LIMIT);
                if (!is_string($raw) || !hash_equals($m[1],hash('sha256',$raw))) continue;
                $shard=self::openWithKey($raw,'ledger-shard-v2',$old);
                if (!is_array($shard) || ($shard['schema']??null)!==1 || !is_int($shard['start']??null) || $shard['start']<1 ||
                    !is_array($shard['entries']??null) || !array_is_list($shard['entries']) || count($shard['entries'])!==self::SHARD_SIZE) continue;
                foreach ($shard['entries'] as $i=>$r) if (!self::validReceipt($r,$shard['start']+$i)) continue 2;
                if (count($proof['digests'])>=50000) return false;
                $proof['digests'][$m[1]]=time();
            }
        } catch (Throwable $e) { return false; }
        $sealed=$this->seal($proof,'shard-retirement-v1');
        return is_string($sealed) && strlen($sealed)<=self::LIMIT && Filesystem::atomicWrite($this->path(self::RETIREMENT),$sealed,0600);
    }
    public function retirementCount()
    {
        $proof=$this->retirement(); return $proof===false?false:count($proof['digests']);
    }

    // Caller holds exclusive recovery gate; only exact unreferenced, aged,
    // authenticated shards or exact retirement-certified bytes are candidates.
    // Never touch unproven files, a divergent mirror, or active recovery state.
    public function orphanShards(bool $remove=false)
    {
        $state=$this->query();
        if ($state===false || $state['active']!==null || $this->keyStatus()!=='active' ||
            (new RestoreJournal(dirname($this->tmp)))->pending() || $this->mirrorStatus()!=='in-sync') return false;
        $root=RestoreJournal::identity($this->tmp);
        if (!is_array($root) || $root[2]!==0040000 || realpath($this->tmp)!==$this->tmp) return false;
        $refs=[]; $indexes=[];
        foreach ([self::FILE,self::MIRROR] as $name) {
            $raw=$this->privateRead($name,self::LIMIT); if (!is_string($raw)) return false;
            $indexes[$name]=hash('sha256',$raw);
            $keys=$this->keys(); $index=$keys===false?false:$this->decodeIndex($raw,$keys);
            if ($index===false) return false;
            foreach ($index['shards'] as $digest) $refs[$digest]=true;
        }
        $proof=$this->retirement(); if ($proof===false) return false;
        $candidates=[]; $count=0;
        try {
            foreach (new FilesystemIterator($this->tmp,FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$count>10000) return false;
                $name=$entry->getFilename();
                if (!preg_match('/^restore-ledger-shard-(?:copy-)?([a-f0-9]{64})\.json$/D',$name,$m) || isset($refs[$m[1]])) continue;
                $path=$this->path($name); $id=RestoreJournal::identity($path); $mtime=@filemtime($path);
                if (!is_array($id) || $id[2]!==0100000 || !is_int($mtime) || $mtime>time()-86400) continue;
                $raw=$this->privateRead($name,self::SHARD_LIMIT);
                if (!is_string($raw) || hash('sha256',$raw)!==$m[1] || ($this->open($raw,'ledger-shard-v2')===false && !isset($proof['digests'][$m[1]]))) continue;
                $candidates[$name]=[$id,$m[1],strlen($raw),$mtime];
            }
        } catch (Throwable $e) { return false; }
        if (!$remove) return array_keys($candidates);
        $removed=[];
        foreach ($candidates as $name=>[$id,$hash,$size,$mtime]) {
            if (RestoreJournal::identity($this->tmp)!==$root || $this->retirement()!==$proof) return false;
            foreach ($indexes as $indexName=>$expected) {
                $raw=$this->privateRead($indexName,self::LIMIT);
                if (!is_string($raw) || hash('sha256',$raw)!==$expected) return false;
            }
            $path=$this->path($name); $raw=$this->privateRead($name,self::SHARD_LIMIT);
            if (!is_string($raw) || strlen($raw)!==$size || hash('sha256',$raw)!==$hash || RestoreJournal::identity($path)!==$id || @filemtime($path)!==$mtime || !@unlink($path)) return false;
            $removed[]=$name;
        }
        return $removed;
    }

    public static function encode(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    public static function digest(array $value): string { return hash('sha256',self::encode($value)); }
    public function seal(array $value, string $domain)
    {
        $key=$this->key(); if ($key===false) return false;
        return self::sealWithKey($value,$domain,$key);
    }
    private static function sealWithKey(array $value,string $domain,string $key): string
    {
        $payload=self::encode($value);
        return self::encode(['payload'=>$payload,'mac'=>hash_hmac('sha256',$domain."\0".$payload,$key)]);
    }
    public function open(string $raw,string $domain)
    {
        $keys=$this->keys(); if ($keys===false) return false;
        return self::openWithKeys($raw,$domain,$keys);
    }
    private static function openWithKeys(string $raw,string $domain,array $keys)
    {
        foreach ($keys as $key) { $value=self::openWithKey($raw,$domain,$key); if ($value!==false) return $value; }
        return false;
    }
    private static function openWithKey(string $raw,string $domain,string $key)
    {
        try { $env=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { return false; }
        if (!is_array($env) || !is_string($env['payload']??null) || !is_string($env['mac']??null) ||
            !hash_equals(hash_hmac('sha256',$domain."\0".$env['payload'],$key),$env['mac'])) return false;
        try { $value=json_decode($env['payload'],true,64,JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { return false; }
        return is_array($value) ? $value : false;
    }
    public function initialize(): bool
    {
        if ($this->exists()) return $this->query()!==false;
        // If interrupted between the two publications, fail closed; never regenerate a missing key or ledger.
        if (!Filesystem::atomicWrite($this->path(self::KEY),self::PREFIX.bin2hex(random_bytes(32)),0600)) return false;
        return $this->save(['schema'=>1,'active'=>null,'history'=>[]]);
    }
    // Read-only query retains one segment plus the selected receipts and a
    // exact ID verification (disk-backed for large queries). All segments are
    // verified before returning results. Explicit index collection retains the map.
    // The caller must hold the shared or exclusive restore gate.
    public function query(?int $page=null, array $wantedIds=[], ?int $sequence=null, bool $all=false, bool $collectIndex=false, ?callable $visitor=null)
    {
        if (count($wantedIds)>100 || ($sequence!==null && $sequence<1)) return false;
        $wanted=[];
        foreach ($wantedIds as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) return false;
            $wanted[$id]=true;
        }
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        if (!is_string($raw) || $keys===false) return false;
        $index=$this->decodeIndex($raw,$keys);
        if ($index===false) return false;
        $total=$index['records']; $active=$index['active'];
        $page=$page!==null && $page>=1 && $page<=max(1,(int)ceil($total/50))?$page:1;
        $end=$total-($page-1)*50; $begin=max(1,$end-49);
        $anchor=$this->loadCheckpoint(); if ($anchor===false) return false;
        $ctx=null;
        if ($anchor!==null) {
            $ring=$this->keyState();
            if ($ring===false || $anchor['installation']!==$ring['installation'] || $anchor['seq']>$total) return false;
            $ctx=hash_init('sha256'); hash_update($ctx,'[');
        }
        $ids=[]; $history=[]; $matches=[]; $last=null; $found=null; $seq=0;
        $spool=(!$collectIndex && $total>=4096)?new RecoveryUuidSpool():null;
        // The sequence index determines the requested window. Even unrelated
        // segments are authenticated and checked for cross-segment duplicates.
        for ($i=0;$i<=count($index['shards']);$i++) {
            $entries=$i===count($index['shards'])?$index['tail']:$this->segmentEntries($index['shards'][$i],$i,$keys);
            if ($entries===false) return false;
            foreach ($entries as $r) {
                ++$seq;
                if (!self::validReceipt($r,$seq)) return false;
                if ($spool!==null) { if (!$spool->add($r['id'])) return false; }
                else { if (isset($ids[$r['id']])) return false; $ids[$r['id']]=$seq; }
                if ($visitor!==null && !$visitor($r)) return false;
                if ($ctx!==null && $seq<=$anchor['seq']) { if ($seq>1) hash_update($ctx,','); hash_update($ctx,self::encode($r)); }
                if ($all || ($seq>=$begin && $seq<=$end)) $history[]=$r;
                if (isset($wanted[$r['id']])) $matches[$r['id']]=$r;
                if ($seq===$sequence) $found=$r;
                $last=$r;
            }
            unset($entries);
        }
        if ($seq!==$total || ($active!==null && (!is_array($active) || ($active['seq']??null)!==$total+1 || !is_string($active['id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$active['id']) || isset($ids[$active['id']])))) return false;
        if ($spool!==null) {
            if (($active!==null && !$spool->add($active['id'])) || !$spool->verify()) return false;
            $spool->close();
        }
        if ($ctx!==null) { hash_update($ctx,']'); if (!hash_equals($anchor['prefix'],hash_final($ctx))) return false; }
        // Never return a mixed generation even if a noncooperating writer changes the primary.
        if ($this->privateRead(self::FILE,self::LIMIT)!==$raw || $this->keys()!==$keys || $this->loadCheckpoint()!==$anchor) return false;
        return ['schema'=>1,'active'=>$active,'history'=>$history,'total'=>$total,'page'=>$page,'last'=>$last,'matches'=>$matches,'sequence'=>$found]+($collectIndex?['idIndex'=>$ids,'generation'=>hash('sha256',$raw)]:[]);
    }
    // Derived acceleration data, never an authority for recovery or global
    // integrity. A stale/missing/corrupt cache falls back to a full query.
    // Callers hold the restore gate; rebuild requires exclusive access.
    private static function lookupBucket(string $id): string { return substr(hash('sha256',$id),0,2); }
    private function bucketName(string $bucket): string { return 'restore-ledger-lookup-bucket-'.$bucket.'.json'; }
    private function writeBucket(string $bucket,array $ids)
    {
        $raw=$this->seal(['schema'=>1,'bucket'=>$bucket,'ids'=>$ids],'ledger-lookup-bucket-v1');
        if (!is_string($raw) || strlen($raw)>self::LIMIT) return false;
        $name=$this->bucketName($bucket);
        if ($this->privateRead($name,self::LIMIT)!==$raw && !Filesystem::atomicWrite($this->path($name),$raw,0600)) return false;
        return hash('sha256',$raw);
    }
    private function writeLookupManifest(string $generation,int $count,array $buckets): bool
    {
        ksort($buckets,SORT_STRING);
        $raw=$this->seal(['schema'=>2,'generation'=>$generation,'records'=>$count,'buckets'=>$buckets],'ledger-lookup-v2');
        return is_string($raw) && Filesystem::atomicWrite($this->path(self::LOOKUP),$raw,0600);
    }
    public function rebuildLookup(): bool
    {
        if ($this->keyStatus()!=='active') return false;
        $primary=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        if (!is_string($primary) || $keys===false) return false;
        $spools=[];
        try {
            $q=$this->query(null,[],null,false,false,static function(array $r) use (&$spools): bool {
                $bucket=self::lookupBucket($r['id']);
                if (!isset($spools[$bucket])) { $f=tmpfile(); if ($f===false) return false; $spools[$bucket]=$f; }
                $line=$r['id'].' '.$r['seq']."\n";
                return fwrite($spools[$bucket],$line)===strlen($line);
            });
            if ($q===false || $this->privateRead(self::FILE,self::LIMIT)!==$primary || $this->keys()!==$keys) return false;
            $buckets=[]; $count=0;
            foreach ($spools as $bucket=>$f) {
                if (!rewind($f)) return false;
                $ids=[];
                while (($line=fgets($f,64))!==false) {
                    if (!preg_match('/^([a-f0-9]{32}) ([1-9][0-9]*)\n$/D',$line,$m) || isset($ids[$m[1]])) return false;
                    $ids[$m[1]]=(int)$m[2];
                }
                if (!feof($f)) return false;
                $count+=count($ids);
                $digest=$this->writeBucket((string)$bucket,$ids); if ($digest===false) return false;
                $buckets[(string)$bucket]=$digest;
            }
            return $count===$q['total'] && $this->privateRead(self::FILE,self::LIMIT)===$primary && $this->keys()===$keys && $this->writeLookupManifest(hash('sha256',$primary),$count,$buckets);
        } finally { foreach ($spools as $f) fclose($f); }
    }
    private function lookupManifest(string $generation,array $keys,int $count)
    {
        $raw=$this->privateRead(self::LOOKUP,self::LIMIT);
        $map=is_string($raw)?self::openWithKeys($raw,'ledger-lookup-v2',$keys):false;
        if (!is_array($map) || ($map['schema']??null)!==2 || ($map['generation']??null)!==$generation || ($map['records']??null)!==$count || !is_array($map['buckets']??null) || count($map['buckets'])>256) return false;
        foreach ($map['buckets'] as $bucket=>$digest) if (!preg_match('/^[a-f0-9]{2}$/D',(string)$bucket) || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) return false;
        return $map['buckets'];
    }
    private function readBucket(string $bucket,array $buckets,array $keys)
    {
        if (!isset($buckets[$bucket])) return [];
        $raw=$this->privateRead($this->bucketName($bucket),self::LIMIT);
        if (!is_string($raw) || !hash_equals($buckets[$bucket],hash('sha256',$raw))) return false;
        $value=self::openWithKeys($raw,'ledger-lookup-bucket-v1',$keys);
        if (!is_array($value) || ($value['schema']??null)!==1 || ($value['bucket']??null)!==$bucket || !is_array($value['ids']??null)) return false;
        return $value['ids'];
    }
    private function updateLookup(string $old,string $new,array $keys,int $count,?array $receipt): void
    {
        $buckets=$this->lookupManifest($old,$keys,$count);
        if ($buckets===false) return;
        if ($receipt!==null) {
            $bucket=self::lookupBucket($receipt['id']); $ids=$this->readBucket($bucket,$buckets,$keys);
            if ($ids===false || isset($ids[$receipt['id']])) return;
            $ids[$receipt['id']]=$receipt['seq'];
            $digest=$this->writeBucket($bucket,$ids); if ($digest===false) return;
            $buckets[$bucket]=$digest; ++$count;
        }
        $this->writeLookupManifest($new,$count,$buckets);
    }
    public function lookup(string $id)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) return false;
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        // A configured external anchor always requires full prefix verification.
        if (is_string($raw) && $keys!==false && $this->keyStatus()==='active' && $this->loadCheckpoint()===null) {
            $index=$this->decodeIndex($raw,$keys);
            $buckets=$index===false?false:$this->lookupManifest(hash('sha256',$raw),$keys,$index['records']);
            $map=$buckets===false?false:$this->readBucket(self::lookupBucket($id),$buckets,$keys);
            if ($index!==false && $map!==false) {
                $seq=$map[$id]??null;
                if (is_int($seq) && $seq>=1 && $seq<=$index['records']) {
                    $i=intdiv($seq-1,self::SHARD_SIZE); $offset=($seq-1)%self::SHARD_SIZE;
                    if (!$index['shards']) { $entries=$index['tail']; $offset=$seq-1; }
                    else $entries=$i===count($index['shards'])?$index['tail']:$this->segmentEntries($index['shards'][$i],$i,$keys);
                    $r=is_array($entries)?($entries[$offset]??null):null;
                    if (self::validReceipt($r,$seq) && $r['id']===$id && $this->privateRead(self::FILE,self::LIMIT)===$raw && $this->keys()===$keys && $this->loadCheckpoint()===null) return $r;
                }
            }
        }
        // Negative lookup also checks all segments, so an incomplete cache
        // cannot turn an existing transaction into a false negative.
        $q=$this->query(null,[$id]); return $q===false?false:($q['matches'][$id]??null);
    }

    // Validate the authoritative generation, then change only the active plan
    // or append its receipt. Existing full shards remain byte-for-byte pinned.
    // No history rewrite or deletion is possible through this API.
    public function mutate(?array $expected, ?array $next, ?array $receipt=null, bool $preview=false): bool
    {
        if ($this->keyStatus()!=='active') return false;
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        $ids=[]; if ($next!==null) $ids[]=$next['id']??'';
        $q=$this->query(null,$ids);
        if (!is_string($raw) || $keys===false || $q===false || $q['active']!==$expected || $this->privateRead(self::FILE,self::LIMIT)!==$raw) return false;
        $index=$this->decodeIndex($raw,$keys); if ($index===false) return false;
        $seq=$q['total']+1;
        $head=$q['last']===null?str_repeat('0',64):self::digest($q['last']);
        if ($next!==null) {
            if ($receipt!==null || ($next['seq']??null)!==$seq || ($next['head']??null)!==$head || !is_string($next['marker']??null) || !preg_match('/^[a-f0-9]{64}$/D',$next['marker']) || isset($q['matches'][$next['id']]) ||
                !in_array($next['state']??null,['prepared','committed'],true) ||
                ($expected===null && $next['state']!=='prepared') ||
                ($expected!==null && (($expected['id']??null)!==$next['id'] || ($expected['marker']??null)!==($next['marker']??null) || ($expected['state']??null)!=='prepared' || $next['state']!=='committed'))) return false;
        } elseif ($receipt===null || $expected===null || !self::validReceipt($receipt,$seq) || $receipt['id']!==($expected['id']??null) || $receipt['marker']!==($expected['marker']??null) || $receipt['stage']!==($expected['stage']??null) || $receipt['result']!==($expected['state']==='committed'?'committed':'rolled-back') || $seq>self::MAX_HISTORY) return false;
        if ($next!==null && $seq>self::MAX_HISTORY) return false;
        // Legacy monolithic state is migrated in bounded segments when possible.
        $index['active']=$next;
        if ($receipt!==null) { $index['tail'][]=$receipt; ++$index['records']; }
        $key=$keys[0];
        while (count($index['tail'])>=self::SHARD_SIZE) {
            $entries=array_slice($index['tail'],0,self::SHARD_SIZE);
            $segment=self::sealWithKey(['schema'=>1,'start'=>count($index['shards'])*self::SHARD_SIZE+1,'entries'=>$entries],'ledger-shard-v2',$key);
            if (strlen($segment)>self::SHARD_LIMIT) return false;
            $digest=hash('sha256',$segment);
            if (!$preview && !$this->publishShard($digest,$segment)) return false;
            $index['shards'][]=$digest; $index['tail']=array_slice($index['tail'],self::SHARD_SIZE);
        }
        $candidate=$this->sealIndex($index,$key,!$preview,self::openWithKeys($raw,'ledger-index-v3',$keys)!==false);
        if (!is_string($candidate) || strlen($candidate)>self::LIMIT || $this->privateRead(self::FILE,self::LIMIT)!==$raw || $this->keys()!==$keys) return false;
        if ($preview) return true;
        if (!$this->publishIndex($candidate)) return false;
        // Derived index publication is deliberately after commit. Failure
        // cannot convert a committed mutation into a reported failure.
        try { $this->updateLookup(hash('sha256',$raw),hash('sha256',$candidate),$keys,$q['total'],$receipt); } catch (Throwable $e) { /* derived cache is optional */ }
        return true;
    }
    private function publishShard(string $digest,string $segment): bool
    {
        foreach ([false,true] as $mirror) if ($this->shardRead($digest,$mirror)!==$segment && !Filesystem::atomicWrite($this->path(self::shardName($digest,$mirror)),$segment,0600)) return false;
        return true;
    }
    private static function pageName(string $digest,bool $mirror=false): string
    { return 'restore-ledger-page-'.($mirror?'copy-':'').$digest.'.json'; }
    private function pageRead(string $digest,bool $mirror)
    {
        $raw=$this->privateRead(self::pageName($digest,$mirror),16384);
        return is_string($raw) && hash_equals($digest,hash('sha256',$raw))?$raw:false;
    }
    private function publishPage(string $digest,string $raw): bool
    {
        foreach ([false,true] as $mirror) if ($this->pageRead($digest,$mirror)!==$raw && !Filesystem::atomicWrite($this->path(self::pageName($digest,$mirror)),$raw,0600)) return false;
        return true;
    }
    // The root pins immutable authenticated pages, which pin the existing
    // immutable receipt shards. Publish both copies before publishing the root.
    private function sealIndex(array $index,string $key,bool $publish=true,bool $force=false)
    {
        if ($index['records']<self::SHARD_SIZE) return self::sealWithKey(['schema'=>1,'active'=>$index['active'],'history'=>$index['tail']],'ledger-v1',$key);
        if (!$force && count($index['shards'])<self::INDEX_THRESHOLD) return self::sealWithKey($index,'ledger-index-v2',$key);
        $pages=[];
        foreach (array_chunk($index['shards'],self::INDEX_PAGE_SIZE) as $i=>$shards) {
            $raw=self::sealWithKey(['schema'=>1,'start'=>$i*self::INDEX_PAGE_SIZE,'shards'=>$shards],'ledger-page-v3',$key);
            $digest=hash('sha256',$raw);
            if (strlen($raw)>16384 || ($publish && !$this->publishPage($digest,$raw))) return false;
            $pages[]=$digest;
        }
        return self::sealWithKey(['schema'=>3,'active'=>$index['active'],'records'=>$index['records'],'pages'=>$pages,'tail'=>$index['tail']],'ledger-index-v3',$key);
    }
    // Explicit maintenance migration; existing receipts, shard bytes, sequence
    // numbers and GC references are untouched. No destructive compaction.
    public function migrateHierarchy(): bool
    {
        if ($this->keyStatus()!=='active' || (new RestoreJournal(dirname($this->tmp)))->pending()) return false;
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys(); $q=$this->query();
        if (!is_string($raw) || $keys===false || $q===false || $q['active']!==null || $q['total']<self::SHARD_SIZE) return false;
        $index=$this->decodeIndex($raw,$keys); if ($index===false) return false;
        $candidate=$this->sealIndex($index,$keys[0],true,true);
        if (!is_string($candidate) || $this->privateRead(self::FILE,self::LIMIT)!==$raw || $this->keys()!==$keys || !$this->publishIndex($candidate)) return false;
        try { $this->updateLookup(hash('sha256',$raw),hash('sha256',$candidate),$keys,$q['total'],null); } catch (Throwable $e) { /* optional cache */ }
        return true;
    }
    private function publishIndex(string $raw): bool
    {
        return strlen($raw)<=self::LIMIT && Filesystem::atomicWrite($this->path(self::MIRROR),$raw,0600) && Filesystem::atomicWrite($this->path(self::FILE),$raw,0600);
    }

    public static function validReceipt($r,int $seq): bool
    {
            if (!is_array($r) || ($r['seq']??null)!==$seq || !is_string($r['id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$r['id']) ||
                !in_array($r['result']??null,['committed','rolled-back'],true) || !is_int($r['time']??null) || $r['time']<=0 ||
                !is_string($r['stage']??null) || !preg_match('/^restore-[a-f0-9]{32}$/D',$r['stage']) || ($r['databaseVerified']??null)!==true || ($r['contentVerified']??null)!==true || !is_string($r['marker']??null) || !preg_match('/^[a-f0-9]{64}$/D',$r['marker'])) return false;
            if ($r['result']==='committed' && (!is_array($r['garbage']??null) || !is_array($r['garbage']['id']??null) || count($r['garbage']['id'])!==3 ||
                !is_string($r['garbage']['digest']??null) || !preg_match('/^[a-f0-9]{64}$/D',$r['garbage']['digest']))) return false;
        return true;
    }
    public static function validArchiveProposal(array $proposal): bool
    {
        $history=$proposal['history']??null; $install=$proposal['installation']??null;
        if (!is_array($history) || !array_is_list($history) || count($history)>self::MAX_HISTORY || !is_string($install) || !preg_match('/^[a-f0-9]{64}$/D',$install)) return false;
        $ids=[];
        foreach ($history as $i=>$r) {
            if (!self::validReceipt($r,$i+1) || isset($ids[$r['id']])) return false;
            $ids[$r['id']]=true;
        }
        return true;
    }
    public function read()
    {
        $q=$this->query(null,[],null,true);
        return $q===false?false:['schema'=>1,'active'=>$q['active'],'history'=>$q['history']];
    }

    // Sealed full segments are immutable, content-addressed, and duplicated.
    // The primary index pins the exact bytes; only a matching shard copy may
    // be substituted. The mutable tail and active transaction remain atomic.
    private static function shardName(string $digest,bool $mirror=false): string
    {
        return 'restore-ledger-shard-'.($mirror?'copy-':'').$digest.'.json';
    }
    private function shardRead(string $digest,bool $mirror)
    {
        $raw=$this->privateRead(self::shardName($digest,$mirror),self::SHARD_LIMIT);
        return is_string($raw) && hash_equals($digest,hash('sha256',$raw)) ? $raw : false;
    }
    private function decodeIndex(string $raw,array $keys)
    {
        $legacy=self::openWithKeys($raw,'ledger-v1',$keys);
        if ($legacy!==false) {
            if (($legacy['schema']??null)!==1 || !array_key_exists('active',$legacy) || !is_array($legacy['history']??null) || !array_is_list($legacy['history']) || count($legacy['history'])>self::MAX_HISTORY) return false;
            return ['schema'=>2,'active'=>$legacy['active'],'records'=>count($legacy['history']),'shards'=>[],'tail'=>$legacy['history']];
        }
        $index=self::openWithKeys($raw,'ledger-index-v2',$keys);
        if ($index===false) {
            $root=self::openWithKeys($raw,'ledger-index-v3',$keys);
            if (!is_array($root) || array_keys($root)!==['schema','active','records','pages','tail'] || $root['schema']!==3 || !is_int($root['records']) || $root['records']<self::SHARD_SIZE || $root['records']>self::MAX_HISTORY || !is_array($root['pages']) || !array_is_list($root['pages']) || !is_array($root['tail']) || !array_is_list($root['tail']) || count($root['tail'])>=self::SHARD_SIZE) return false;
            $full=intdiv($root['records'],self::SHARD_SIZE);
            if (count($root['pages'])!==intdiv($full+self::INDEX_PAGE_SIZE-1,self::INDEX_PAGE_SIZE)) return false;
            $shards=[];
            foreach ($root['pages'] as $i=>$digest) {
                if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) return false;
                $page=$this->pageRead($digest,false); if ($page===false) $page=$this->pageRead($digest,true);
                if ($page===false) return false;
                $data=self::openWithKeys($page,'ledger-page-v3',$keys);
                if (!is_array($data) || array_keys($data)!==['schema','start','shards'] || $data['schema']!==1 || $data['start']!==$i*self::INDEX_PAGE_SIZE || !is_array($data['shards']) || !array_is_list($data['shards']) || count($data['shards'])!==min(self::INDEX_PAGE_SIZE,$full-count($shards))) return false;
                foreach ($data['shards'] as $d) $shards[]=$d;
            }
            $index=['schema'=>2,'active'=>$root['active'],'records'=>$root['records'],'shards'=>$shards,'tail'=>$root['tail']];
        }
        if (!is_array($index) || ($index['schema']??null)!==2 || !is_array($index['shards']??null) || !array_is_list($index['shards']) ||
            !is_array($index['tail']??null) || !array_is_list($index['tail']) || count($index['tail'])>=self::SHARD_SIZE || !array_key_exists('active',$index) ||
            !is_int($index['records']??null) || $index['records']>self::MAX_HISTORY || $index['records']<self::SHARD_SIZE ||
            count($index['shards'])*self::SHARD_SIZE+count($index['tail'])!==$index['records']) return false;
        foreach ($index['shards'] as $digest) if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) return false;
        return $index;
    }
    private function segmentEntries(string $digest,int $i,array $keys,?bool &$degraded=null,bool $checkCopies=false)
    {
        $segment=$this->shardRead($digest,false);
        if ($segment===false || $checkCopies) {
            $backup=$this->shardRead($digest,true);
            if ($segment===false || $backup===false) $degraded=true;
            if ($segment===false) $segment=$backup;
        }
        if ($segment===false) return false;
        $shard=self::openWithKeys($segment,'ledger-shard-v2',$keys);
        if (!is_array($shard) || ($shard['schema']??null)!==1 || ($shard['start']??null)!==$i*self::SHARD_SIZE+1 ||
            !is_array($shard['entries']??null) || !array_is_list($shard['entries']) || count($shard['entries'])!==self::SHARD_SIZE) return false;
        return $shard['entries'];
    }
    // Re-sign one immutable segment at a time. The old primary remains
    // authoritative until all replacement segments and the mirror are durable
    // to the extent provided by Filesystem::atomicWrite. Dual-key ring permits retry.
    private function republishWithActiveKey(): bool
    {
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        $q=$this->query();
        if (!is_string($raw) || $keys===false || $q===false || $q['active']!==null || $this->privateRead(self::FILE,self::LIMIT)!==$raw) return false;
        $index=$this->decodeIndex($raw,$keys); if ($index===false) return false;
        foreach ($index['shards'] as $i=>$digest) {
            $entries=$this->segmentEntries($digest,$i,$keys); if ($entries===false) return false;
            $segment=self::sealWithKey(['schema'=>1,'start'=>$i*self::SHARD_SIZE+1,'entries'=>$entries],'ledger-shard-v2',$keys[0]);
            $new=hash('sha256',$segment);
            if (strlen($segment)>self::SHARD_LIMIT || !$this->publishShard($new,$segment)) return false;
            $index['shards'][$i]=$new;
            unset($entries,$segment);
        }
        // Migrate a legacy monolithic envelope while re-signing.
        while (count($index['tail'])>=self::SHARD_SIZE) {
            $entries=array_slice($index['tail'],0,self::SHARD_SIZE);
            $segment=self::sealWithKey(['schema'=>1,'start'=>count($index['shards'])*self::SHARD_SIZE+1,'entries'=>$entries],'ledger-shard-v2',$keys[0]);
            $new=hash('sha256',$segment);
            if (strlen($segment)>self::SHARD_LIMIT || !$this->publishShard($new,$segment)) return false;
            $index['shards'][]=$new; $index['tail']=array_slice($index['tail'],self::SHARD_SIZE);
        }
        $candidate=$this->sealIndex($index,$keys[0],true,self::openWithKeys($raw,'ledger-index-v3',$keys)!==false);
        return is_string($candidate) && $this->privateRead(self::FILE,self::LIMIT)===$raw && $this->keys()===$keys && $this->publishIndex($candidate);
    }

    // Non-destructive export in the existing anchor-proposal JSON format.
    // Buffer to a private temporary stream so a corrupt late segment cannot
    // emit a superficially valid partial document. Call under the restore gate.
    public function exportHistory($out,bool $segmented=false): bool
    {
        if (!is_resource($out) || $this->keyStatus()!=='active') return false;
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys(); $ring=$this->keyState(); $anchor=$this->loadCheckpoint();
        $q=$this->query();
        if (!is_string($raw) || $keys===false || $ring===false || $q===false || $q['active']!==null || $this->privateRead(self::FILE,self::LIMIT)!==$raw) return false;
        $index=$this->decodeIndex($raw,$keys); if ($index===false) return false;
        $spool=fopen('php://temp/maxmemory:262144','w+b'); if ($spool===false) return false;
        $write=static function(string $bytes) use ($spool): bool { return fwrite($spool,$bytes)===strlen($bytes); };
        try {
            if (!$write($segmented?self::encode(['schema'=>2,'installation'=>$ring['installation']])."\n":'{"installation":'.json_encode($ring['installation'],JSON_THROW_ON_ERROR).',"history":[')) return false;
            $seq=0;
            for ($i=0;$i<=count($index['shards']);$i++) {
                $entries=$i===count($index['shards'])?$index['tail']:$this->segmentEntries($index['shards'][$i],$i,$keys);
                if ($entries===false) return false;
                foreach ($entries as $r) {
                    ++$seq;
                    if (!self::validReceipt($r,$seq) || !$write($segmented?self::encode($r)."\n":($seq===1?'':',').self::encode($r))) return false;
                }
                unset($entries);
            }
            if ($seq!==$q['total'] || !$write($segmented?self::encode(['end'=>$seq])."\n":"]}\n") || $this->privateRead(self::FILE,self::LIMIT)!==$raw || $this->keys()!==$keys || $this->loadCheckpoint()!==$anchor || !rewind($spool)) return false;
            $stat=fstat($spool);
            return is_array($stat) && stream_copy_to_stream($spool,$out)===$stat['size'];
        } finally { fclose($spool); }
    }

    private function encodeState(array $state)
    {
        $key=$this->key(); if ($key===false || count($state['history'])>self::MAX_HISTORY) return false;
        $count=count($state['history']);
        if ($count<self::SHARD_SIZE) return self::sealWithKey($state,'ledger-v1',$key);
        $shards=[]; $full=intdiv($count,self::SHARD_SIZE);
        for ($i=0;$i<$full;$i++) {
            $segment=self::sealWithKey(['schema'=>1,'start'=>$i*self::SHARD_SIZE+1,'entries'=>array_slice($state['history'],$i*self::SHARD_SIZE,self::SHARD_SIZE)],'ledger-shard-v2',$key);
            if (strlen($segment)>self::SHARD_LIMIT) return false;
            $digest=hash('sha256',$segment);
            // Publish both exact copies before an index references the shard.
            // Existing immutable shards are never rewritten if they already match.
            if (!$this->publishShard($digest,$segment)) return false;
            $shards[]=$digest;
        }
        return $this->sealIndex(['schema'=>2,'active'=>$state['active'],'records'=>$count,'shards'=>$shards,
            'tail'=>array_slice($state['history'],$full*self::SHARD_SIZE)],$key,true,($raw=$this->privateRead(self::FILE,self::LIMIT))!==false && $this->open($raw,'ledger-index-v3')!==false);
    }

    /** Optional operator-managed checkpoint, stored independently of the content root.
     * Pins a finalized history prefix, not the latest active state. The deployment
     * must protect the file and environment independently from the CMS account. */
    public function checkpoint(array $state, ?int $seq=null): array
    {
        $seq=$seq??count($state['history']);
        if ($seq<0 || $seq>count($state['history'])) throw new InvalidArgumentException('Invalid checkpoint sequence');
        $ring=$this->keyState(); if ($ring===false) throw new RuntimeException('Missing recovery key');
        return ['schema'=>1,'installation'=>$ring['installation'],'seq'=>$seq,
            'prefix'=>self::historyPrefix($state['history'],$seq)];
    }
    public function checkpointSummary()
    {
        $ring=$this->keyState(); if ($ring===false) return false;
        $ctx=hash_init('sha256'); hash_update($ctx,'['); $seq=0;
        $q=$this->query(null,[],null,false,false,static function(array $r) use ($ctx,&$seq): bool {
            if ($seq++) hash_update($ctx,','); hash_update($ctx,self::encode($r)); return true;
        });
        if ($q===false || $q['active']!==null || $seq!==$q['total'] || $this->keyState()!==$ring) return false;
        hash_update($ctx,']');
        return ['schema'=>1,'installation'=>$ring['installation'],'seq'=>$seq,'prefix'=>hash_final($ctx)];
    }
    public static function historyPrefix(array $history,int $seq): string
    {
        if ($seq<0 || $seq>count($history)) throw new InvalidArgumentException('Invalid checkpoint sequence');
        $ctx=hash_init('sha256'); hash_update($ctx,'[');
        for ($i=0;$i<$seq;$i++) { if ($i) hash_update($ctx,','); hash_update($ctx,self::encode($history[$i])); }
        hash_update($ctx,']'); return hash_final($ctx);
    }

    // Detached Ed25519 signature; the secret signing key is never needed by CMS.
    public static function verifyAnchor(string $raw,string $publicKey)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached') || !preg_match('/^[a-f0-9]{64}$/D',$publicKey)) return false;
        try {
            $env=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($env) || !is_array($env['checkpoint']??null) || !is_string($env['signature']??null) || !preg_match('/^[a-f0-9]{128}$/D',$env['signature'])) return false;
            $cp=$env['checkpoint'];
            if (array_keys($cp)!==['schema','installation','seq','prefix'] || $cp['schema']!==1 || !is_int($cp['seq']) || $cp['seq']<0 ||
                !is_string($cp['installation']) || !preg_match('/^[a-f0-9]{64}$/D',$cp['installation']) || !is_string($cp['prefix']) || !preg_match('/^[a-f0-9]{64}$/D',$cp['prefix'])) return false;
            return sodium_crypto_sign_verify_detached(hex2bin($env['signature']),"brendy-anchor-v1\0".self::encode($cp),hex2bin($publicKey))?$cp:false;
        } catch (Throwable $e) { return false; }
    }

    // Run only by the independent operator, under its own exclusive lock.
    // Full history is the consistency proof; do not sign a fork of an old prefix.
    public static function signAnchor(array $proposal,?string $previous,string $secret)
    {
        if (!function_exists('sodium_crypto_sign_detached') || !preg_match('/^[a-f0-9]{128}$/D',$secret)) return false;
        try {
            $history=$proposal['history']??null; $install=$proposal['installation']??null;
            if (!is_array($history) || !array_is_list($history) || count($history)>self::MAX_HISTORY || !is_string($install) || !preg_match('/^[a-f0-9]{64}$/D',$install)) return false;
            foreach ($history as $i=>$r) if (!is_array($r) || ($r['seq']??null)!==$i+1) return false;
            $key=hex2bin($secret); $public=bin2hex(sodium_crypto_sign_publickey_from_secretkey($key));
            if ($previous!==null) {
                $old=self::verifyAnchor($previous,$public);
                if ($old===false || $old['installation']!==$install || $old['seq']>count($history) || !hash_equals($old['prefix'],self::historyPrefix($history,$old['seq']))) return false;
            }
            $cp=['schema'=>1,'installation'=>$install,'seq'=>count($history),'prefix'=>self::historyPrefix($history,count($history))];
            
            $signed=self::encode(['checkpoint'=>$cp,'signature'=>bin2hex(sodium_crypto_sign_detached("brendy-anchor-v1\0".self::encode($cp),$key))]);
            return self::verifyAnchor($signed,$public)===$cp ? $signed : false;
        } catch (Throwable $e) { return false; }
    }

    // null means no configured anchor; false means configured but invalid.
    private function loadCheckpoint()
    {
        $file=getenv('BRENDY_RECOVERY_CHECKPOINT'); $pub=getenv('BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY');
        if ($file===false || $file==='') return $pub===false || $pub==='' ? null : false;
        $root=dirname($this->tmp); $real=realpath($file);
        if ($real===false || $real!==$file || $real===$root || str_starts_with($real,$root.DS) || is_link($file)) return false;
        $raw=Filesystem::readFileBounded($file,4096,1); if (!is_string($raw)) return false;
        try {
            $anchor=($pub!==false && $pub!=='')?self::verifyAnchor($raw,$pub):json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($anchor) || array_keys($anchor)!==['schema','installation','seq','prefix'] || $anchor['schema']!==1 ||
                !is_string($anchor['installation']) || !preg_match('/^[a-f0-9]{64}$/D',$anchor['installation']) || !is_int($anchor['seq']) || $anchor['seq']<0 ||
                !is_string($anchor['prefix']) || !preg_match('/^[a-f0-9]{64}$/D',$anchor['prefix'])) return false;
            return $anchor;
        } catch (Throwable $e) { return false; }
    }
    private function checkpointMatches(array $state): bool
    {
        $anchor=$this->loadCheckpoint(); if ($anchor===null) return true; if ($anchor===false) return false;
        try { return $anchor===$this->checkpoint($state,$anchor['seq']); } catch (Throwable $e) { return false; }
    }

    /** Primary is authoritative. A mirror may contain an uncommitted candidate.
     * Never promote it automatically; repair only from a valid primary under EX lock. */
    public function mirrorStatus(): string
    {
        $primary=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        $summary=$this->query(); if ($summary===false || !is_string($primary) || $keys===false || $this->privateRead(self::FILE,self::LIMIT)!==$primary) return 'primary-invalid';
        $raw=$this->privateRead(self::MIRROR,self::LIMIT);
        if ($raw===false) return 'missing-or-invalid';
        if ($raw!==$primary) {
            return $this->decodeIndex($raw,$keys)===false?'missing-or-invalid':'divergent';
        }
        $index=$this->decodeIndex($raw,$keys); if ($index===false) return 'primary-invalid';
        $root=self::openWithKeys($raw,'ledger-index-v3',$keys);
        if ($root!==false) foreach ($root['pages'] as $digest) if ($this->pageRead($digest,false)===false || $this->pageRead($digest,true)===false) return 'shard-degraded';
        foreach ($index['shards'] as $digest) if ($this->shardRead($digest,false)===false || $this->shardRead($digest,true)===false) return 'shard-degraded';
        return 'in-sync';
    }
    public function reconcileMirror(): bool
    {
        $raw=$this->privateRead(self::FILE,self::LIMIT); $keys=$this->keys();
        if (!is_string($raw) || $keys===false || $this->query()===false) return false;
        $index=$this->decodeIndex($raw,$keys); if ($index===false) return false;
        $root=self::openWithKeys($raw,'ledger-index-v3',$keys);
        if ($root!==false) foreach ($root['pages'] as $digest) {
            $page=$this->pageRead($digest,false); if ($page===false) $page=$this->pageRead($digest,true);
            if ($page===false || !$this->publishPage($digest,$page)) return false;
        }
        foreach ($index['shards'] as $digest) {
            $shard=$this->shardRead($digest,false); if ($shard===false) $shard=$this->shardRead($digest,true);
            if ($shard===false || !$this->publishShard($digest,$shard)) return false;
        }
        return $this->privateRead(self::FILE,self::LIMIT)===$raw && $this->keys()===$keys && Filesystem::atomicWrite($this->path(self::MIRROR),$raw,0600);
    }
    public function save(array $state): bool
    {
        if (!$this->checkpointMatches($state)) return false;
        $raw=$this->encodeState($state);
        // Write candidate first; primary publication is the sole commit point.
        return is_string($raw) && $this->publishIndex($raw);
    }
    public static function head(array $state): string
    {
        return $state['history'] ? self::digest($state['history'][count($state['history'])-1]) : str_repeat('0',64);
    }
}
