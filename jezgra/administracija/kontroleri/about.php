<?php defined('BRENDY') or die('Brendy CMS.');

checkRole(array('admin'));

// Title of the page
$layout['title'] = $L->g('About') . ' - ' . $layout['title'];