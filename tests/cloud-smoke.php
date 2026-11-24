<?php
require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'urednik' . DS . 'cloud.php';
return array(
    'Tiny Cloud URL requires authenticated content edit route' => static function () {
        $dir = brendyTestTempDir('brendy-cloud');
        $mode = getenv('BRENDY_TINYMCE_MODE'); $key = getenv('BRENDY_TINYMCE_API_KEY');
        try {
            putenv('BRENDY_TINYMCE_MODE'); putenv('BRENDY_TINYMCE_API_KEY');
            $fakeKey = str_repeat('a', 48);
            file_put_contents($dir . DS . '.tinymce-cloud.json', json_encode(array('mode'=>'cloud','apiKey'=>$fakeKey)));
            brendyTestAssert(brendyTinyCloudUrl(true,'edit-content',$dir)==='', 'Unverified Cloud must be off by default');
            putenv('BRENDY_TINYMCE_MODE=cloud');
            $expected = 'https://cdn.tiny.cloud/1/' . $fakeKey . '/tinymce/' . BRENDY_EDITOR_VERSION . '/tinymce.min.js';
            foreach (array('new-content','edit-content') as $route) brendyTestAssert(brendyTinyCloudUrl(true,$route,$dir)===$expected,'Cloud URL is not pinned correctly');
            foreach (array('login','dashboard','settings','plugins-position','ajax') as $route) brendyTestAssert(brendyTinyCloudUrl(true,$route,$dir)==='','Cloud loaded outside content editing');
            brendyTestAssert(brendyTinyCloudUrl(false,'edit-content',$dir)==='','Unauthenticated request received Cloud URL');
            brendyTestAssert(brendyTinyCloudUrl(true,'edit-content',$dir,true)==='','Local override failed');
            putenv('BRENDY_TINYMCE_MODE=local'); brendyTestAssert(brendyTinyCloudUrl(true,'edit-content',$dir)==='','Environment local override failed');
            putenv('BRENDY_TINYMCE_MODE=cloud'); putenv('BRENDY_TINYMCE_API_KEY=bad/key'); brendyTestAssert(brendyTinyCloudUrl(true,'edit-content',$dir)==='','Invalid API key was accepted');
        } finally {
            putenv($mode === false ? 'BRENDY_TINYMCE_MODE' : 'BRENDY_TINYMCE_MODE='.$mode);
            putenv($key === false ? 'BRENDY_TINYMCE_API_KEY' : 'BRENDY_TINYMCE_API_KEY='.$key);
            brendyTestRemoveTree($dir);
        }
    },
    'Tiny Cloud refuses malformed and oversized private configuration' => static function () {
        $dir=brendyTestTempDir('brendy-cloud-malformed');
        $mode=getenv('BRENDY_TINYMCE_MODE'); $key=getenv('BRENDY_TINYMCE_API_KEY');
        try {
            putenv('BRENDY_TINYMCE_MODE'); putenv('BRENDY_TINYMCE_API_KEY');
            foreach (array('{bad-json',str_repeat('x',3000),json_encode(array('mode'=>'cloud','apiKey'=>array('bad')))) as $data) {
                file_put_contents($dir.DS.'.tinymce-cloud.json',$data);
                brendyTestAssert(brendyTinyCloudUrl(true,'new-content',$dir)==='','Malformed config accepted');
            }
        } finally {
            putenv($mode === false ? 'BRENDY_TINYMCE_MODE' : 'BRENDY_TINYMCE_MODE='.$mode);
            putenv($key === false ? 'BRENDY_TINYMCE_API_KEY' : 'BRENDY_TINYMCE_API_KEY='.$key);
            brendyTestRemoveTree($dir);
        }
    },
);
