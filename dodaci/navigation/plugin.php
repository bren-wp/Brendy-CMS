<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginNavigation extends BrendyPlugin
{

	public function init()
	{
		$this->dbFields = array(
			'label' => 'Navigation',
			'homeLink' => true,
			'showPublishedPages' => true,
			'showStaticPages' => true,
			'numberOfItems' => 5
		);
	}

	protected function migrateLegacySettings($loaded)
	{
		$loaded = parent::migrateLegacySettings($loaded);
		if (!is_array($loaded)) $loaded = array();
		if (!array_key_exists('showPublishedPages', $loaded)) $loaded['showPublishedPages'] = true;
		if (!array_key_exists('showStaticPages', $loaded)) {
			// Preserve historical Navigation behaviour unless the separate Static
			// Pages plugin was actually installed. Fresh installs use the new default
			// from init() and expose both useful page types from one plugin.
			$legacyStaticDb = defined('PATH_PLUGINS_DATABASES')
				? PATH_PLUGINS_DATABASES . 'static-pages' . DS . 'db.php' : '';
			$loaded['showStaticPages'] = $legacyStaticDb !== '' && is_file($legacyStaticDb) && !is_link($legacyStaticDb);
		}
		return $loaded;
	}

	public function form()
	{
		$html = $this->settingsIntro();
		$html .= $this->settingsLabelField('jslabel');
		$html .= $this->settingsHomeLinkField('Show the home link on the sidebar');
		$html .= $this->settingsBooleanField('showPublishedPages', 'Show published pages');
		$html .= $this->settingsBooleanField('showStaticPages', 'Show static pages');

		if (ORDER_BY === 'date') {
			$html .= $this->settingsNumberField('numberOfItems', 'Amount of published items', 1, 100);
		}
		return $html;
	}

	protected function integerSettingBounds()
	{
		return array('numberOfItems' => array(1, 100));
	}

	public function siteHead()
	{
		return $this->sidebarTreeStylesheet();
	}

	private function rootKeysForTypes($types)
	{
		global $pages;
		if (!is_object($pages) || !is_array($types) || empty($types)) return array();
		$ordered = array();
		if (in_array('published', $types, true)) {
			foreach ($pages->getPublishedDB() as $key) if (is_string($key) && $key !== '') $ordered[] = $key;
		}
		if (in_array('static', $types, true)) {
			foreach ($pages->getStaticDB() as $key) if (is_string($key) && $key !== '') $ordered[] = $key;
		}
		$ordered = array_values(array_unique($ordered));
		$allowed = array_fill_keys($ordered, true);
		$roots = array();
		foreach ($ordered as $key) {
			if (!$pages->isPubliclyAccessible($key)) continue;
			try {
				$item = new Page($key);
				$parentKey = $item->parentKey();
				if ($parentKey === false || !isset($allowed[$parentKey])) $roots[] = $key;
			} catch (Throwable $e) {
				// Corrupt records remain visible through System Health, not the public UI.
			}
		}
		return $roots;
	}

	public function siteSidebar()
	{
		global $pages;

		$html  = '<div class="plugin plugin-navigation">';
		$label = $this->getValue('label', false);
		$html .= $this->sidebarHeading(is_scalar($label) ? (string)$label : '');
		$html .= '<div class="plugin-content"><ul>';
		$html .= $this->sidebarHomeLink();

		$types = array();
		if ($this->getValue('showPublishedPages') === true) $types[] = 'published';
		if ($this->getValue('showStaticPages') === true) $types[] = 'static';

		if (ORDER_BY === 'position') {
			$html .= $this->sidebarPageTree($this->rootKeysForTypes($types), $types);
		} else {
			if (in_array('published', $types, true)) {
				$pageNumber = 1;
				$numberOfItems = max(1, min(100, (int)$this->getValue('numberOfItems')));
				$publishedPages = $pages->getPublicList($pageNumber, $numberOfItems, true);
				foreach (is_array($publishedPages) ? $publishedPages : array() as $pageKey) {
					if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
					try {
						$item = new Page($pageKey);
						$isCurrent = isset($GLOBALS['page']) && is_object($GLOBALS['page']) && method_exists($GLOBALS['page'], 'key') && $GLOBALS['page']->key() === $item->key();
						$html .= '<li><a' . ($isCurrent ? ' aria-current="page"' : '') . ' href="' . Theme::escape($item->permalink()) . '">' . Theme::escape($item->title()) . '</a></li>';
					} catch (Throwable $e) {
						// Continue with the remaining public entries.
					}
				}
			}
			if (in_array('static', $types, true)) {
				$html .= $this->sidebarPageTree($this->rootKeysForTypes(array('static')), array('static'));
			}
		}

		return $html . '</ul></div></div>';
	}
}
