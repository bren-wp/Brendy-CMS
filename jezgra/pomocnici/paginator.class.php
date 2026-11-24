<?php defined('BRENDY') or die('Brendy CMS.');

class Paginator {

	public static $pager = array(
		'itemsPerPage'=>0,
		'numberOfPages'=>1,
		'numberOfItems'=>0,
		'firstPage'=>1,
		'nextPage'=>1,
		'prevPage'=>1,
		'currentPage'=>1,
		'showPrev'=>false,
		'showNext'=>false,
		'showNextPrev'=>false
	);

	public static function set($key, $value)
	{
		self::$pager[$key] = $value;
	}

	public static function get($key)
	{
		return self::$pager[$key];
	}

	public static function numberOfPages()
	{
		return self::get('numberOfPages');
	}

	public static function currentPage()
	{
		return self::get('currentPage');
	}

	public static function nextPage()
	{
		return self::get('nextPage');
	}

	public static function prevPage()
	{
		return self::get('prevPage');
	}

	public static function showNext()
	{
		return self::get('showNext');
	}

	public static function showPrev()
	{
		return self::get('showPrev');
	}

	public static function firstPage()
	{
		return self::get('firstPage');
	}

	/** Return a bounded page-number window with first/last anchors. */
	public static function window($current, $pages, $radius = 2)
	{
		$pages = filter_var($pages, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 1000000)));
		$current = filter_var($current, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 1000000)));
		$radius = filter_var($radius, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 10)));
		if ($pages === false || $current === false || $radius === false) return array();
		$current = min($pages, $current);
		$window = array(1, $pages);
		for ($page = max(1, $current - $radius); $page <= min($pages, $current + $radius); $page++) {
			$window[] = $page;
		}
		$window = array_values(array_unique($window));
		sort($window, SORT_NUMERIC);
		return $window;
	}

	// Returns the absolute URL for the first page
	public static function firstPageUrl()
	{
		return self::numberUrl( self::firstPage() );
	}

	// Returns the absolute URL for the last page
	public static function lastPageUrl()
	{
		return self::numberUrl( self::numberOfPages() );
	}

	// Returns the absolute URL for the next page
	public static function nextPageUrl()
	{
		return self::numberUrl( self::nextPage() );
	}

	// Returns the absolute URL for the previous page
	public static function previousPageUrl()
	{
		return self::numberUrl( self::prevPage() );
	}

	// Return the absoulte URL with the page number
	public static function numberUrl($pageNumber)
	{
		global $url;
		$pageNumber = filter_var($pageNumber, FILTER_VALIDATE_INT);
		if ($pageNumber === false || $pageNumber < 1 || $pageNumber > 1000000) $pageNumber = 1;
		$domain = rtrim(DOMAIN_BASE, '/');
		$filter = trim((string)$url->activeFilter(), '/');
		$slug = (string)$url->slug();
		if (strlen($slug) > 4096 || preg_match('/[\x00-\x1F\x7F\\\\]/', $slug)) $slug = '';
		$filterSegments = array();
		foreach (explode('/', $filter) as $segment) {
			if ($segment !== '') $filterSegments[] = rawurlencode($segment);
		}
		$slugSegments = array();
		foreach (explode('/', trim($slug, '/')) as $segment) {
			if ($segment !== '') $slugSegments[] = rawurlencode($segment);
		}
		$path = empty($filterSegments) ? '' : '/' . implode('/', $filterSegments);
		if (!empty($slugSegments)) {
			$path .= '/' . implode('/', $slugSegments);
		} elseif (!empty($filterSegments)) {
			$path .= '/';
		}
		$baseUrl = $domain . ($path === '' ? '/' : $path);

		// Preserve the explicit GET search query while paging. The search route also
		// supports path-based terms, but form submissions use ?q=... and previously
		// lost that term as soon as the visitor opened page 2. Only the known search
		// parameter is carried forward; arbitrary query parameters are deliberately
		// excluded from generated internal links.
		$query = array();
		if (method_exists($url, 'whereAmI') && $url->whereAmI() === 'search' && isset($_GET['q']) && is_scalar($_GET['q'])) {
			$searchQuery = trim((string)$_GET['q']);
			if ($searchQuery !== '' && strlen($searchQuery) <= 800 && preg_match('/[\x00-\x1F\x7F]/', $searchQuery) !== 1) {
				$searchQuery = mb_substr($searchQuery, 0, 200, CHARSET);
				if ($searchQuery !== '') $query['q'] = $searchQuery;
			}
		}
		if ($pageNumber !== self::firstPage()) $query['page'] = $pageNumber;
		return empty($query) ? $baseUrl : $baseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	public static function html($textPrevPage=false, $textNextPage=false, $showPageNumber=false)
	{
		global $L;

		$html  = '<nav id="paginator" aria-label="' . Theme::escape($L->g('Page navigation')) . '">';
		$html .= '<ul>';

		if (self::showPrev()) {
			if ($textPrevPage === false) $textPrevPage = '« ' . $L->g('Previous page');
			$html .= '<li class="left"><a rel="prev" href="' . Theme::escape(self::previousPageUrl()) . '">' .
				Theme::escape($textPrevPage) . '</a></li>';
		}

		if ($showPageNumber) {
			$html .= '<li class="list">' . (int)self::currentPage() . ' / ' . (int)self::numberOfPages() . '</li>';
		}

		if (self::showNext()) {
			if ($textNextPage === false) $textNextPage = $L->g('Next page') . ' »';
			$html .= '<li class="right"><a rel="next" href="' . Theme::escape(self::nextPageUrl()) . '">' .
				Theme::escape($textNextPage) . '</a></li>';
		}

		$html .= '</ul></nav>';
		return $html;
	}

	/*
	 * Bootstrap Pagination
	 */
	public static function bootstrap_html($textPrevPage=false, $textNextPage=false, $showPageNumber=false)
	{
		global $language;

		$totalPages = max(1, (int)self::numberOfPages());
		$currentPage = max(1, min($totalPages, (int)self::currentPage()));
		$howMany = 2;
		$previousLabel = $textPrevPage === false ? $language->get('Previous') : (string)$textPrevPage;
		$nextLabel = $textNextPage === false ? $language->get('Next') : (string)$textNextPage;

		if ($totalPages <= 1 && !$showPageNumber) return '';

		$html = '<nav aria-label="' . Theme::escape($language->get('Page navigation')) . '">';
		$html .= '<ul class="pagination">';

		if ($currentPage > 3 || $currentPage === $totalPages) {
			$html .= '<li class="page-item"><a class="page-link" href="' . Theme::escape(self::firstPageUrl()) .
				'" aria-label="' . Theme::escape($language->get('First')) . '"><span aria-hidden="true">&laquo;</span> ' .
				Theme::escape($language->get('First')) . '</a></li>';
		}
		if ($currentPage > 1) {
			$html .= '<li class="page-item"><a class="page-link" rel="prev" href="' . Theme::escape(self::previousPageUrl()) .
				'" aria-label="' . Theme::escape($previousLabel) . '"><span aria-hidden="true">&laquo;</span> ' .
				Theme::escape($previousLabel) . '</a></li>';
		}
		if ($currentPage > $howMany + 1) {
			$html .= '<li class="page-item disabled" aria-hidden="true"><span class="page-link">&hellip;</span></li>';
		}

		for ($pageIndex = $currentPage - $howMany; $pageIndex <= $currentPage + $howMany; $pageIndex++) {
			if ($pageIndex < 1 || $pageIndex > $totalPages) continue;
			$active = $pageIndex === $currentPage;
			$html .= '<li class="page-item' . ($active ? ' active' : '') . '"><a class="page-link" href="' .
				Theme::escape(self::numberUrl($pageIndex)) . '"' . ($active ? ' aria-current="page"' : '') . '>' .
				(int)$pageIndex . '</a></li>';
		}

		if ($currentPage + $howMany < $totalPages - 1) {
			$html .= '<li class="page-item disabled" aria-hidden="true"><span class="page-link">&hellip;</span></li>';
		}
		if ($currentPage < $totalPages) {
			$html .= '<li class="page-item"><a class="page-link" rel="next" href="' . Theme::escape(self::nextPageUrl()) .
				'" aria-label="' . Theme::escape($nextLabel) . '">' . Theme::escape($nextLabel) .
				' <span aria-hidden="true">&raquo;</span></a></li>';
			$html .= '<li class="page-item"><a class="page-link" href="' . Theme::escape(self::lastPageUrl()) .
				'">' . Theme::escape($language->get('Last')) . '</a></li>';
		}
		if ($showPageNumber) {
			$html .= '<li class="page-item disabled"><span class="page-link" aria-label="' .
				Theme::escape($currentPage . ' / ' . $totalPages) . '">' . (int)$currentPage . ' / ' . (int)$totalPages . '</span></li>';
		}

		$html .= '</ul></nav>';
		return $html;
	}

}
