<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$risk = array('eval','assert','exec','shell_exec','system','passthru','proc_open','popen','unserialize','base64_decode','gzinflate','gzuncompress','str_rot13','move_uploaded_file','file_put_contents','fopen','curl_exec','call_user_func','call_user_func_array','create_function','putenv','dl','stream_socket_client','fsockopen');
$dynamic = array(); $findings = array(); $counts = array(); $hashes = array(); $files = 0; $php = 0;
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if (!$f->isFile() || $f->isLink()) continue;
    $path = substr($f->getPathname(), strlen($root)+1);
    if (str_starts_with($path,'sadrzaj/') || str_starts_with($path,'tests/') || $path === 'SHA256SUMS.txt') continue;
    $files++; if ($f->getSize() > 0) $hashes[hash_file('sha256',$f->getPathname())][]=$path;
    if (strtolower($f->getExtension()) !== 'php') continue;
    $php++;
    $tokens = token_get_all(file_get_contents($f->getPathname()));
    foreach ($tokens as $i=>$token) {
        if (!is_array($token)) continue;
        if ($token[0] === T_VARIABLE) {
            $next=$i+1; while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true)) $next++;
            if (($tokens[$next]??null)==='(') $dynamic[]=array('path'=>$path,'line'=>$token[2],'kind'=>'variable-call');
        }
        $name = strtolower(ltrim($token[1], '\\'));
        if ($token[0] !== T_EVAL && (!in_array($token[0], array(T_STRING,T_NAME_FULLY_QUALIFIED),true) || !in_array($name,$risk,true))) continue;
        $j=$i+1; while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true)) $j++;
        if (($tokens[$j]??null)!=='(') continue;
        $counts[$name]=($counts[$name]??0)+1;
        $findings[]=array('path'=>$path,'line'=>$token[2],'symbol'=>$name);
    }
}
$duplicates = array_values(array_filter($hashes,static fn($a)=>count($a)>1));
echo json_encode(array('scope'=>'distribution files, PHP token indicators; candidates not confirmed vulnerabilities; private content and tests excluded','files'=>$files,'php'=>$php,'risk_counts'=>$counts,'candidates'=>$findings,'dynamic_call_candidates'=>$dynamic,'byte_identical_groups'=>$duplicates),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
