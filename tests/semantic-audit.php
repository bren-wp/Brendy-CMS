<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Conservative candidate inventory, never deletes code. Dynamic dispatch and external extensions may retain references.
$root = dirname(__DIR__); $sources = array(); $symbols = array(); $private = array(); $deprecated = array(); $dynamicReferences = array(); $staticCalls = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $f) {
    if (!$f->isFile() || $f->isLink()) continue;
    $path = substr($f->getPathname(), strlen($root)+1);
    if (preg_match('~^(tests|sadrzaj)/~', $path) || str_contains($path, '/tinymce/') || str_ends_with($path, '.min.js') || basename($path)==='parsedown.class.php') continue;
    if (!in_array($f->getExtension(), array('php','js'), true)) continue;
    $code = file_get_contents($f->getPathname());
    $sources[$path] = $code;
    if ($f->getExtension() !== 'php') continue;
    $tokens = token_get_all($code, TOKEN_PARSE);
    // Direct class-method edges only. Dynamic calls and aliases are intentionally unresolved.
    $significant = array_values(array_filter($tokens, static fn($t) => !is_array($t) || !in_array($t[0], array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT), true)));
    for ($i=0, $n=count($significant)-3; $i<$n; $i++) {
        $owner=$significant[$i]; $op=$significant[$i+1]; $method=$significant[$i+2];
        if (is_array($owner) && in_array($owner[0],array(T_STRING,T_STATIC,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED),true) &&
            is_array($op) && $op[0]===T_DOUBLE_COLON && is_array($method) && $method[0]===T_STRING && $significant[$i+3]==='(') {
            $staticCalls[]=array('path'=>$path,'line'=>$owner[2],'target'=>$owner[1].'::'.$method[1]);
        }
    }
    foreach ($tokens as $i=>$token) {
        if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]),array('call_user_func','call_user_func_array','method_exists','is_callable','function_exists'),true)) $dynamicReferences[]=array('path'=>$path,'line'=>$token[2],'kind'=>strtolower($token[1]));
        if (!is_array($token) || $token[0] !== T_PRIVATE) continue;
        $j=$i+1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE,T_STATIC,T_FINAL,T_COMMENT,T_DOC_COMMENT), true)) $j++;
        if (!is_array($tokens[$j]??null) || $tokens[$j][0]!==T_FUNCTION) continue;
        $j++;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j++;
        if (is_array($tokens[$j]??null) && $tokens[$j][0]===T_STRING) $private[]=array('name'=>$tokens[$j][1],'path'=>$path,'line'=>$tokens[$j][2]);
    }
}
$all=implode("\n",$sources); $candidates=array();
foreach ($private as $symbol) {
    // Includes strings, comments and unrelated same-name methods: intentionally conservative.
    $count=preg_match_all('/(?<![a-zA-Z0-9_])'.preg_quote($symbol['name'],'/').'(?![a-zA-Z0-9_])/i',$all);
    if ($count===1 && !str_starts_with($symbol['name'],'__')) $candidates[]=$symbol;
}
$patterns=array('jquery_removed_static'=>'~(?:\bjQuery|\$)\s*\.\s*(?:trim|type|isArray|isFunction|isNumeric|parseJSON|proxy|isWindow|holdReady|camelCase|nodeName|now)\s*\(~','legacy_ready'=>'~\$\(document\)\.ready\s*\(~','legacy_window_click'=>'~\$\(window\)\.click\s*\(~','legacy_form_submit'=>'~(?:\$\(["\']#jsform["\']\)|appendTo\(["\']body["\']\))\.submit\s*\(~');
foreach ($sources as $path=>$code) foreach ($patterns as $name=>$pattern) if (preg_match_all($pattern,$code,$matches,PREG_OFFSET_CAPTURE)) foreach($matches[0] as $m) $deprecated[]=array('path'=>$path,'line'=>substr_count(substr($code,0,$m[1]),"\n")+1,'pattern'=>$name);
echo json_encode(array('scope'=>'first-party PHP/JS textual reference inventory; not call-graph proof; vendor/minified, tests and private content excluded','files'=>count($sources),'private_methods'=>count($private),'unreferenced_private_candidates'=>$candidates,'selected_deprecated_js_candidates'=>$deprecated,'dynamic_reference_indicators'=>$dynamicReferences,'direct_static_call_edges'=>$staticCalls),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
