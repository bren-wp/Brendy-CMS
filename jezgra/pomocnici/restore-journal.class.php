<?php defined('BRENDY') or die('Brendy CMS.');

require_once __DIR__ . '/recovery-ledger.class.php';

/** Write-ahead restore plan. All paths are derived from a trusted content root,
 * never from absolute paths in the journal. Caller holds the exclusive request gate.
 * Same-filesystem rename preserves identity across a killed PHP process. */
final class RestoreJournal
{
    const FILE = 'restore-transaction.json';
    const RECEIPT = 'restore-last-result.json';
    private const LIMIT = 4194304;
    private $root;
    private $tmp;
    private RecoveryLedger $ledger;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->tmp = $this->root . DS . 'tmp';
        $this->ledger = new RecoveryLedger($this->tmp);
    }

    public static function identity(string $path)
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        if (!is_array($s)) return null;
        $type = $s['mode'] & 0170000;
        if (!in_array($type, [0040000, 0100000], true) || ($type === 0100000 && $s['nlink'] !== 1) || $s['ino'] <= 0) return false;
        return [$s['dev'], $s['ino'], $type];
    }

    private static function validIdentity($id, $device = null, $directory = false): bool
    {
        return is_array($id) && array_keys($id) === [0,1,2] && is_int($id[0]) && is_int($id[1]) && $id[1] > 0 &&
            ($device === null || $id[0] === $device) && in_array($id[2], $directory ? [0040000] : [0040000,0100000], true);
    }

    private static function validName($name): bool
    {
        return is_string($name) && $name !== '' && strlen($name) <= 255 && !in_array(strtolower($name), ['.','..','tmp'], true) &&
            strpos($name, chr(92)) === false && !preg_match('~[\x00-\x1f\x7f/:]~', $name) && preg_match('//u', $name) === 1 &&
            rtrim($name, '. ') === $name;
    }

    private function scan(string $dir, bool $skipTmp = false)
    {
        $list = @scandir($dir);
        if (!is_array($list) || count($list) > 20002) return false;
        $out = [];
        foreach ($list as $name) {
            if ($name === '.' || $name === '..' || ($skipTmp && $name === 'tmp')) continue;
            if (!self::validName($name)) return false;
            $id = self::identity($dir . DS . $name);
            if (!self::validIdentity($id)) return false;
            $out[] = ['name'=>$name, 'id'=>$id];
        }
        return $out;
    }

    private function path(): string { return $this->tmp . DS . self::FILE; }
    public function pending(): bool
    {
        if (self::identity($this->path()) !== null) return true;
        if (!$this->ledger->exists()) return $this->authenticatedEvidence();
        $state=$this->ledger->query();
        return $state===false || $state['active']!==null;
    }

    private function authenticatedEvidence(): bool
    {
        $raw=Filesystem::readFileBounded($this->tmp.DS.self::RECEIPT,4096,1);
        if (!is_string($raw)) return false;
        $r=json_decode($raw,true,8);
        return is_array($r) && ($r['contentVerified']??null)===true;
    }

    private function paths(array $plan): array
    {
        return ['live'=>$this->root, 'tmp'=>$this->tmp,
            'container'=>$this->tmp . DS . $plan['stage'],
            'stage'=>$this->tmp . DS . $plan['stage'] . DS . 'sadrzaj',
            'old'=>$this->tmp . DS . 'backup-restore-rollback-' . $plan['id']];
    }

    private function rootsMatch(array $plan): bool
    {
        foreach ($this->paths($plan) as $key=>$path) {
            if (self::identity($path) !== $plan['roots'][$key]) return false;
        }
        return true;
    }

    private function valid(array $plan): bool
    {
        if (!in_array($plan['schema'] ?? null, [1,2,3], true) || !in_array($plan['state'] ?? null, ['prepared','committed'], true) ||
            !is_string($plan['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $plan['id']) ||
            !is_string($plan['stage'] ?? null) || !preg_match('/^restore-[a-f0-9]{32}$/D', $plan['stage']) ||
            !is_array($plan['roots'] ?? null) || !self::validIdentity($plan['roots']['live'] ?? null, null, true)) return false;
        $device = $plan['roots']['live'][0];
        foreach (['live','tmp','container','stage','old'] as $key) if (!self::validIdentity($plan['roots'][$key] ?? null, $device, true)) return false;
        $all = [];
        foreach (['old','new'] as $side) {
            if (!is_array($plan[$side] ?? null) || !array_is_list($plan[$side]) || count($plan[$side]) > 20000) return false;
            $names = [];
            foreach ($plan[$side] as $item) {
                if (!is_array($item) || !self::validName($item['name'] ?? null) || !self::validIdentity($item['id'] ?? null, $device)) return false;
                $name = strtolower($item['name']); $identity = implode(':', $item['id']);
                if (isset($names[$name]) || isset($all[$identity])) return false;
                $names[$name] = true; $all[$identity] = true;
            }
        }
        // A case-only rename is ambiguous on case-insensitive hosts.
        $names = [];
        foreach (array_merge($plan['old'], $plan['new']) as $entry) {
            $key = strtolower($entry['name']);
            if (isset($names[$key]) && $names[$key] !== $entry['name']) return false;
            $names[$key] = $entry['name'];
        }
        if ($plan['schema'] >= 2) {
            foreach (['oldDigest','newDigest'] as $field) {
                if (!is_string($plan[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $plan[$field])) return false;
            }
        }
        if ($plan['schema'] === 3) {
            if (!is_int($plan['seq']??null) || $plan['seq']<1 || !is_string($plan['head']??null) || !preg_match('/^[a-f0-9]{64}$/D',$plan['head']) ||
                !is_string($plan['marker']??null) || !preg_match('/^[a-f0-9]{64}$/D',$plan['marker'])) return false;
            foreach (['old','new'] as $side) foreach ($plan[$side] as $entry) {
                if (!is_string($entry['digest']??null) || !preg_match('/^[a-f0-9]{64}$/D',$entry['digest']) || ($side==='new' && (!is_string($entry['immutable']??null) || !preg_match('/^[a-f0-9]{64}$/D',$entry['immutable'])))) return false;
            }
        }
        return true;
    }

    // Digest the complete database subtree, independently of top-level inode checks.
    // Workspaces and other content remain subject to the existing identity checks.
    private function databaseDigest(string $directory)
    {
        $path = $directory . DS . 'databases';
        $before = self::identity($path);
        if (!self::validIdentity($before, null, true)) return false;
        $digest = Filesystem::treeDigest($path, 1073741824, 100000);
        return is_array($digest) && self::identity($path) === $before ? $digest['hash'] : false;
    }

    private function oldDatabaseMatches(array $plan): bool
    {
        if ($plan['schema'] !== 2) return true;
        $paths = $this->paths($plan);
        foreach ($plan['old'] as $entry) {
            if ($entry['name'] !== 'databases') continue;
            foreach (['old','live'] as $where) {
                if (self::identity($paths[$where] . DS . 'databases') === $entry['id']) {
                    return $this->databaseDigest($paths[$where]) === $plan['oldDigest'];
                }
            }
        }
        return false;
    }

    // Compatibility aliases point to canonical UUID directories under uploads/pages.
    // Hash the link text, never follow it. In a moved rollback tree the absolute
    // target still names the live root; verify the corresponding UUID locally.
    private function aliasDigest(string $path,array $budget)
    {
        $base=$budget['base']??'';
        if ($base==='' || !str_starts_with($path,$base.DS)) return false;
        $relative=str_replace(DS,'/',substr($path,strlen($base)+1));
        $uuid='(?:autosave-)?[a-fA-F0-9]{32}';
        if (!preg_match('~^uploads/pages/(?:'.$uuid.'/)?[\pL\pN._\x7e-]+$~uD',$relative)) return false;
        clearstatcache(true,$path); $before=@lstat($path); $target=@readlink($path);
        $prefix=$this->root.DS.'uploads'.DS.'pages'.DS;
        if (!is_array($before) || ($before['mode']&0170000)!==0120000 || $before['dev']!==$budget['device'] || !is_string($target) || !str_starts_with($target,$prefix)) return false;
        $name=substr($target,strlen($prefix));
        if (!preg_match('~^'.$uuid.'$~D',$name)) return false;
        $local=$base.DS.'uploads'.DS.'pages'.DS.$name;
        if (!self::validIdentity(self::identity($local),$budget['device'],true) || realpath($local)!==$local) return false;
        clearstatcache(true,$path); $after=@lstat($path);
        return is_array($after) && $before['dev']===$after['dev'] && $before['ino']===$after['ino'] && ($after['mode']&0170000)===0120000 && @readlink($path)===$target ? hash('sha256','L:'.$target) : false;
    }

    private function generatedPath(string $path,array $budget): bool
    {
        $relative=str_replace(DS,'/',substr($path,strlen($budget['base'])+1));
        return in_array($relative,['databases/.htaccess','pages/.htaccess','uploads/.htaccess','workspaces/.htaccess','revisions/.htaccess',
            'databases/security.php','databases/security.php.write.lock','databases/core-integrity.php'],true);
    }

    private function entryDigest(string $path, array &$budget)
    {
        if (--$budget['entries']<0) return false;
        if (is_link($path)) {
            $id=self::gcIdentity($path);
            $hash=$this->aliasDigest($path,$budget);
            return $hash!==false && $this->captureGcEntry($path,$budget,$hash,$id) ? $hash : false;
        }
        $id=self::identity($path);
        if (!self::validIdentity($id,$budget['device']) || realpath($path)!==$path) return false;
        if ($id[2]===0100000) {
            clearstatcache(true,$path); $size=@filesize($path);
            if (!is_int($size) || $size<0 || $size>$budget['bytes']) return false;
            $budget['bytes']-=$size;
            $hash=Filesystem::hashFileBounded($path,'sha256',max(1,$size),0,$size);
            if (!is_string($hash) || self::identity($path)!==$id) return false;
            $digest=hash('sha256','F:'.$size.':'.$hash);
            return $this->captureGcEntry($path,$budget,$digest,$id) ? $digest : false;
        }
        if (++$budget['depth']>128) return false;
        $names=@scandir($path); if (!is_array($names) || count($names)>100002) return false;
        $ctx=hash_init('sha256'); hash_update($ctx,'D:');
        foreach ($names as $name) {
            if ($name==='.' || $name==='..') continue;
            // Nested tmp names are legal; reject ambiguous separators and controls.
            if (strlen($name)>255 || preg_match('~[\x00-\x1f\x7f/:]~',$name) || str_contains($name,chr(92)) || preg_match('//u',$name)!==1) return false;
            $child=$path.DS.$name;
            if (!empty($budget['immutable'])) {
                if (is_link($child)) { if ($this->aliasDigest($child,$budget)===false) return false; continue; }
                if ($this->generatedPath($child,$budget)) { if (!self::validIdentity(self::identity($child),$budget['device']) || !is_file($child)) return false; continue; }
            }
            $hash=$this->entryDigest($child,$budget);
            if ($hash===false) return false;
            hash_update($ctx,strlen($name).':'.$name.':'.$hash."\n");
        }
        --$budget['depth'];
        if (self::identity($path)!==$id || @scandir($path)!==$names) return false;
        $digest=hash_final($ctx);
        return $this->captureGcEntry($path,$budget,$digest,$id) ? $digest : false;
    }

    private function budget(int $device): array
    {
        return ['device'=>$device,'entries'=>100000,'bytes'=>1073741824,'depth'=>0];
    }

    private function digestSide(array $plan,string $side,array $locations,bool $capture=false,bool $immutable=false)
    {
        $paths=$this->paths($plan); $budget=$this->budget($plan['roots']['live'][0]); $budget['immutable']=$immutable; $out=[];
        $field=$immutable ? 'immutable' : 'digest';
        foreach ($plan[$side] as $entry) {
            $found=false;
            foreach ($locations as $where) {
                $path=$paths[$where].DS.$entry['name'];
                if (self::identity($path)!==$entry['id']) continue;
                $budget['base']=$paths[$where];
                $hash=$this->entryDigest($path,$budget);
                if ($hash===false || self::identity($path)!==$entry['id'] || (!$capture && !hash_equals($entry[$field],$hash))) return false;
                $entry[$field]=$hash; $out[]=$entry; $found=true; break;
            }
            if (!$found) return false;
        }
        return $this->rootsMatch($plan) ? $out : false;
    }

    private function marker(array $plan): array
    {
        unset($plan['marker']); return $plan;
    }

    private function loadMarker()
    {
        $id=self::identity($this->path());
        if (!self::validIdentity($id) || $id[2]!==0100000 || (@fileperms($this->path())&0777)!==0600) return false;
        $raw=Filesystem::readFileBounded($this->path(),self::LIMIT,2);
        if (!is_string($raw) || self::identity($this->path())!==$id) return false;
        $plan=$this->ledger->open($raw,'journal-v3');
        if (!is_array($plan)) return false;
        $plan['marker']=RecoveryLedger::digest($plan);
        return $this->valid($plan) && $plan['schema']===3 && $plan['state']==='prepared' && $this->rootsMatch($plan) ? $plan : false;
    }

    private function anchored(array $plan,array $state): bool
    {
        return $plan['seq']===$state['total']+1 && hash_equals($plan['head'],$state['last']===null?str_repeat('0',64):RecoveryLedger::digest($state['last']));
    }

    private function write(array $plan): bool
    {
        if ($plan['schema']===3) {
            $state=$this->ledger->query();
            if ($state===false || !is_array($state['active']) || $state['active']['marker']!==$plan['marker'] || !$this->anchored($plan,$state) || !$this->rootsMatch($plan)) return false;
            return $this->ledger->mutate($state['active'],$plan);
        }
        $raw = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return strlen($raw) <= self::LIMIT && $this->rootsMatch($plan) && Filesystem::atomicWrite($this->path(), $raw, 0600);
    }

    public function read()
    {
        if ($this->ledger->exists()) {
            $state=$this->ledger->query(); $marker=$this->loadMarker();
            if ($state===false || $marker===false || !is_array($state['active'])) return false;
            $plan=$state['active'];
            return $this->valid($plan) && $this->anchored($plan,$state) && $this->rootsMatch($plan) &&
                hash_equals($plan['marker'],$marker['marker']) && $plan['id']===$marker['id'] ? $plan : false;
        }
        if ($this->authenticatedEvidence()) return false;
        $id = self::identity($this->path());
        if (!self::validIdentity($id) || $id[2] !== 0100000) return false;
        clearstatcache(true, $this->path());
        if ((@fileperms($this->path()) & 0777) !== 0600) return false;
        $raw = Filesystem::readFileBounded($this->path(), self::LIMIT, 2);
        if (!is_string($raw) || self::identity($this->path()) !== $id) return false;
        try { $plan = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { return false; }
        return is_array($plan) && in_array($plan['schema']??null,[1,2],true) && $this->valid($plan) && $this->rootsMatch($plan) ? $plan : false;
    }

    public function prepare(string $stage)
    {
        if ($this->pending() || !preg_match('/^restore-[a-f0-9]{32}$/D', $stage)) return false;
        if (!$this->ledger->initialize() || $this->ledger->keyStatus()!=='active') return false;
        $state=$this->ledger->query();
        if ($state===false || $state['active']!==null || $state['total']>=RecoveryLedger::MAX_HISTORY) return false;
        $plan = ['schema'=>3,'seq'=>$state['total']+1,'head'=>$state['last']===null?str_repeat('0',64):RecoveryLedger::digest($state['last']),'state'=>'prepared','id'=>bin2hex(random_bytes(16)),'stage'=>$stage,'roots'=>[]];
        $paths = $this->paths($plan);
        foreach (['live','tmp','container','stage'] as $key) {
            $plan['roots'][$key] = self::identity($paths[$key]);
            if (!self::validIdentity($plan['roots'][$key], $plan['roots']['live'][0] ?? null, true)) return false;
        }
        if (!@mkdir($paths['old'], 0700)) return false;
        $plan['roots']['old'] = self::identity($paths['old']);
        $plan['old'] = $this->scan($paths['live'], true);
        $plan['new'] = $this->scan($paths['stage']);
        if (!is_array($plan['old']) || !is_array($plan['new'])) return false;
        $plan['old']=$this->digestSide($plan,'old',['live'],true);
        $plan['new']=$this->digestSide($plan,'new',['stage'],true);
        if (!is_array($plan['old']) || !is_array($plan['new'])) return false;
        $plan['new']=$this->digestSide($plan,'new',['stage'],true,true);
        if (!is_array($plan['new'])) return false;
        $plan['oldDigest']=array_column($plan['old'],'digest','name')['databases']??false;
        $plan['newDigest']=array_column($plan['new'],'digest','name')['databases']??false;
        $plan['marker']=RecoveryLedger::digest($plan);
        if (!$this->valid($plan) || !$this->rootsMatch($plan)) return false;
        if (!$this->ledger->mutate(null,$plan,null,true)) return false;
        $raw=$this->ledger->seal($this->marker($plan),'journal-v3');
        if (!is_string($raw) || strlen($raw)>self::LIMIT || !Filesystem::atomicWrite($this->path(),$raw,0600)) return false;
        // A crash here leaves an authenticated but unapplied marker. Recover may adopt only an untouched initial state.
        if (!$this->ledger->mutate(null,$plan)) return false;
        return $plan;
    }

    // Verify the complete observed state, not just the entry about to move.
    // A prepared transaction can be in any prefix of apply OR reverse rollback.
    private function stateMatches(array $plan, string $mode): bool
    {
        if (!$this->rootsMatch($plan)) return false;
        $paths = $this->paths($plan); $expected = []; $seen = [];
        foreach (['old','new'] as $side) foreach ($plan[$side] as $entry) {
            $expected[implode(':',$entry['id'])] = [$side,$entry['name']];
        }
        foreach (['live','stage','old'] as $where) {
            $entries = $this->scan($paths[$where], $where === 'live');
            if ($entries === false) return false;
            foreach ($entries as $entry) {
                $id = implode(':',$entry['id']);
                if (!isset($expected[$id]) || isset($seen[$id]) || $expected[$id][1] !== $entry['name']) return false;
                $side = $expected[$id][0];
                $allowed = $mode === 'committed' ? [$side === 'old' ? 'old' : 'live'] :
                    ($mode === 'rolled-back' ? [$side === 'old' ? 'live' : 'stage'] : ($side === 'old' ? ['live','old'] : ['live','stage']));
                if (!in_array($where,$allowed,true)) return false;
                $seen[$id] = true;
            }
        }
        return count($seen) === count($expected);
    }

    private function move(array $plan, array $entry, string $from, string $to): bool
    {
        if (!$this->rootsMatch($plan)) return false;
        $paths = $this->paths($plan);
        $a = $paths[$from] . DS . $entry['name']; $b = $paths[$to] . DS . $entry['name'];
        if (self::identity($a) !== $entry['id'] || self::identity($b) !== null) return false;
        // Do not use Filesystem::mv(): its regular-file link/copy fallback has
        // an intermediate two-name state and may change the inode on recovery.
        return @rename($a,$b) && $this->rootsMatch($plan) && self::identity($a) === null && self::identity($b) === $entry['id'];
    }

    public function apply(array $plan): bool
    {
        if ($plan['state']!=='prepared' || $this->read() !== $plan || !$this->stateMatches($plan,'rolled-back') || !$this->oldDatabaseMatches($plan) ||
            ($plan['schema'] === 2 && $this->databaseDigest($this->paths($plan)['stage']) !== $plan['newDigest'])) return false;
        if ($plan['schema']===3 && ($this->digestSide($plan,'old',['live'])===false || $this->digestSide($plan,'new',['stage'])===false)) return false;
        foreach ($plan['old'] as $entry) if (!$this->move($plan,$entry,'live','old')) return false;
        foreach ($plan['new'] as $entry) if (!$this->move($plan,$entry,'stage','live')) return false;
        return $this->stateMatches($plan,'committed') && ($plan['schema'] !== 2 ||
            $this->databaseDigest($this->root) === $plan['newDigest']) && ($plan['schema']!==3 ||
            ($this->digestSide($plan,'old',['old'])!==false && $this->digestSide($plan,'new',['live'])!==false));
    }

    public function commit(array $plan, bool $trustedPostprocessing = false): bool
    {
        if ($plan['state']!=='prepared' || $this->read() !== $plan || !$this->stateMatches($plan,'committed')) return false;
        if (!$this->oldDatabaseMatches($plan)) return false;
        // Deployment protection and alias reconstruction intentionally run after apply.
        if ($plan['schema'] === 2) {
            $plan['newDigest'] = $this->databaseDigest($this->root);
            if ($plan['newDigest'] === false) return false;
        }
        if ($plan['schema']===3) {
            if ($this->digestSide($plan,'old',['old'])===false) return false;
            if ($trustedPostprocessing && $this->digestSide($plan,'new',['live'],false,true)===false) return false;
            $new=$this->digestSide($plan,'new',['live'],$trustedPostprocessing);
            if ($new===false) return false;
            $plan['new']=$new;
            $plan['newDigest']=array_column($new,'digest','name')['databases']??false;
            if ($plan['newDigest']===false) return false;
        }
        $plan['state'] = 'committed';
        return $this->write($plan);
    }

    /** Returns none, rolled-back, committed, or blocked. Retains evidence and
     * all staging data on any ambiguity; cleanup is outside the transaction. */
    public function recover(): string
    {
        if (!$this->pending()) return 'none';
        $plan = $this->read();
        if ($plan===false && $this->ledger->exists()) {
            $state=$this->ledger->query(); $marker=$this->loadMarker();
            if ($state===false || $marker===false || $state['active']!==null) return 'blocked';
            $last=$state['last'];
            if (is_array($last) && $last['id']===$marker['id'] && hash_equals($last['marker'],$marker['marker'])) {
                // Ledger already finalized. No replay of any rename: only remove the lingering marker.
                return @unlink($this->path()) ? $last['result'] : 'blocked';
            }
            if (!$this->anchored($marker,$state) || !$this->stateMatches($marker,'rolled-back') ||
                $this->digestSide($marker,'old',['live'])===false) return 'blocked';
            if (!$this->ledger->mutate(null,$marker)) return 'blocked';
            $plan=$this->read();
        }
        if ($plan === false || !$this->stateMatches($plan, $plan['state'] === 'committed' ? 'committed' : 'partial')) return 'blocked';
        $paths = $this->paths($plan);
        if (!$this->oldDatabaseMatches($plan) || ($plan['schema'] === 2 && $plan['state'] === 'committed' &&
            $this->databaseDigest($this->root) !== $plan['newDigest'])) return 'blocked';
        if ($plan['schema']===3 && ($this->digestSide($plan,'old',['old','live'])===false ||
            ($plan['state']==='committed' && $this->digestSide($plan,'new',['live'])===false))) return 'blocked';
        if ($plan['state'] === 'prepared') {
            foreach (array_reverse($plan['new']) as $entry) {
                if (self::identity($paths['stage'] . DS . $entry['name']) === $entry['id']) continue;
                if (!$this->move($plan,$entry,'live','stage')) return 'blocked';
            }
            foreach (array_reverse($plan['old']) as $entry) {
                if (self::identity($paths['live'] . DS . $entry['name']) === $entry['id']) continue;
                if (!$this->move($plan,$entry,'old','live')) return 'blocked';
            }
            if (!$this->stateMatches($plan,'rolled-back') || !$this->oldDatabaseMatches($plan) || ($plan['schema']===3 && $this->digestSide($plan,'old',['live'])===false)) return 'blocked';
        }
        $result = $plan['state'] === 'committed' ? 'committed' : 'rolled-back';
        if (!$this->rootsMatch($plan) || $this->read() !== $plan) return 'blocked';
        // Publish before deleting the journal. A failed write leaves recovery retryable.
        $receipt = ['schema'=>1,'id'=>$plan['id'],'stage'=>$plan['stage'],'result'=>$result,
            'time'=>time(),'databaseVerified'=>$plan['schema'] >= 2,'contentVerified'=>$plan['schema']===3];
        if (!Filesystem::atomicWrite($this->tmp . DS . self::RECEIPT, json_encode($receipt, JSON_THROW_ON_ERROR), 0600) ||
            !$this->rootsMatch($plan) || $this->read() !== $plan) return 'blocked';
        if ($plan['schema']===3) {
            $state=$this->ledger->query();
            if ($state===false || $state['active']!==$plan) return 'blocked';
            $receipt['seq']=$plan['seq']; $receipt['marker']=$plan['marker'];
            // Store only an authenticated digest and root identity for the old rollback tree.
            if ($result==='committed') {
                // Reuse the authenticated per-entry digests, not a new unverified baseline.
                $ctx=hash_init('sha256'); hash_update($ctx,'D:');
                foreach ($plan['old'] as $entry) hash_update($ctx,strlen($entry['name']).':'.$entry['name'].':'.$entry['digest']."\n");
                $receipt['garbage']=['id'=>$plan['roots']['old'],'digest'=>hash_final($ctx)];
            }
            if (!$this->ledger->mutate($plan,null,$receipt)) return 'blocked';
        }
        if (!@unlink($this->path())) return 'blocked';
        return $result;
    }

    public function history(): array
    {
        if (!$this->ledger->exists()) return [];
        $state=$this->ledger->read(); return $state===false ? [] : $state['history'];
    }

    public function lastResult()
    {
        if ($this->ledger->exists()) {
            $state=$this->ledger->query();
            return $state===false?null:$state['last'];
        }
        $path = $this->tmp . DS . self::RECEIPT;
        $id = self::identity($path);
        if (!self::validIdentity($id) || $id[2] !== 0100000 || (@fileperms($path) & 0777) !== 0600) return null;
        $raw = Filesystem::readFileBounded($path, 4096, 2);
        if (!is_string($raw) || self::identity($path) !== $id) return null;
        $r = json_decode($raw, true, 8);
        if (!is_array($r) || ($r['schema'] ?? null) !== 1 || !is_string($r['id'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/D',$r['id']) || !is_string($r['stage'] ?? null) ||
            !preg_match('/^restore-[a-f0-9]{32}$/D',$r['stage']) ||
            !in_array($r['result'] ?? null,['committed','rolled-back'],true) ||
            !is_int($r['time'] ?? null) || $r['time'] <= 0 || !is_bool($r['databaseVerified'] ?? null)) return null;
        return $r;
    }

    private function managementRoot()
    {
        $id = self::identity($this->tmp);
        $root = self::identity($this->root);
        return self::validIdentity($root,null,true) && self::validIdentity($id,$root[0],true) &&
            realpath($this->tmp) === $this->tmp ? $id : false;
    }

    private static function recoveryName(string $name): bool
    {
        return preg_match('/^(?:restore-|backup-restore-rollback-)[a-f0-9]{32}$/D',$name) === 1;
    }

    // Only empty staging directories (optionally with an empty sadrzaj child)
    // are eligible. Never recurse or remove any files, even if they are old.
    private static function limitedNames(string $path): array
    {
        $names = ['.','..'];
        try {
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                $names[] = $entry->getFilename();
                if (count($names) > 3) return [];
            }
        } catch (Throwable $e) { return []; }
        sort($names, SORT_STRING);
        return $names;
    }

    private function emptyCandidate(string $name, array $tmpId)
    {
        if (!self::recoveryName($name) || $this->managementRoot() !== $tmpId ||
            (str_starts_with($name,'backup-restore-rollback-') && self::identity($this->tmp.DS.'restore-gc-'.substr($name,-32).'.json')!==null)) return false;
        $path = $this->tmp . DS . $name;
        $id = self::identity($path);
        if (!self::validIdentity($id,$tmpId[0],true)) return false;
        clearstatcache(true,$path);
        $mtime = @filemtime($path);
        if (!is_int($mtime) || $mtime > time()-86400) return false;
        $list = self::limitedNames($path); $childId = null;
        if ($list === ['.','..','sadrzaj'] && str_starts_with($name,'restore-')) {
            $child = $path . DS . 'sadrzaj'; $childId = self::identity($child);
            if (!self::validIdentity($childId,$tmpId[0],true) || self::limitedNames($child) !== ['.','..'] ||
                self::identity($child) !== $childId) return false;
        } elseif ($list !== ['.','..']) return false;
        if ($this->managementRoot() !== $tmpId || self::identity($path) !== $id) return false;
        return ['id'=>$id,'child'=>$childId,'token'=>hash('sha256',json_encode([$tmpId,$name,$id,$childId,$mtime], JSON_THROW_ON_ERROR))];
    }

    private function verifiedCandidate(string $name,array $tmpId, ?array $state=null)
    {
        if (!preg_match('/^backup-restore-rollback-([a-f0-9]{32})$/D',$name,$m) || $this->managementRoot()!==$tmpId) return false;
        $state=$state??$this->ledger->read(); if ($state===false || $state['active']!==null) return false;
        foreach ($state['history'] as $r) {
            if ($r['id']!==$m[1] || $r['result']!=='committed' || !is_array($r['garbage']??null) || ($r['time']??PHP_INT_MAX)>time()-86400) continue;
            $path=$this->tmp.DS.$name; $id=self::identity($path);
            if ($id!==$r['garbage']['id']) {
                if ($id!==null || !$this->gcAwaitingFinalReceipt($name,$tmpId,$r['garbage']['id'])) return false;
                $id=$r['garbage']['id'];
            }
            // Do not hash all historic media just to render an admin page; verify again on explicit deletion.
            $token=$this->ledger->seal(['name'=>$name,'receipt'=>RecoveryLedger::digest($r),'root'=>$tmpId],'gc-v1');
            return is_string($token) ? ['id'=>$id,'digest'=>$r['garbage']['digest'],'token'=>hash('sha256',$token)] : false;
        }
        return false;
    }

    private function gcAwaitingFinalReceipt(string $name,array $tmpId,array $rootId): bool
    {
        $base=$this->tmp.DS.'restore-gc-'.substr($name,-32);
        $plan=$this->gcLoad($base.'.json','gc-plan-v1');
        $cursor=$this->gcLoad($base.'-cursor.json','gc-cursor-v1');
        if (!is_array($plan) || ($plan['name']??null)!==$name || ($plan['root']??null)!==$tmpId || empty($plan['entries']) || !is_array($cursor)) return false;
        $entries=$plan['entries']; $last=end($entries);
        return ($last['path']??null)==='' && ($last['id']??null)===$rootId && ($cursor['next']??null)===count($entries)-1 && ($cursor['plan']??null)===RecoveryLedger::digest($plan);
    }

    private static function gcIdentity(string $path)
    {
        clearstatcache(true,$path); $s=@lstat($path);
        if ($s===false) return null;
        if (($s['mode']&0170000)===0120000 && $s['ino']>0) return [$s['dev'],$s['ino'],0120000];
        return self::identity($path);
    }

    // Capture postorder entries while verifying the authenticated whole-tree digest.
    private function captureGcEntry(string $path,array &$budget,string $digest,$expected): bool
    {
        if (!isset($budget['gc'])) return true;
        if (count($budget['gc'])>=10000) return false;
        $id=self::gcIdentity($path); if (!is_array($id) || $id!==$expected || $id[0]!==$budget['device']) return false;
        $relative=$path===$budget['base'] ? '' : substr($path,strlen($budget['base'])+1);
        $budget['gc'][]=['path'=>$relative,'id'=>$id,'digest'=>$digest];
        return true;
    }
    private function gcLoad(string $path,string $domain)
    {
        $id=self::identity($path);
        if (!is_array($id) || $id[2]!==0100000 || (@fileperms($path)&0777)!==0600) return false;
        $raw=Filesystem::readFileBounded($path,RecoveryLedger::LIMIT,1);
        return is_string($raw) && self::identity($path)===$id ? $this->ledger->open($raw,$domain) : false;
    }
    private function gcSave(string $path,array $value,string $domain): bool
    {
        $raw=$this->ledger->seal($value,$domain);
        return is_string($raw) && strlen($raw)<=RecoveryLedger::LIMIT && Filesystem::atomicWrite($path,$raw,0600);
    }
    private function gcFileMatches(string $path,array $entry,array $tmpId): bool
    {
        if (self::gcIdentity($path)!==$entry['id']) return false;
        if ($entry['id'][2]===0040000) return realpath($path)===$path;
        if ($entry['id'][2]===0120000) {
            $target=@readlink($path);
            return is_string($target) && hash_equals($entry['digest'],hash('sha256','L:'.$target)) && self::gcIdentity($path)===$entry['id'];
        }
        $budget=$this->budget($tmpId[0]);
        return $this->entryDigest($path,$budget)===$entry['digest'];
    }
    private function gcParentsMatch(string $path,string $root,array $byPath,array $tmpId): bool
    {
        if ($this->managementRoot()!==$tmpId) return false;
        while ($path!==$root) {
            $path=dirname($path);
            $rel=$path===$root ? '' : substr($path,strlen($root)+1);
            if (!isset($byPath[$rel]) || $byPath[$rel]['id'][2]!==0040000 || self::identity($path)!==$byPath[$rel]['id'] || realpath($path)!==$path) return false;
        }
        return true;
    }

    public function cleanupVerified(string $name,string $token): bool
    {
        if ($this->pending() || $this->ledger->keyStatus()!=='active' || !preg_match('/^[a-f0-9]{64}$/D',$token)) return false;
        $tmpId=$this->managementRoot(); if ($tmpId===false) return false;
        $c=$this->verifiedCandidate($name,$tmpId);
        if ($c===false || !hash_equals($c['token'],$token)) return false;
        $root=$this->tmp.DS.$name;
        $base=$this->tmp.DS.'restore-gc-'.substr($name,-32);
        $planPath=$base.'.json'; $cursorPath=$base.'-cursor.json';
        if (self::identity($planPath)===null) {
            if (self::identity($cursorPath)!==null) return false;
            $budget=$this->budget($tmpId[0]); $budget['base']=$root; $budget['gc']=[];
            if ($this->entryDigest($root,$budget)!==$c['digest'] || $this->managementRoot()!==$tmpId || self::identity($root)!==$c['id']) return false;
            $plan=['schema'=>1,'name'=>$name,'token'=>$token,'root'=>$tmpId,'entries'=>$budget['gc']];
            if (!$this->gcSave($planPath,$plan,'gc-plan-v1')) return false;
        } else $plan=$this->gcLoad($planPath,'gc-plan-v1');
        if (!is_array($plan) || ($plan['schema']??null)!==1 || ($plan['name']??null)!==$name || ($plan['root']??null)!==$tmpId || !is_array($plan['entries']??null)) return false;
        // The request token is checked against the current key above. Persisted
        // evidence binds to the authenticated receipt, not a retired UI token.
        $entries=$plan['entries']; $count=count($entries); $digest=RecoveryLedger::digest($plan);
        if (!$count || $count>10000 || end($entries)['path']!=='' || end($entries)['id']!==$c['id'] || end($entries)['digest']!==$c['digest']) return false;
        $cursor=0;
        if (self::identity($cursorPath)!==null) {
            $state=$this->gcLoad($cursorPath,'gc-cursor-v1');
            if (!is_array($state) || ($state['plan']??null)!==$digest || !is_int($state['next']??null) || $state['next']<0 || $state['next']>$count) return false;
            $cursor=$state['next'];
        }
        $byPath=[]; $children=[];
        foreach ($entries as $i=>$entry) {
            $relative=$entry['path'];
            if (!is_string($relative) || isset($byPath[$relative]) || ($relative!=='' && (str_starts_with($relative,DS) || in_array('..',explode(DS,$relative),true) || in_array('.',explode(DS,$relative),true)))) return false;
            $byPath[$relative]=$entry;
            if ($relative!=='') { $parent=dirname($relative); $children[$parent==='.'?'':$parent][]=$i; }
        }
        // Validate every remaining item before any additional destructive action.
        // Only the current durably authorized operation may already be absent.
        $present=[];
        foreach ($entries as $i=>$entry) {
            $path=$entry['path']===''?$root:$root.DS.$entry['path']; $id=self::gcIdentity($path);
            if ($i<$cursor) { if ($id!==null) return false; continue; }
            if ($i===$cursor && $id===null && self::identity($cursorPath)!==null) continue;
            if (!$this->gcParentsMatch($path,$root,$byPath,$tmpId) || !$this->gcFileMatches($path,$entry,$tmpId)) return false;
            $present[$i]=true;
        }
        foreach ($present as $i=>$_) {
            $entry=$entries[$i]; if ($entry['id'][2]!==0040000) continue;
            $expected=['.','..']; foreach ($children[$entry['path']]??[] as $child) if (isset($present[$child])) $expected[]=basename($entries[$child]['path']);
            sort($expected,SORT_STRING); $path=$entry['path']===''?$root:$root.DS.$entry['path'];
            if (@scandir($path)!==$expected) return false;
        }
        for ($i=$cursor;$i<$count;$i++) {
            $entry=$entries[$i]; $path=$entry['path']===''?$root:$root.DS.$entry['path'];
            if (!$this->gcParentsMatch($path,$root,$byPath,$tmpId)) return false;
            // Persist permission for exactly one operation before unlink/rmdir.
            if (!$this->gcSave($cursorPath,['plan'=>$digest,'next'=>$i],'gc-cursor-v1')) return false;
            if (!isset($present[$i])) { if (self::gcIdentity($path)!==null) return false; continue; }
            if (!$this->gcFileMatches($path,$entry,$tmpId)) return false;
            if ($entry['id'][2]===0040000 ? !@rmdir($path) : !@unlink($path)) return false;
        }
        return $this->gcSave($cursorPath,['plan'=>$digest,'next'=>$count],'gc-cursor-v1');
    }

    public function inventory(?int $historyPage=null): array
    {
        $tmpId = $this->managementRoot();
        $out = ['pending'=>$this->pending(),'last'=>null,'history'=>[],'items'=>[],'complete'=>false,'mirror'=>'uninitialized','checkpointConfigured'=>false];
        if ($tmpId === false) return $out;
        // Collect only the IDs needed for this inventory (at most 100).
        // No need to materialize the entire receipt history for a page.
        $wanted=[]; $scanned=0;
        try {
            foreach (new DirectoryIterator($this->tmp) as $entry) {
                if (++$scanned>10000 || count($wanted)>=100) break;
                if (preg_match('/^(?:backup-restore-rollback-([a-f0-9]{32})|restore-gc-([a-f0-9]{32})\.json)$/D',$entry->getFilename(),$m)) {
                    $id=$m[1]!==''?$m[1]:$m[2]; $wanted[$id]=$id;
                }
            }
        } catch (Throwable $e) { return $out; }
        $ledgerState=$this->ledger->exists()?$this->ledger->query($historyPage,array_values($wanted),null,$historyPage===null):false;
        $out['last']=$ledgerState===false?$this->lastResult():$ledgerState['last'];
        $out['historyTotal']=$ledgerState===false?0:$ledgerState['total'];
        $out['historyPage']=$ledgerState===false?1:$ledgerState['page'];
        if ($ledgerState!==false) {
            $out['history']=array_reverse($ledgerState['history']);
            // Only selected receipts can authorize displayed GC candidates.
            $ledgerState=['active'=>$ledgerState['active'],'history'=>array_values($ledgerState['matches'])];
        }
        $out['mirror']=$this->ledger->exists() ? $this->ledger->mirrorStatus() : 'uninitialized';
        $out['checkpointConfigured']=(bool)getenv('BRENDY_RECOVERY_CHECKPOINT');
        $count = 0;
        try {
            foreach (new DirectoryIterator($this->tmp) as $entry) {
                if (++$count > 10000 || count($out['items']) >= 100) return $out;
                $name = $entry->getFilename();
                if (preg_match('/^restore-gc-([a-f0-9]{32})\.json$/D',$name,$gc)) {
                    $name='backup-restore-rollback-'.$gc[1];
                    if (self::identity($this->tmp.DS.$name)!==null || $out['pending'] || $ledgerState===false) continue;
                    $candidate=$this->verifiedCandidate($name,$tmpId,$ledgerState);
                    if ($candidate!==false) $out['items'][]=['name'=>$name,'safeDirectory'=>true,'verified'=>true,'gcPending'=>true,'token'=>$candidate['token']];
                    continue;
                }
                if (!self::recoveryName($name)) continue;
                $id = self::identity($this->tmp . DS . $name);
                $candidate=false; $verified=false;
                if (!$out['pending'] && $ledgerState!==false) { $candidate=$this->verifiedCandidate($name,$tmpId,$ledgerState); $verified=$candidate!==false; }
                if ($candidate===false && !$out['pending']) $candidate=$this->emptyCandidate($name,$tmpId);
                $out['items'][] = ['name'=>$name,'safeDirectory'=>self::validIdentity($id,$tmpId[0],true),
                    'verified'=>$verified,'gcPending'=>$verified && self::identity($this->tmp.DS.'restore-gc-'.substr($name,-32).'.json')!==null,'token'=>$candidate === false ? null : $candidate['token']];
            }
        } catch (Throwable $e) { return $out; }
        $out['complete'] = $this->managementRoot() === $tmpId;
        return $out;
    }

    // Caller must hold the exclusive restore gate. The token binds the UI
    // selection to the directory identity; rmdir cannot erase nonempty data.
    public function cleanupEmpty(string $name, string $token): bool
    {
        if ($this->pending() || !preg_match('/^[a-f0-9]{64}$/D',$token)) return false;
        $tmpId = $this->managementRoot();
        if ($tmpId === false) return false;
        $c = $this->emptyCandidate($name,$tmpId);
        if ($c === false || !hash_equals($c['token'],$token)) return false;
        $path = $this->tmp . DS . $name;
        if ($this->pending() || $this->managementRoot() !== $tmpId || self::identity($path) !== $c['id']) return false;
        if ($c['child'] !== null) {
            $child = $path . DS . 'sadrzaj';
            if (self::identity($child) !== $c['child'] || !@rmdir($child)) return false;
        }
        return !$this->pending() && $this->managementRoot() === $tmpId && self::identity($path) === $c['id'] && @rmdir($path);
    }

}
