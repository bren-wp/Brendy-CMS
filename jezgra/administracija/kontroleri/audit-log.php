<?php defined('BRENDY') or die('Brendy CMS.');

checkRole(array('admin'));
$layout['title'] .= ' - ' . $L->g('Activity log');
