<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginTags extends BrendyPlugin
{

	public function init()
	{
		$this->dbFields = array(
			'label' => 'Tags'
		);
	}

	public function form()
	{
		$html = $this->settingsIntro();

		$html .= $this->settingsLabelField('jslabel');

		return $html;
	}

	public function siteSidebar()
	{
		global $tags;
		$html  = '<div class="plugin plugin-tags">';
		$html .= $this->sidebarHeading($this->getValue('label', false));
		$html .= '<div class="plugin-content">';
		$html .= $this->sidebarTaxonomyList($tags, 'tag', DOMAIN_TAGS, 'tag', true, false);
		return $html . '</div></div>';
	}
}
