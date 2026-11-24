<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginSitemap extends BrendyGeneratedArtifactPlugin
{
	public function init()
	{
		$this->dbFields = array();
	}

	public function prepare()
	{
		// Remove obsolete ping settings left by old releases. They no longer have
		// runtime behavior and keeping them only creates dead configuration data.
		$before = $this->db;
		$changed = false;
		foreach (array('pingGoogle', 'pingBing') as $field) {
			if (array_key_exists($field, $this->db)) {
				unset($this->db[$field]);
				$changed = true;
			}
		}
		if ($changed && !$this->save()) {
			$this->db = $before;
			Log::set(__METHOD__ . LOG_SEP . 'Unable to remove legacy sitemap settings.', LOG_TYPE_ERROR);
			return false;
		}
		return true;
	}

	public function form()
	{
		global $L;
		$sitemapUrl = Theme::escape(Theme::sitemapUrl());
		$html = $this->settingsIntro();
		$html .= '<div class="form-group"><label for="brendy-sitemap-url">' . Theme::escape($L->get('Sitemap URL')) . '</label>' .
			'<div><a id="brendy-sitemap-url" class="text-break" href="' . $sitemapUrl . '" target="_blank" rel="noopener noreferrer">' . $sitemapUrl . '</a></div></div>';
		return $html;
	}

	private function xmlText($value)
	{
		if (!is_scalar($value)) return '';
		$value = mb_convert_encoding((string)$value, 'UTF-8', 'UTF-8');
		$clean = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
		return is_string($clean) ? $clean : '';
	}

	protected function generatedArtifactConfig()
	{
		return array(
			'filename' => 'sitemap.xml',
			'contentType' => 'application/xml',
			'maxSize' => 33554432,
			'dependencies' => array(DB_SITE, DB_PAGES, DB_CATEGORIES, DB_TAGS, $this->filenameDb, __FILE__, PATH_KERNEL . 'release.php')
		);
	}

	protected function generatePublicArtifact()
	{
		global $pages, $categories, $tags, $site;
		if (!class_exists('DOMDocument')) return false;

		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$urlset = $doc->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
		$doc->appendChild($urlset);
		$seen = array();
		$appendUrl = function ($location, $lastModified = '') use ($doc, $urlset, &$seen) {
			if (!brendyIsSafeHttpUrl($location, 2047) || strlen($location) >= 2048 || isset($seen[$location])) return false;
			$seen[$location] = true;
			$urlNode = $doc->createElement('url');
			$locationNode = $doc->createElement('loc');
			$locationNode->appendChild($doc->createTextNode($this->xmlText($location)));
			$urlNode->appendChild($locationNode);
			if (is_string($lastModified) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $lastModified) === 1) {
				$lastModifiedNode = $doc->createElement('lastmod');
				$lastModifiedNode->appendChild($doc->createTextNode($lastModified));
				$urlNode->appendChild($lastModifiedNode);
			}
			$urlset->appendChild($urlNode);
			return true;
		};

		$count = 0;
		$homepage = $site->homepage();
		// A configured homepage is emitted in the page loop, with its own
		// noindex and lastmod settings. The listing homepage has no lastmod.
		if ($homepage === false || !$pages->isPubliclyAccessible($homepage)) {
			$count = $appendUrl(DOMAIN_BASE) ? 1 : 0;
		}
		$list = $pages->getPublicList(1, -1, true, true, true);
		foreach (is_array($list) ? $list : array() as $pageKey) {
			if ($count >= 50000) break;
			if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
			try {
				$page = new Page($pageKey);
				$lastModified = $page->dateModified(SITEMAP_DATE_FORMAT);
				if (!is_string($lastModified) || $lastModified === '') $lastModified = $page->date(SITEMAP_DATE_FORMAT);
				if (!$page->noindex() && $appendUrl(Theme::canonicalPageUrl($page), $lastModified)) $count++;
			} catch (Throwable $e) {
				// Skip malformed page records.
			}
		}

		// Public taxonomy archives are canonical, indexable URLs too. Add only
		// non-empty archives and keep the sitemap protocol's 50,000 URL ceiling.
		foreach (array(array($categories, DOMAIN_CATEGORIES), array($tags, DOMAIN_TAGS)) as $taxonomy) {
			if ($count >= 50000) break;
			if (!is_object($taxonomy[0]) || !method_exists($taxonomy[0], 'getDB') ||
				!method_exists($taxonomy[0], 'numberOfPages')) continue;
			$db = $taxonomy[0]->getDB();
			foreach (is_array($db) ? $db : array() as $key => $record) {
				if ($count >= 50000) break 2;
				if (!is_string($key) || $key === '' || strlen($key) > 200 || !is_array($record) ||
					$taxonomy[0]->numberOfPages($key) < 1) continue;
				if ($appendUrl(rtrim($taxonomy[1], '/') . '/' . rawurlencode($key))) $count++;
			}
		}

		$xml = $doc->saveXML();
		if (!is_string($xml) || $xml === '' || strlen($xml) > 33554432) return false;
		return Filesystem::atomicWrite($this->workspace() . 'sitemap.xml', $xml, 0644);
	}


	public function afterCategoryCreate() { return $this->refreshGeneratedArtifact(false); }
	public function afterCategoryModify() { return $this->refreshGeneratedArtifact(false); }
	public function afterCategoryDelete() { return $this->refreshGeneratedArtifact(false); }

}
