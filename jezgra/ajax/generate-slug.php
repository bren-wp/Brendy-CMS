<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');
requireAjaxRole(array('admin', 'editor', 'author'));

/*
| Generate a slug text for the URL
|
| @_POST['text']	string 	The text from where is generated the slug
| @_POST['parentKey']	string	The parent key if the page has one
| @_POST['currentKey']	string	The current page key
|
| @return	array
*/

// $_POST
// ----------------------------------------------------------------------------
$text 	= isset($_POST['text']) && is_string($_POST['text']) ? mb_substr($_POST['text'], 0, 500, CHARSET) : '';
$parent = isset($_POST['parentKey']) && is_string($_POST['parentKey']) ? $_POST['parentKey'] : '';
$oldKey = isset($_POST['currentKey']) && is_string($_POST['currentKey']) ? $_POST['currentKey'] : '';
// ----------------------------------------------------------------------------

if (strlen($parent) > 200 || strlen($oldKey) > 200) {
	ajaxResponse(1, $L->g('Invalid page key'));
}

$slug = $pages->generateKey($text, $parent, $returnSlug=true, $oldKey);
if (!is_string($slug) || $slug === '') {
	ajaxResponse(1, $L->g('Unable to generate a valid slug'));
}

ajaxResponse(0, $L->g('Slug generated'), array(
	'slug'=>$slug
));

?>
