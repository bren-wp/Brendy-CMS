<?php defined('BRENDY') or die('Brendy CMS.');

class Category extends BrendyTaxonomyItem
{
	function __construct($key)
	{
		global $categories;
		$this->hydrateTaxonomy($key, $categories, DOMAIN_CATEGORIES, 'Category', array(
			'template' => '',
			'description' => ''
		));
	}

	public function template() { return $this->getValue('template'); }
	public function description() { return $this->getValue('description'); }

	protected function jsonFields()
	{
		return array(
			'key' => $this->key(),
			'name' => $this->name(),
			'description' => $this->description(),
			'permalink' => $this->permalink(),
			'pages' => $this->pages()
		);
	}
}
