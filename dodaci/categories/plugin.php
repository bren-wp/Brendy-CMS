<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginCategories extends BrendyPlugin
{

	public function init()
	{
		// Fields and default values for the database of this plugin
		$this->dbFields = array(
			'label' => 'Categories',
			'hideCero' => true
		);
	}

	// Method called on the settings of the plugin on the admin area
	public function form()
	{
		$html = $this->settingsIntro();

		$html .= $this->settingsLabelField();

		$html .= $this->settingsBooleanField('hideCero', 'Hide Categories without content');

		return $html;
	}

	// Method called on the sidebar of the website
	public function siteSidebar()
	{
		global $categories;
		$html  = '<div class="plugin plugin-categories">';
		$html .= $this->sidebarHeading($this->getValue('label', false));
		$html .= '<div class="plugin-content">';
		$html .= $this->sidebarTaxonomyList($categories, 'category', DOMAIN_CATEGORIES, 'folder2', $this->getValue('hideCero') === true, true);
		return $html . '</div></div>';
	}
}
