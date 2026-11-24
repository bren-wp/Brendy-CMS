<?php
if (PHP_SAPI!=='cli' || !defined('BRENDY')) { http_response_code(404); exit; }
/** Operator-side, append-only archive. All callers use a separately protected directory.
 * A signed manifest is the only publication point; unreferenced blobs are preserved. */
final class RecoverySegmentedArchive
{
    const MANIFEST='archive-segmented-current.json';
    const MAX_RECORDS=1000000;
    const CHUNK=256;
    const LINE=65536;
    const MAX_BLOB=16777216;
    const MAX_MANIFEST=2097152;
    private static function hex($v,int $n=64): bool { return is_string($v) && strlen($v)===$n && ctype_xdigit($v) && strtolower($v)===$v; }
    private static function directory(string $dir): bool { return realpath($dir)===$dir && is_dir($dir) && !is_link($dir) && (@fileperms($dir)&0777)===0700; }
    private static function read(string $path,int $limit) {
        $id=RestoreJournal::identity($path);
        if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
        $raw=Filesystem::readFileBounded($path,$limit,1);
        return is_string($raw) && RestoreJournal::identity($path)===$id?$raw:false;
    }
    private static function blob(string $dir,string $hash): string { return $dir.DS.'archive-segment-'.$hash.'.ndjson'; }
    // Domain-separated Merkle mountain range, ordered peaks committed with count.
    private static function push(array &$peaks,string $digest): void {
        $node=self::leafHash($digest); $level=0;
        while (isset($peaks[$level])) { $node=self::nodeHash($peaks[$level],$node); unset($peaks[$level++]); }
        $peaks[$level]=$node;
    }
    private static function root(array $peaks,int $count): string {
        krsort($peaks,SORT_NUMERIC); return hash('sha256',"brendy-archive-root-v2\0".$count."\0".implode('', $peaks));
    }
    private static function leafHash(string $digest): string { return hash('sha256',"brendy-archive-leaf-v2\0".hex2bin($digest)); }
    private static function nodeHash(string $left,string $right): string { return hash('sha256',"brendy-archive-node-v2\0".hex2bin($left).hex2bin($right)); }
    /** Inclusion in the MMR root of a verified signed manifest, not proof of a receipt inside a segment. */
    public static function inclusionProof(array $m,int $index) {
        $segments=$m['segments']??null;
        if (!is_array($segments) || !array_is_list($segments) || count($segments)>(int)ceil(self::MAX_RECORDS/self::CHUNK) || $index<0 || $index>=count($segments)) return false;
        $peaks=[]; $siblings=[];
        foreach ($segments as $i=>$digest) {
            if (!self::hex($digest)) return false;
            $node=self::leafHash($digest); $contains=$i===$index; $level=0;
            while (isset($peaks[$level])) {
                [$left,$has]=$peaks[$level];
                if ($has) $siblings[]=$node;
                elseif ($contains) $siblings[]=$left;
                $node=self::nodeHash($left,$node); $contains=$contains||$has; unset($peaks[$level++]);
            }
            $peaks[$level]=[$node,$contains];
        }
        $others=[]; $all=[];
        krsort($peaks,SORT_NUMERIC);
        foreach ($peaks as $level=>[$node,$has]) { $all[$level]=$node; if (!$has) $others[]=$node; }
        if (($m['root']??null)!==self::root($all,count($segments))) return false;
        return ['count'=>count($segments),'index'=>$index,'digest'=>$segments[$index],'siblings'=>$siblings,'peaks'=>$others];
    }
    public static function verifyInclusion(array $p,string $root): bool {
        if (array_keys($p)!==['count','index','digest','siblings','peaks'] || !self::hex($root) || !self::hex($p['digest']) || !is_int($p['count']) || $p['count']<1 || $p['count']>(int)ceil(self::MAX_RECORDS/self::CHUNK) || !is_int($p['index']) || $p['index']<0 || $p['index']>=$p['count'] || !is_array($p['siblings']) || !array_is_list($p['siblings']) || !is_array($p['peaks']) || !array_is_list($p['peaks'])) return false;
        foreach ([$p['siblings'],$p['peaks']] as $list) { if (count($list)>32) return false; foreach ($list as $h) if (!self::hex($h)) return false; }
        $offset=0; $other=0; $used=false; $peaks=[];
        for ($level=30;$level>=0;$level--) {
            $size=1<<$level; if (!($p['count']&$size)) continue;
            if ($p['index']>=$offset && $p['index']<$offset+$size) {
                if (count($p['siblings'])!==$level) return false;
                $node=self::leafHash($p['digest']); $relative=$p['index']-$offset;
                foreach ($p['siblings'] as $j=>$sibling) $node=($relative&(1<<$j))?self::nodeHash($sibling,$node):self::nodeHash($node,$sibling);
                $peaks[$level]=$node; $used=true;
            } else { if (!isset($p['peaks'][$other])) return false; $peaks[$level]=$p['peaks'][$other++]; }
            $offset+=$size;
        }
        return $used && $other===count($p['peaks']) && hash_equals($root,self::root($peaks,$p['count']));
    }
    /** Append proof at a complete-segment boundary. Partial tail replacement is not
     * append-only at the segment level; callers must use full receipt-prefix verification. */
    public static function consistencyProof(array $old,array $new) {
        if (!is_int($old['seq']??null) || $old['seq']%self::CHUNK!==0 || !is_int($new['seq']??null) || $old['seq']>$new['seq'] || ($old['installation']??null)!==($new['installation']??null) || !is_array($old['segments']??null) || !is_array($new['segments']??null) || array_slice($new['segments'],0,count($old['segments']))!==$old['segments']) return false;
        $peaks=[]; foreach ($old['segments'] as $h) { if (!self::hex($h)) return false; self::push($peaks,$h); }
        krsort($peaks,SORT_NUMERIC);
        $p=['oldCount'=>count($old['segments']),'newCount'=>count($new['segments']),'oldPeaks'=>array_values($peaks),'suffix'=>array_slice($new['segments'],count($old['segments']))];
        return self::verifyConsistency($p,$old['root'],$new['root'])?$p:false;
    }
    public static function verifyConsistency(array $p,string $oldRoot,string $newRoot): bool {
        if (array_keys($p)!==['oldCount','newCount','oldPeaks','suffix'] || !self::hex($oldRoot) || !self::hex($newRoot) || !is_int($p['oldCount']) || !is_int($p['newCount']) || $p['oldCount']<0 || $p['newCount']<$p['oldCount'] || $p['newCount']>(int)ceil(self::MAX_RECORDS/self::CHUNK) || !is_array($p['oldPeaks']) || !array_is_list($p['oldPeaks']) || count($p['oldPeaks'])>32 || !is_array($p['suffix']) || !array_is_list($p['suffix']) || count($p['suffix'])!==$p['newCount']-$p['oldCount']) return false;
        $peaks=[]; $i=0;
        for ($level=30;$level>=0;$level--) if ($p['oldCount']&(1<<$level)) {
            if (!self::hex($p['oldPeaks'][$i]??null)) return false; $peaks[$level]=$p['oldPeaks'][$i++];
        }
        if ($i!==count($p['oldPeaks']) || !hash_equals($oldRoot,self::root($peaks,$p['oldCount']))) return false;
        foreach ($p['suffix'] as $h) { if (!self::hex($h)) return false; self::push($peaks,$h); }
        return hash_equals($newRoot,self::root($peaks,$p['newCount']));
    }
    /** Partial-tail proof: the new boundary segment reveals at most 256
     * receipts. Its old prefix must reconstruct the old authenticated leaf.
     * Both roots, installation and record counts must come from trusted manifests. */
    public static function partialConsistencyProof(string $dir,array $old,array $new) {
        if (!self::directory($dir) || !is_int($old['seq']??null) || $old['seq']<1 || $old['seq']%self::CHUNK===0 || !is_int($new['seq']??null) || $new['seq']<$old['seq'] || !is_array($old['segments']??null) || !is_array($new['segments']??null)) return false;
        $full=intdiv($old['seq'],self::CHUNK);
        $digest=$new['segments'][$full]??null; if (!self::hex($digest)) return false;
        $raw=self::read(self::blob($dir,$digest),self::MAX_BLOB); if (!is_string($raw)) return false;
        $peaks=[]; foreach (array_slice($old['segments'],0,$full) as $h) { if (!self::hex($h)) return false; self::push($peaks,$h); }
        krsort($peaks,SORT_NUMERIC);
        $proof=['oldPeaks'=>array_values($peaks),'boundary'=>$raw,'suffix'=>array_slice($new['segments'],$full+1)];
        return self::verifyPartialConsistency($proof,$old,$new)?$proof:false;
    }
    public static function verifyPartialConsistency(array $p,array $old,array $new): bool {
        if (array_keys($p)!==['oldPeaks','boundary','suffix'] || !self::hex($old['root']??null) || !self::hex($new['root']??null) || !self::hex($old['installation']??null) || ($new['installation']??null)!==$old['installation'] || !is_int($old['seq']??null) || !is_int($new['seq']??null) || $old['seq']<1 || $old['seq']%self::CHUNK===0 || $new['seq']<$old['seq'] || $new['seq']>self::MAX_RECORDS || !is_array($p['oldPeaks']) || !array_is_list($p['oldPeaks']) || count($p['oldPeaks'])>32 || !is_string($p['boundary']) || strlen($p['boundary'])>self::MAX_BLOB || !is_array($p['suffix']) || !array_is_list($p['suffix'])) return false;
        $full=intdiv($old['seq'],self::CHUNK); $newCount=intdiv($new['seq']+self::CHUNK-1,self::CHUNK);
        if (count($p['suffix'])!==$newCount-$full-1) return false;
        $peaks=[]; $i=0;
        for ($level=30;$level>=0;$level--) if ($full&(1<<$level)) {
            if (!self::hex($p['oldPeaks'][$i]??null)) return false; $peaks[$level]=$p['oldPeaks'][$i++];
        }
        if ($i!==count($p['oldPeaks'])) return false;
        $lines=explode("\n",$p['boundary']); if (array_pop($lines)!=='' || count($lines)!==min(self::CHUNK,$new['seq']-$full*self::CHUNK)) return false;
        try {
            foreach ($lines as $i=>$line) if (strlen($line)>=self::LINE || !RecoveryLedger::validReceipt(json_decode($line,true,64,JSON_THROW_ON_ERROR),$full*self::CHUNK+$i+1)) return false;
        } catch (Throwable $e) { return false; }
        $prefix=implode("\n",array_slice($lines,0,$old['seq']%self::CHUNK))."\n";
        $prior=$peaks; self::push($prior,hash('sha256',$prefix));
        if (!hash_equals($old['root'],self::root($prior,$full+1))) return false;
        self::push($peaks,hash('sha256',$p['boundary']));
        foreach ($p['suffix'] as $h) { if (!self::hex($h)) return false; self::push($peaks,$h); }
        return hash_equals($new['root'],self::root($peaks,$newCount));
    }
    private static function manifest(string $raw,string $public) {
        if (!self::hex($public) || !function_exists('sodium_crypto_sign_verify_detached')) return false;
        $env=json_decode($raw,true,16,JSON_THROW_ON_ERROR); $m=$env['manifest']??null; $sig=$env['signature']??null;
        if (!is_array($m) || array_keys($m)!==['schema','installation','seq','prefix','root','segments'] || $m['schema']!==2 || !self::hex($m['installation']) || !self::hex($m['prefix']) || !self::hex($m['root']) || !self::hex($sig,128) || !is_int($m['seq']) || $m['seq']<0 || $m['seq']>self::MAX_RECORDS || !is_array($m['segments']) || !array_is_list($m['segments']) || count($m['segments'])!==(int)ceil($m['seq']/self::CHUNK)) return false;
        foreach ($m['segments'] as $digest) if (!self::hex($digest)) return false;
        return sodium_crypto_sign_verify_detached(hex2bin($sig),"brendy-archive-v2\0".RecoveryLedger::encode($m),hex2bin($public))?$m:false;
    }
    private static function line($in) {
        $line=fgets($in,self::LINE+1);
        if ($line===false || !str_ends_with($line,"\n")) return false;
        return json_decode($line,true,64,JSON_THROW_ON_ERROR);
    }
    /** Exact duplicate detection via private disk spools; one hash bucket in RAM at a time.
     * Bound worst-case memory even for adversarially concentrated IDs. */
    private static function uniqueAdd(array &$buckets,string $id): bool {
        $b=substr(hash('sha256',$id),0,2);
        if (!isset($buckets[$b])) { $f=tmpfile(); if ($f===false) return false; $buckets[$b]=$f; }
        return fwrite($buckets[$b],$id."\n")===33;
    }
    private static function uniqueCheck(array $buckets): bool {
        foreach ($buckets as $f) {
            if (!rewind($f)) return false; $seen=[];
            while (($id=fgets($f,34))!==false) {
                if (strlen($id)!==33 || isset($seen[$id]) || count($seen)>=65536) return false;
                $seen[$id]=true;
            }
            if (!feof($f)) return false;
        }
        return true;
    }
    private static function prefix($ctx): string { $copy=hash_copy($ctx); hash_update($copy,']'); return hash_final($copy); }
    private static function receipt($r,int $seq,$ctx,array &$buckets): bool {
        if (!RecoveryLedger::validReceipt($r,$seq) || !self::uniqueAdd($buckets,$r['id'])) return false;
        hash_update($ctx,($seq===1?'':',').RecoveryLedger::encode($r)); return true;
    }
    private static function checkCheckpoint(?string $raw,string $public,array $m) {
        if ($raw===null) return null;
        $cp=RecoveryLedger::verifyAnchor($raw,$public);
        return $cp!==false && $cp['installation']===$m['installation'] && $cp['seq']<=$m['seq']?$cp:false;
    }
    private static function verifyManifest(string $dir,array $m,?array $cp): bool {
        $ctx=hash_init('sha256'); hash_update($ctx,'['); $buckets=[]; $peaks=[]; $seq=0;
        try {
            if ($cp!==null && $cp['seq']===0 && !hash_equals($cp['prefix'],self::prefix($ctx))) return false;
            foreach ($m['segments'] as $i=>$digest) {
                $raw=self::read(self::blob($dir,$digest),self::MAX_BLOB);
                if (!is_string($raw) || !hash_equals($digest,hash('sha256',$raw))) return false;
                if (substr_count($raw,"\n")!==min(self::CHUNK,$m['seq']-$seq)) return false;
                $lines=explode("\n",$raw); if (array_pop($lines)!=='') return false;
                if (count($lines)!==min(self::CHUNK,$m['seq']-$seq)) return false;
                foreach ($lines as $line) {
                    if (strlen($line)>=self::LINE || !self::receipt(json_decode($line,true,64,JSON_THROW_ON_ERROR),++$seq,$ctx,$buckets)) return false;
                    if ($cp!==null && $seq===$cp['seq'] && !hash_equals($cp['prefix'],self::prefix($ctx))) return false;
                }
                self::push($peaks,$digest);
            }
            return $seq===$m['seq'] && hash_equals($m['prefix'],self::prefix($ctx)) && hash_equals($m['root'],self::root($peaks,count($m['segments']))) && self::uniqueCheck($buckets);
        } finally { foreach ($buckets as $f) fclose($f); }
    }
    public static function verify(string $dir,string $public,?string $checkpoint=null) {
        if (!self::directory($dir)) return false;
        try {
            $raw=self::read($dir.DS.self::MANIFEST,self::MAX_MANIFEST); $m=is_string($raw)?self::manifest($raw,$public):false;
            if ($m===false) return false;
            $cp=self::checkCheckpoint($checkpoint,$public,$m);
            return $cp!==false && self::verifyManifest($dir,$m,$cp) && self::read($dir.DS.self::MANIFEST,self::MAX_MANIFEST)===$raw?$m:false;
        } catch (Throwable $e) { return false; }
    }
    /** Publish a checkpoint directly from the independently verified segmented
     * archive. No monolithic proposal or CMS-held signing key is required. */
    public static function publishCheckpoint(string $dir,string $anchor,string $secret,bool $initialize): bool {
        if (!self::directory($dir) || !self::hex($secret,128) || !function_exists('sodium_crypto_sign_detached') || !self::directory(dirname($anchor)) || dirname($anchor)===$dir || str_starts_with(dirname($anchor),$dir.DS) || is_link($anchor)) return false;
        $lock=Filesystem::acquireLock($anchor.'.lock',LOCK_EX|LOCK_NB,0600); if ($lock===false) return false;
        try {
            $id=RestoreJournal::identity($anchor);
            if ($initialize!==($id===null) || (!$initialize && (!is_array($id) || $id[2]!==0100000))) return false;
            $previous=$initialize?null:Filesystem::readFileBounded($anchor,4096,1);
            if ($previous===false) return false;
            $key=hex2bin($secret); $public=bin2hex(sodium_crypto_sign_publickey_from_secretkey($key));
            $m=self::verify($dir,$public,$previous); if ($m===false) return false;
            $cp=['schema'=>1,'installation'=>$m['installation'],'seq'=>$m['seq'],'prefix'=>$m['prefix']];
            $raw=RecoveryLedger::encode(['checkpoint'=>$cp,'signature'=>bin2hex(sodium_crypto_sign_detached("brendy-anchor-v1\0".RecoveryLedger::encode($cp),$key))]);
            if (RecoveryLedger::verifyAnchor($raw,$public)!==$cp || RestoreJournal::identity($anchor)!==$id || (!$initialize && Filesystem::readFileBounded($anchor,4096,1)!==$previous)) return false;
            return Filesystem::atomicWrite($anchor,$raw,0644);
        } catch (Throwable $e) { return false; }
        finally { Filesystem::releaseLock($lock); }
    }
    /** Read-only retention inventory. A separately retained checkpoint must match
     * current history exactly. Preserve all segments referenced by any signed snapshot. */
    public static function retentionPlan(string $dir,string $public,string $checkpoint) {
        if (!self::directory($dir)) return false;
        $lock=Filesystem::acquireLock($dir.DS.'archive-segmented.lock',LOCK_EX|LOCK_NB,0600); if ($lock===false) return false;
        try { return self::retentionInventory($dir,$public,$checkpoint); } finally { Filesystem::releaseLock($lock); }
    }
    private static function retentionInventory(string $dir,string $public,string $checkpoint) {
        if (!self::directory($dir)) return false;
        try {
            $m=self::verify($dir,$public,$checkpoint); $cp=RecoveryLedger::verifyAnchor($checkpoint,$public);
            if ($m===false || $cp===false || $m['seq']!==$cp['seq'] || !hash_equals($m['prefix'],$cp['prefix'])) return false;
            $refs=array_fill_keys($m['segments'],true); $snapshots=0; $files=0;
            foreach (new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$files>100000) return false;
                $name=$entry->getFilename();
                if (!preg_match('/^archive-manifest-([a-f0-9]{64})\.json$/D',$name,$match)) continue;
                $raw=self::read($entry->getPathname(),self::MAX_MANIFEST);
                if (!is_string($raw) || !hash_equals($match[1],hash('sha256',$raw))) return false;
                $old=self::manifest($raw,$public);
                if ($old===false || $old['installation']!==$m['installation'] || $old['seq']>$m['seq']) return false;
                ++$snapshots;
                foreach ($old['segments'] as $digest) { $refs[$digest]=true; if (count($refs)>100000) return false; }
            }
            $candidates=[]; $now=time();
            foreach (new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $entry) {
                $name=$entry->getFilename();
                if (!preg_match('/^archive-segment-([a-f0-9]{64})\.ndjson$/D',$name,$match) || isset($refs[$match[1]]) || $entry->isLink() || $entry->getMTime()>$now-86400) continue;
                $identity=RestoreJournal::identity($entry->getPathname());
                $raw=self::read($entry->getPathname(),self::MAX_BLOB);
                if (!is_array($identity) || !is_string($raw) || RestoreJournal::identity($entry->getPathname())!==$identity || !hash_equals($match[1],hash('sha256',$raw))) continue;
                if (count($candidates)>=10000) return false;
                $candidates[]=['file'=>$name,'sha256'=>$match[1],'bytes'=>strlen($raw),'identity'=>$identity];
            }
            usort($candidates,static fn($a,$b)=>strcmp($a['file'],$b['file']));
            return ['mode'=>'report-only','sequence'=>$m['seq'],'protectedSegments'=>count($refs),'signedSnapshots'=>$snapshots,'unreferencedCandidates'=>$candidates];
        } catch (Throwable $e) { return false; }
    }
    const GC_JOURNAL='archive-gc-journal.json';
    private static function gcSeal(array $state,string $key): string {
        return RecoveryLedger::encode(['state'=>$state,'signature'=>bin2hex(sodium_crypto_sign_detached("brendy-archive-gc-v1\0".RecoveryLedger::encode($state),$key))]);
    }
    private static function gcOpen(string $raw,string $public) {
        $env=json_decode($raw,true,32,JSON_THROW_ON_ERROR); $state=$env['state']??null; $sig=$env['signature']??null;
        if (!is_array($state) || !self::hex($sig,128) || !sodium_crypto_sign_verify_detached(hex2bin($sig),"brendy-archive-gc-v1\0".RecoveryLedger::encode($state),hex2bin($public))) return false;
        if (array_keys($state)!==['schema','manifest','checkpoint','rootIdentity','items','cursor'] || $state['schema']!==1 || !self::hex($state['manifest']) || !self::hex($state['checkpoint']) || !is_array($state['rootIdentity']) || !is_array($state['items']) || !array_is_list($state['items']) || count($state['items'])>1000 || !is_int($state['cursor']) || $state['cursor']<0 || $state['cursor']>count($state['items'])) return false;
        $last='';
        foreach ($state['items'] as $item) {
            if (!is_array($item) || array_keys($item)!==['file','sha256','bytes','identity'] || !self::hex($item['sha256']) || $item['file']!=='archive-segment-'.$item['sha256'].'.ndjson' || strcmp($last,$item['file'])>=0 || !is_int($item['bytes']) || $item['bytes']<1 || $item['bytes']>self::MAX_BLOB || !is_array($item['identity']) || count($item['identity'])!==3 || $item['identity'][2]!==0100000) return false;
            $last=$item['file'];
        }
        return $state;
    }
    /** Explicit operator approval; digest binds the exact report-only inventory. A signed
     * write-ahead plan precedes unlink; signed cursor follows it. No snapshot is removed. */
    public static function garbageCollect(string $dir,string $secret,string $checkpoint,?string $approval=null,int $budget=100): bool {
        if (!self::directory($dir) || !self::hex($secret,128) || !function_exists('sodium_crypto_sign_detached') || $budget<1 || $budget>1000 || ($approval!==null && !self::hex($approval))) return false;
        $lock=Filesystem::acquireLock($dir.DS.'archive-segmented.lock',LOCK_EX|LOCK_NB,0600); if ($lock===false) return false;
        try {
            $key=hex2bin($secret); $public=bin2hex(sodium_crypto_sign_publickey_from_secretkey($key)); $root=RestoreJournal::identity($dir);
            $inventory=self::retentionInventory($dir,$public,$checkpoint); if ($inventory===false) return false;
            $manifest=self::read($dir.DS.self::MANIFEST,self::MAX_MANIFEST); if ($manifest===false) return false;
            $manifestHash=hash('sha256',$manifest); $cpHash=hash('sha256',$checkpoint);
            $path=$dir.DS.self::GC_JOURNAL; $existing=RestoreJournal::identity($path); $state=null;
            if ($existing!==null) {
                $raw=self::read($path,self::MAX_MANIFEST); $state=is_string($raw)?self::gcOpen($raw,$public):false; if ($state===false) return false;
            }
            if ($approval!==null) {
                if ($state!==null && $state['cursor']!==count($state['items'])) return false;
                if (!hash_equals($approval,RecoveryLedger::digest($inventory))) return false;
                $items=array_slice($inventory['unreferencedCandidates'],0,1000);
                if (!$items) return true;
                // Preserve the prior completed signed receipt before replacing the journal.
                if ($state!==null) {
                    $receipt=$dir.DS.'archive-gc-receipt-'.hash('sha256',$raw).'.json';
                    if (RestoreJournal::identity($receipt)===null && !Filesystem::atomicWrite($receipt,$raw,0600)) return false;
                    if (self::read($receipt,self::MAX_MANIFEST)!==$raw) return false;
                }
                $state=['schema'=>1,'manifest'=>$manifestHash,'checkpoint'=>$cpHash,'rootIdentity'=>$root,'items'=>$items,'cursor'=>0];
                $raw=self::gcSeal($state,$key);
                if (!Filesystem::atomicWrite($path,$raw,0600) || self::read($path,self::MAX_MANIFEST)!==$raw) return false;
            } elseif ($state===null) return false;
            if ($state['manifest']!==$manifestHash || $state['checkpoint']!==$cpHash || $state['rootIdentity']!==$root) return false;
            $available=array_column($inventory['unreferencedCandidates'],null,'file');
            // Recheck already completed items. A recreated pathname is never deleted.
            for ($i=0;$i<$state['cursor'];$i++) if (RestoreJournal::identity($dir.DS.$state['items'][$i]['file'])!==null) return false;
            $end=min(count($state['items']),$state['cursor']+$budget);
            while ($state['cursor']<$end) {
                $item=$state['items'][$state['cursor']]; $target=$dir.DS.$item['file']; $id=RestoreJournal::identity($target);
                if (RestoreJournal::identity($dir)!==$root || self::read($dir.DS.self::MANIFEST,self::MAX_MANIFEST)!==$manifest || self::read($path,self::MAX_MANIFEST)!==$raw) return false;
                if ($id!==null) {
                    if ($id!==$item['identity'] || ($available[$item['file']]??null)!==$item) return false;
                    $bytes=self::read($target,self::MAX_BLOB);
                    if (!is_string($bytes) || strlen($bytes)!==$item['bytes'] || !hash_equals($item['sha256'],hash('sha256',$bytes)) || RestoreJournal::identity($target)!==$id || RestoreJournal::identity($dir)!==$root || !unlink($target)) return false;
                }
                if (RestoreJournal::identity($target)!==null) return false;
                $state['cursor']++; $raw=self::gcSeal($state,$key);
                if (!Filesystem::atomicWrite($path,$raw,0600) || self::read($path,self::MAX_MANIFEST)!==$raw) return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
        finally { Filesystem::releaseLock($lock); }
    }
    private static function publishBlob(string $dir,string $raw,array &$segments,array &$peaks): bool {
        if (strlen($raw)>self::MAX_BLOB) return false;
        $digest=hash('sha256',$raw); $path=self::blob($dir,$digest);
        if (RestoreJournal::identity($path)===null && !Filesystem::atomicWrite($path,$raw,0600)) return false;
        if (self::read($path,self::MAX_BLOB)!==$raw) return false;
        $segments[]=$digest; self::push($peaks,$digest); return true;
    }
    /** Full, verified NDJSON proposal; trailer makes truncated streams unpublishable. */
    public static function publish(string $dir,$in,string $secret,bool $initialize,?string $checkpoint=null): bool {
        if (!self::directory($dir) || !is_resource($in) || !self::hex($secret,128) || !function_exists('sodium_crypto_sign_detached')) return false;
        $lock=Filesystem::acquireLock($dir.DS.'archive-segmented.lock',LOCK_EX|LOCK_NB,0600); if ($lock===false) return false;
        $buckets=[];
        try {
            $key=hex2bin($secret); $public=bin2hex(sodium_crypto_sign_publickey_from_secretkey($key));
            $gcPath=$dir.DS.self::GC_JOURNAL;
            if (RestoreJournal::identity($gcPath)!==null) {
                $gcRaw=self::read($gcPath,self::MAX_MANIFEST); $gc=is_string($gcRaw)?self::gcOpen($gcRaw,$public):false;
                if ($gc===false || $gc['cursor']!==count($gc['items'])) return false;
            }
            $path=$dir.DS.self::MANIFEST; $identity=RestoreJournal::identity($path);
            if ($initialize!==($identity===null)) return false;
            // Initial publication only in an empty directory (or retry of uncommitted blobs).
            if ($initialize) foreach (new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $entry) {
                $n=$entry->getFilename(); if ($n==='archive-segmented.lock') continue;
                if ($checkpoint===null || (!preg_match('/^archive-segment-[a-f0-9]{64}\.ndjson$/D',$n) && !str_starts_with($n,'.brendy-write-'))) return false;
            }
            $oldRaw=$initialize?null:self::read($path,self::MAX_MANIFEST); $old=$initialize?null:self::verify($dir,$public);
            if ($old===false || $oldRaw===false) return false;
            $header=self::line($in); if (!is_array($header) || array_keys($header)!==['schema','installation'] || $header['schema']!==2 || !self::hex($header['installation']) || ($old!==null && $old['installation']!==$header['installation'])) return false;
            $ctx=hash_init('sha256'); hash_update($ctx,'['); $seq=0; $segments=[]; $peaks=[]; $chunk='';
            $cp=$checkpoint===null?null:RecoveryLedger::verifyAnchor($checkpoint,$public);
            if ($cp===false || ($cp!==null && $cp['installation']!==$header['installation'])) return false;
            foreach ([$old,$cp] as $bound) if ($bound!==null && $bound['seq']===0 && !hash_equals($bound['prefix'],self::prefix($ctx))) return false;
            while (true) {
                $r=self::line($in); if (!is_array($r)) return false;
                if (array_key_exists('end',$r)) {
                    if ($r!==['end'=>$seq] || fgetc($in)!==false || !feof($in)) return false;
                    break;
                }
                if (++$seq>self::MAX_RECORDS || !self::receipt($r,$seq,$ctx,$buckets)) return false;
                foreach ([$old,$cp] as $bound) if ($bound!==null && $seq===$bound['seq'] && !hash_equals($bound['prefix'],self::prefix($ctx))) return false;
                $chunk.=RecoveryLedger::encode($r)."\n";
                if ($seq%self::CHUNK===0) { if (!self::publishBlob($dir,$chunk,$segments,$peaks)) return false; $chunk=''; }
            }
            if (($old!==null && $seq<$old['seq']) || ($cp!==null && $seq<$cp['seq']) || !self::uniqueCheck($buckets)) return false;
            if ($chunk!=='' && !self::publishBlob($dir,$chunk,$segments,$peaks)) return false;
            $m=['schema'=>2,'installation'=>$header['installation'],'seq'=>$seq,'prefix'=>self::prefix($ctx),'root'=>self::root($peaks,count($segments)),'segments'=>$segments];
            $signed=RecoveryLedger::encode(['manifest'=>$m,'signature'=>bin2hex(sodium_crypto_sign_detached("brendy-archive-v2\0".RecoveryLedger::encode($m),$key))]);
            if (strlen($signed)>self::MAX_MANIFEST || self::manifest($signed,$public)!==$m || RestoreJournal::identity($path)!==$identity || (!$initialize && self::read($path,self::MAX_MANIFEST)!==$oldRaw)) return false;
            // Preserve prior signed manifests by digest before switching the current pointer.
            if (is_string($oldRaw)) {
                $snapshot=$dir.DS.'archive-manifest-'.hash('sha256',$oldRaw).'.json';
                if (RestoreJournal::identity($snapshot)===null && !Filesystem::atomicWrite($snapshot,$oldRaw,0600)) return false;
                if (self::read($snapshot,self::MAX_MANIFEST)!==$oldRaw) return false;
            }
            return Filesystem::atomicWrite($path,$signed,0600) && self::read($path,self::MAX_MANIFEST)===$signed;
        } catch (Throwable $e) { return false; }
        finally { foreach ($buckets as $f) fclose($f); Filesystem::releaseLock($lock); }
    }
}
