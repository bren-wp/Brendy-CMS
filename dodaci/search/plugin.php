<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginSearch extends BrendyPlugin
{

	private $pagesFound = array();
	private $numberOfItems = 0;
	private $searchTerm = '';
	private $formSequence = 0;

	public function init()
	{
		// Fields and default values for the database of this plugin
		$this->dbFields = array(
			'label' => 'Search',
			'minChars' => 3,
			'wordsToCachePerPage' => 800,
			'showButtonSearch' => false,
			'highlightResults' => true,
			'showResultCount' => true,
			'searchInTags' => true,
			'searchInCategories' => true,
			'excerptLength' => 200
		);
	}

	public function form()
	{
		$html = $this->settingsIntro();
		$html .= $this->settingsLabelField();
		$html .= $this->settingsNumberField('minChars', 'Minimum number of characters when searching', 1, 100);
		$html .= $this->settingsNumberField('wordsToCachePerPage', 'Words to cache per page', 50, 2000);
		$html .= $this->settingsBooleanField('showButtonSearch', 'Show button search');
		$html .= $this->settingsBooleanField('highlightResults', 'Highlight search terms in results');
		$html .= $this->settingsBooleanField('showResultCount', 'Show result count');
		$html .= $this->settingsBooleanField('searchInTags', 'Search in tags');
		$html .= $this->settingsBooleanField('searchInCategories', 'Search in categories');
		$html .= $this->settingsNumberField('excerptLength', 'Excerpt length (characters)', 50, 500);
		return $html;
	}

	// HTML for sidebar
	public function siteSidebar()
	{
		global $L;
		$label = $this->getValue('label', false);
		$searchText = $L->get('Search');
		$searchTextEscaped = Theme::escape($searchText);
		$minChars = max(1, min(100, (int) $this->getValue('minChars')));

		$html  = '<div class="plugin plugin-search">';
		$html .= $this->sidebarHeading($label);
		$html .= '<div class="plugin-content">';
		$html .= '<form class="search-plugin-form" role="search" action="' . Theme::escape(DOMAIN_BASE . 'search/') . '" method="get" data-brendy-search-form data-search-base="' . Theme::escape(DOMAIN_BASE . 'search/') . '" data-min-chars="' . $minChars . '">';
		$inputId = 'jsbrendySearchText-' . (++$this->formSequence);
		$html .= '<label for="' . $inputId . '" class="brendy-visually-hidden">' . $searchTextEscaped . '</label>';
		$html .= '<input type="search" id="' . $inputId . '" name="q" maxlength="200" minlength="' . $minChars . '" placeholder="' . $searchTextEscaped . '..." autocomplete="off" enterkeyhint="search" dir="auto" aria-label="' . $searchTextEscaped . '">';
		if ($this->getValue('showButtonSearch')) {
			$html .= '<button type="submit" class="brendy-icon-label"><i class="bi bi-search" aria-hidden="true"></i>' . $searchTextEscaped . '</button>';
		}
		$html .= '</form>';
		$html .= '</div>';
		$html .= '</div>';


		return $html;
	}

	// Inject CSS styles for search results (works with any theme)
	public function siteHead()
	{
		$output = $this->includeCSS('stil.css');
		if ($this->webhook('search', false, false) && $this->getValue('highlightResults')) {
			$output .= $this->includeJS('search.js');
		}
		return $output;
	}

	// Display search header before content
	public function siteBodyBegin()
	{
		$webhook = 'search';
		if (!$this->webhook($webhook, false, false)) {
			return false;
		}

		global $L;
		$searchTerm = $this->getSearchTerm();
		$searchTermEscaped = Theme::escape($searchTerm);
		$count = $this->numberOfItems;

		if ($searchTerm === '') {
			return '';
		}

		$html = '<div class="container">';
		$html .= '<div class="search-results-header">';
		$html .= '<h1>' . Theme::escape($L->get('Search results for')) . ' "<span class="search-query">' . $searchTermEscaped . '</span>"</h1>';

		if ($this->getValue('showResultCount')) {
			if ($count === 0) {
				$html .= '<p class="search-count">' . Theme::escape($L->get('No pages found')) . '</p>';
			} elseif ($count === 1) {
				$html .= '<p class="search-count">1 ' . Theme::escape(Text::lowercase($L->get('result found'))) . '</p>';
			} else {
				$html .= '<p class="search-count">' . (int) $count . ' ' . Theme::escape(Text::lowercase($L->get('results found'))) . '</p>';
			}
		}

		$html .= '</div>';
		$html .= '</div>';

		// If no results, show helpful message
		if ($count === 0) {
			$minChars = max(1, min(100, (int) $this->getValue('minChars')));
			$html .= '<div class="container">';
			$html .= '<div class="search-no-results">';
			$html .= '<h2>' . Theme::escape($L->get('No results found')) . '</h2>';
			$html .= '<p>' . Theme::escape(sprintf($L->get('no-pages-found-for-the-search'), $searchTerm)) . '</p>';
			$html .= '<div class="search-suggestions">';
			$html .= '<h3>' . Theme::escape($L->get('Suggestions')) . ':</h3>';
			$html .= '<ul>';
			$html .= '<li>' . Theme::escape($L->get('Check your spelling')) . '</li>';
			$html .= '<li>' . Theme::escape($L->get('Try different keywords')) . '</li>';
			$html .= '<li>' . Theme::escape($L->get('Try more general keywords')) . '</li>';
			$html .= '</ul>';
			$html .= '</div>';
			$html .= '<div class="search-inline-form">';
			$html .= '<form role="search" action="' . Theme::escape(DOMAIN_BASE . 'search/') . '" method="get" data-brendy-search-form data-search-base="' . Theme::escape(DOMAIN_BASE . 'search/') . '" data-min-chars="' . $minChars . '">';
			$searchLabel = Theme::escape($L->get('Search'));
			$html .= '<input type="search" id="searchAgainInput" name="q" maxlength="200" minlength="' . $minChars . '" value="' . $searchTermEscaped . '" placeholder="' . $searchLabel . '..." autocomplete="off" enterkeyhint="search" aria-label="' . $searchLabel . '">';
			$html .= '</form>';
			$html .= '</div>';
			$html .= '</div>';
			$html .= '</div>';
		}

		return $html;
	}

	public function install($position = 0)
	{
		if (!parent::install($position)) return false;
		if ($this->createCache()) return true;
		parent::uninstall();
		return false;
	}

	// Enforce per-field bounds before one atomic settings write, then rebuild the cache.
	protected function integerSettingBounds()
	{
		return array(
			'minChars' => array(1, 100),
			'wordsToCachePerPage' => array(50, 2000),
			'excerptLength' => array(50, 500)
		);
	}

	public function post()
	{
		return $this->postWithGeneratedArtifact(function () {
			return $this->createCache();
		});
	}

	public function afterPageCreate()
	{
		$this->createCache();
	}

	public function afterPageModify()
	{
		$this->createCache();
	}

	public function afterPageDelete()
	{
		$this->createCache();
	}

	public function afterCategoryCreate()
	{
		return $this->createCache();
	}

	public function afterCategoryModify()
	{
		return $this->createCache();
	}

	public function afterCategoryDelete()
	{
		return $this->createCache();
	}

	// Get the current search term
	public function getSearchTerm()
	{
		return $this->searchTerm;
	}

	// Get the number of results
	public function getResultCount()
	{
		return $this->numberOfItems;
	}

	public function beforeAll()
	{
		// Check if the URL match with the webhook
		$webhook = 'search';
		if ($this->webhook($webhook, false, false)) {
			global $site;
			global $url;

			// Change the whereAmI to avoid load pages in the rule 69.pages
			// This is only for performance purpose
			$url->setWhereAmI('search');

			// Get the string to search from the URL
			$stringToSearch = $this->webhook($webhook, true, false);
			$stringToSearch = is_string($stringToSearch) ? trim($stringToSearch, '/') : '';
			if ($stringToSearch === '' && isset($_GET['q']) && is_string($_GET['q'])) {
				$stringToSearch = $_GET['q'];
			}
			$stringToSearch = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $stringToSearch);
			if (!is_string($stringToSearch)) {
				$stringToSearch = '';
			}
			$stringToSearch = mb_substr(trim($stringToSearch), 0, 200, 'UTF-8');
			$this->searchTerm = $stringToSearch;

			// Search the string in the cache and get all pages with matches
			$list = $this->search($stringToSearch);
			$this->numberOfItems = count($list);

			// Split the content in pages
			// The first page number is 1, so the real is 0
			$pageNumber = max(1, (int)$url->pageNumber());
			$realPageNumber = $pageNumber - 1;
			$itemsPerPage = $site->itemsPerPage();
			if ($itemsPerPage <= 0) {
				if ($realPageNumber === 0) {
					$this->pagesFound = $list;
				} else {
					$url->setNotFound();
					return;
				}
			} else {
				// Keep only the requested page in memory. The previous chunking path
				// duplicated the complete result set although the paginator renders one page.
				$offset = $realPageNumber * $itemsPerPage;
				if ($offset < $this->numberOfItems) {
					$this->pagesFound = array_slice($list, $offset, $itemsPerPage);
				} elseif ($realPageNumber > 0) {
					$url->setNotFound();
					return;
				}
			}
		}
	}

	public function paginator()
	{
		$webhook = 'search';
		if ($this->webhook($webhook, false, false)) {
			// Get the pre-defined variable from the rule 99.paginator.php
			// Is necessary to change this variable to fit the paginator with the result from the search
			global $numberOfItems;
			$numberOfItems = $this->numberOfItems;
		}
	}

	public function beforeSiteLoad()
	{
		global $url;
		if (is_object($url) && $url->notFound()) return false;
		$webhook = 'search';
		if ($this->webhook($webhook, false, false)) {
			global $WHERE_AM_I;
			$WHERE_AM_I = 'search';

			// Get the pre-defined variable from the rule 69.pages.php
			// We change the content to show in the website
			global $content;
			$content = array();
			foreach ($this->pagesFound as $pageKey) {
				try {
					$page = new Page($pageKey);
					array_push($content, $page);
				} catch (Throwable $e) {
					// continue
				}
			}
		}
	}

	/**
	 * Highlight search terms in text
	 */
	public function highlightTerms($text, $searchTerm)
	{
		$text = is_scalar($text) ? (string)$text : '';
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, CHARSET);
		$escape = static function ($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); };
		if (!$this->getValue('highlightResults')) return $escape($text);
		$words = $this->tokenizeSearchTerms($searchTerm);
		if (empty($words)) return $escape($text);
		usort($words, static function ($a, $b) { return strlen($b) <=> strlen($a); });
		$pattern = '/(' . implode('|', array_map(static function ($word) { return preg_quote($word, '/'); }, $words)) . ')/iu';
		$parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($parts)) return $escape($text);
		$output = '';
		foreach ($parts as $index => $part) {
			$safe = $escape($part);
			$output .= $index % 2 === 1 ? '<mark class="search-highlight">' . $safe . '</mark>' : $safe;
		}
		return $output;
	}

	/**
	 * Get excerpt around the search term
	 */
	public function getSearchExcerpt($content, $searchTerm, $length = null)
	{
		if ($length === null) {
			$length = $this->getValue('excerptLength');
		}

		$length = max(50, min(500, (int)$length));
		$content = Text::visibleHtmlText($content);

		// Find the first occurrence of any bounded search term.
		$words = $this->tokenizeSearchTerms($searchTerm);

		$firstPos = false;
		foreach ($words as $word) {
			$pos = mb_stripos($content, $word, 0, 'UTF-8');
			if ($pos !== false && ($firstPos === false || $pos < $firstPos)) {
				$firstPos = $pos;
			}
		}

		// Calculate start position (center the found term)
		$start = $firstPos === false ? 0 : max(0, $firstPos - (int)($length / 2));

		// Adjust to not cut words
		if ($start > 0) {
			$spacePos = mb_strpos($content, ' ', $start, 'UTF-8');
			if ($spacePos !== false && $spacePos < $start + 20) {
				$start = $spacePos + 1;
			}
		}

		// Extract excerpt
		$excerpt = mb_substr($content, $start, $length, 'UTF-8');

		// Clean up beginning and end
		if ($start > 0) {
			$excerpt = '...' . $excerpt;
		}
		if ($start + $length < mb_strlen($content, 'UTF-8')) {
			// Don't cut in the middle of a word
			$lastSpace = mb_strrpos($excerpt, ' ', 0, 'UTF-8');
			if ($lastSpace !== false && $lastSpace > $length - 30) {
				$excerpt = mb_substr($excerpt, 0, $lastSpace, 'UTF-8');
			}
			$excerpt .= '...';
		}

		return htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Return a small, normalized set of non-empty search terms. */
	private function tokenizeSearchTerms($searchTerm)
	{
		if (!is_string($searchTerm) || $searchTerm === '') return array();
		$words = preg_split('/\s+/u', trim($searchTerm), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($words)) return array();
		$unique = array();
		foreach ($words as $word) {
			$word = mb_substr($word, 0, 100, 'UTF-8');
			if ($word === '') continue;
			$key = mb_strtolower($word, 'UTF-8');
			if (isset($unique[$key])) continue;
			$unique[$key] = $word;
			if (count($unique) >= 8) break;
		}
		return array_values($unique);
	}

	// Generate the cache file
	// This function is necessary to call it when you create, edit or remove content
	private function createCache()
	{
		return $this->withWorkspaceLock('search-cache', function () {
			return $this->createCacheUnlocked();
		});
	}

	private function createCacheUnlocked()
	{
		// Get all pages published
		global $pages;
		global $categories;

		$list = $pages->getPublicList(1, -1, true, true, true);
		$list = is_array($list) ? array_slice($list, 0, 5000) : array();

		$cache = array();
		$words = max(50, min(2000, (int)$this->getValue('wordsToCachePerPage'))) * 5;
		$searchTags = $this->getValue('searchInTags');
		$searchCategories = $this->getValue('searchInCategories');
		$categoryNames = array();
		foreach ($list as $pageKey) {
			if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
			$page = buildPage($pageKey);
			if (!($page instanceof Page)) continue;

			// Process content
			$content = $page->content();
			$content = Text::visibleHtmlText($content);
			$content = Text::truncate($content, $words, '');

			// Include the page to the cache
			$cache[$pageKey]['title'] = Sanitize::htmlDecode($page->title());
			$cache[$pageKey]['description'] = Sanitize::htmlDecode($page->description());
			$cache[$pageKey]['content'] = $content;

			// Add tags if enabled
			if ($searchTags) {
				$pageTags = $page->tags(true);
				if (!empty($pageTags)) {
					$cache[$pageKey]['tags'] = Sanitize::htmlDecode(implode(' ', $pageTags));
				}
			}

			// Add category if enabled
			if ($searchCategories) {
				$categoryKey = $page->categoryKey();
				if (is_string($categoryKey) && $categoryKey !== '' && isset($categories)) {
					if (!array_key_exists($categoryKey, $categoryNames)) {
						try { $categoryNames[$categoryKey] = Sanitize::htmlDecode((new Category($categoryKey))->name()); }
						catch (Throwable $e) { $categoryNames[$categoryKey] = null; }
					}
					if ($categoryNames[$categoryKey] !== null) $cache[$pageKey]['category'] = $categoryNames[$categoryKey];
				}
			}
		}

		// Generate JSON file with the cache
		try {
			$json = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			Log::set(__METHOD__ . LOG_SEP . 'Search cache source contains invalid UTF-8.', LOG_TYPE_ERROR);
			return false;
		}
		if (!is_string($json) || strlen($json) > 67108864) return false;
		$workspace = $this->workspace();
		if (!Filesystem::directoryExists($workspace) && !Filesystem::mkdir($workspace, true)) return false;
		if (!Filesystem::directoryExists($workspace)) return false;
		$cacheFile = $this->cacheFile();
		if (is_link($cacheFile) || (file_exists($cacheFile) && !is_file($cacheFile))) return false;
		return Filesystem::atomicWrite($cacheFile, $json, 0600);
	}

	// Returns the absolute path of the cache file
	private function cacheFile()
	{
		return $this->workspace() . 'cache.json';
	}

	// Search text inside the cache
	// Returns an array with the pages keys related to the text
	// The array is sorted by score
	private function search($text)
	{
		$text = trim($text);

		// Check minimum characters
		$minChars = max(1, min(100, (int)$this->getValue('minChars')));
		if (mb_strlen($text, 'UTF-8') < $minChars) {
			return array();
		}

		// Read the cache file
		$cacheFile = $this->cacheFile();
		$dependencies = array(DB_PAGES, DB_CATEGORIES, $this->filenameDb, __FILE__, PATH_KERNEL . 'release.php');
		if (generatedFileNeedsRefresh($cacheFile, $dependencies)) {
			// Do not disclose removed terms through the ranking of a still-public
			// page if the index could not be rebuilt from current content.
			if (!$this->createCache()) return array();
		}

		$json = Filesystem::readFileBounded($cacheFile, 67108864, 2);
		if (!is_string($json)) return array();
		$cache = json_decode($json, true, 32);

		if (!is_array($cache) || empty($cache) || count($cache) > 5000) {
			return array();
		}

		// Linear, bounded relevance scoring. The former fuzzy implementation built
		// quadratic dynamic-programming matrices for every field of every page,
		// allowing a public search request to exhaust CPU and memory on larger sites.
		$needle = mb_strtolower($text, CHARSET);
		$terms = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($terms)) return array();
		$terms = array_slice(array_values(array_unique($terms)), 0, 8);
		if (empty($terms)) return array();

		$weights = array('title' => 0, 'tags' => 100, 'category' => 100, 'description' => 250, 'content' => 1000);
		$needsTermFallback = count($terms) > 1 || $terms[0] !== $needle;
		$scores = array();
		foreach ($cache as $pageKey => $data) {
			if (!is_string($pageKey) || strlen($pageKey) > 200 || !is_array($data)) continue;
			$fields = array();
			foreach ($weights as $field => $weight) {
				$value = isset($data[$field]) && is_string($data[$field]) ? $data[$field] : '';
				$fields[$field] = mb_strtolower($value, CHARSET);
			}

			$best = false;
			foreach ($weights as $field => $weight) {
				$position = strpos($fields[$field], $needle);
				if ($position !== false) {
					$score = $weight + min($position, 100000);
					$best = ($best === false) ? $score : min($best, $score);
				}
			}

			if ($best === false && $needsTermFallback) {
				$haystack = implode("\n", $fields);
				$termScore = 100000;
				foreach ($terms as $term) {
					$position = strpos($haystack, $term);
					if ($position === false) {
						$termScore = false;
						break;
					}
					$termScore += min($position, 100000);
				}
				$best = $termScore;
			}

			if ($best !== false) $scores[$pageKey] = $best;
		}

		asort($scores, SORT_NUMERIC);

		// A generated cache is an optimization, never an authorization source. If a
		// page visibility changed after cache generation,
		// verify every candidate against the current pages DB before it can enter the
		// public result set. This also drops deleted/corrupt stale cache rows.
		global $pages;
		$result = array();
		foreach (array_keys($scores) as $pageKey) {
			if (!isset($pages) || !is_object($pages) || !method_exists($pages, 'isPubliclyAccessible')) break;
			if ($pages->isPubliclyAccessible($pageKey)) $result[] = $pageKey;
		}
		return $result;
	}
}
