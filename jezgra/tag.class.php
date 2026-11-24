<?php defined('BRENDY') or die('Brendy CMS.');

class Tag extends BrendyTaxonomyItem
{
	function __construct($key)
	{
		global $tags;
		$this->hydrateTaxonomy($key, $tags, DOMAIN_TAGS, 'Tag');
	}
}
