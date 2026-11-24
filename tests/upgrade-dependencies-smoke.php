<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return array(
 'jQuery 4.1.0 physically installed' => static function () {
  $source=file_get_contents(dirname(__DIR__).'/jezgra/js/jquery.min.js');
  brendyTestAssert(str_starts_with($source,'/*! jQuery v4.1.0'),'Unexpected jQuery version');
 },
 'TinyMCE local sanitizer replacement and licenses' => static function () {
  $dir=dirname(__DIR__).'/jezgra/urednik/tinymce/'; $source=file_get_contents($dir.'tinymce.min.js');
  brendyTestAssert(str_contains($source,'DOMPurify 3.5.0') && str_contains($source,'var Jw=(function(){var module={exports:{}};') && !str_contains($source,'3.4.12'),'Unexpected embedded sanitizer');
  brendyTestAssert(is_file($dir.'DOMPurify-LICENSE.txt') && is_file($dir.'DOMPurify-LICENSE-MPL.txt'),'Missing sanitizer licenses');
 },
);
