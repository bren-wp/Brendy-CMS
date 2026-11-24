<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Named PHP function bodies with identical tokens, ignoring whitespace and comments.
$root=dirname(__DIR__); $groups=[]; $normalizedGroups=[]; $files=0; $functions=0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') continue;
    $path=substr($file->getPathname(),strlen($root)+1);
    if (preg_match('~^(tests|sadrzaj)/~',$path) || basename($path)==='parsedown.class.php') continue;
    $files++;
    $tokens=array_values(array_filter(token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
    foreach ($tokens as $i=>$t) {
        if (!is_array($t)||$t[0]!==T_FUNCTION) continue;
        $j=$i+1;
        if (($tokens[$j]??null)==='&'||(is_array($tokens[$j]??null)&&$tokens[$j][1]==='&')) $j++;
        if (!is_array($tokens[$j]??null)||$tokens[$j][0]!==T_STRING) continue;
        $name=$tokens[$j][1]; $line=$t[2]; $parentheses=0;
        for ($j++;$j<count($tokens);$j++) {
            $v=$tokens[$j];
            if ($v==='(') $parentheses++;
            if ($v===')') $parentheses--;
            if ($parentheses===0&&($v==='{'||$v===';')) break;
        }
        if (($tokens[$j]??null)!=='{') continue;
        $body=[]; $depth=1;
        for ($j++;$j<count($tokens)&&$depth>0;$j++) {
            $v=$tokens[$j];
            if ($v==='{'||(is_array($v)&&in_array($v[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))) $depth++;
            if ($v==='}') $depth--;
            if ($depth>0) $body[]=is_array($v)?[$v[0],$v[1]]:$v;
        }
        if ($depth!==0) continue;
        $functions++;
        if (count($body)<24) continue;
        $entry=['path'=>$path,'line'=>$line,'symbol'=>$name,'tokens'=>count($body)];
        $groups[hash('sha256',serialize($body))][]=$entry;
        $variables=[]; $normalized=[];
        foreach ($body as $token) {
            if (is_array($token) && $token[0]===T_VARIABLE && !in_array($token[1],['$this','$GLOBALS','$_GET','$_POST','$_SERVER','$_SESSION','$_COOKIE','$_FILES','$_REQUEST','$_ENV'],true)) {
                $variables[$token[1]] ??= count($variables)+1;
                $token[1]='$local'.$variables[$token[1]];
            }
            $normalized[]=$token;
        }
        if (count($body)>=40) $normalizedGroups[hash('sha256',serialize($normalized))][]=$entry;
    }
}
$groups=array_values(array_filter($groups,static fn($g)=>count($g)>1));
echo json_encode(['scope'=>'first-party PHP named function bodies; exact tokens ignoring trivia; at least 24 tokens; candidates only, plus variable-normalized candidates (40+ tokens); not semantic equivalence or call graph','files'=>$files,'named_functions'=>$functions,'clone_groups'=>$groups,'variable_normalized_candidates'=>array_values(array_filter($normalizedGroups,static fn($g)=>count($g)>1))],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
