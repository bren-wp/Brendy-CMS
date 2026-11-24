<?php defined('BRENDY') or die('Brendy CMS.');

class Url
{
	protected $uri;
	protected $uriStrlen;
	protected $whereAmI;
	protected $slug;
	protected $filters; // Filters for the URI
	protected $notFound;
	protected $parameters;
	protected $activeFilter;
	protected $httpCode;
	protected $httpMessage;

	function __construct()
	{
		$requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
			? $_SERVER['REQUEST_URI'] : '/';
		if (strlen($requestUri) > 8192 || preg_match('/[\x00-\x1F\x7F\\\\]/', $requestUri)) {
			$requestUri = '/__invalid_request_uri__';
		}
		// Decode only the path. Reject malformed percent escapes and encoded path
		// separators first so proxies/web servers and Brendy cannot disagree about
		// route boundaries (request smuggling/path-confusion class of bugs).
		$requestPath = explode('?', $requestUri, 2)[0];
		if (preg_match('/%(?![0-9A-Fa-f]{2})/', $requestPath) || preg_match('/%(?:2f|5c)/i', $requestPath)) {
			$requestPath = '/__invalid_request_uri__';
		}
		$decode = rawurldecode($requestPath);
		if (preg_match('/[\x00-\x1F\x7F\\\\]/', $decode) ||
			preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decode)) {
			$decode = '/__invalid_request_uri__';
		}

		// Remove parameters GET, I don't use parse_url because has problem with utf-8
		$this->uri = $decode;
		$this->parameters = $_GET;
		$this->uriStrlen = Text::length($this->uri);
		$this->whereAmI = 'home';
		$this->notFound = false;
		$this->slug = '';
		$this->filters = array();
		$this->activeFilter = '';
		$this->httpCode = 200;
		$this->httpMessage = 'OK';
	}

	// Filters change for different languages
	// Ex (Spanish): Array('post'=>'/publicacion/', 'tag'=>'/etiqueta/', ....)
	// Ex (English): Array('post'=>'/post/', 'tag'=>'/tag/', ....)
	public function checkFilters($filters)
	{
		// Routing configuration is persisted JSON and can be damaged by a partial
		// manual migration or filesystem corruption. Fail closed instead of letting
		// array access / mbstring receive an unexpected type and take down routing.
		if (!is_array($filters) || count($filters) > 32 || !isset($filters['admin']) || !is_string($filters['admin'])) {
			$this->setNotFound();
			return false;
		}
		foreach ($filters as $filterName => $filterURI) {
			if (!is_string($filterName) || $filterName === '' || strlen($filterName) > 64 ||
				!is_string($filterURI) || $filterURI === '' || strlen($filterURI) > 512 ||
				$filterURI[0] !== '/' || strpos($filterURI, "\0") !== false || strpos($filterURI, "\\") !== false ||
				preg_match('/[\x00-\x1F\x7F?#]/', $filterURI) === 1) {
				$this->setNotFound();
				return false;
			}
		}

		// Put the "admin" filter first
		$adminFilter = array('admin' => $filters['admin']);
		unset($filters['admin']);
		uasort($filters, array($this, 'sortByLength'));
		$this->filters = $adminFilter + $filters;

		foreach ($this->filters as $filterName=>$filterURI) {
			// $filterName = 'category'
			// $filterURI = '/category/'
			// $filterFull = '/base_url/category/'
			$filterFull = ltrim($filterURI, '/');
			$filterFull = HTML_PATH_ROOT.$filterFull;
			$filterFullLength = Text::length($filterFull);

			$subString = mb_substr($this->uri, 0, $filterFullLength, CHARSET);

			// Compare against the complete base-aware path. Comparing only the raw
			// filter broke canonical handling for installations in a subdirectory.
			if ($this->uri === rtrim($filterFull, '/')) {
				$this->setNotFound();
				return false;
			}

			// Check coincidence with complete filterURI
			if ($subString === $filterFull) {
				$this->slug = mb_substr($this->uri, $filterFullLength);
				$this->setWhereAmI($filterName);
				$this->activeFilter = $filterURI;

				if ($this->slug === '' && ($filterName === 'blog')) {
					$this->setWhereAmI('blog');
				} elseif ($this->slug !== '' && ($filterName === 'blog')) {
					$this->setNotFound();
					return false;
				} elseif ($this->slug === '' && ($filterURI === '/')) {
					$this->setWhereAmI('home');
				} elseif ($this->slug !== '' && ($filterURI === '/')) {
					$this->setWhereAmI('page');
				} elseif ($filterName === 'admin') {
					$this->slug = ltrim($this->slug, '/');
				}

				return true;
			}
		}

		$this->setNotFound();
		return false;
	}

	public function slug()
	{
		return $this->slug;
	}

	public function setSlug($slug)
	{
		if (!is_string($slug) || strlen($slug) > 4096 || strpos($slug, "\0") !== false) return false;
		$this->slug = $slug;
		return true;
	}

	public function activeFilter()
	{
		return $this->activeFilter;
	}

	public function explodeSlug($delimiter="/")
	{
		return explode($delimiter, $this->slug);
	}

	public function uri()
	{
		return $this->uri;
	}

	// Return the filter of type (e.g. tag => /tag/, category => /category/, etc.)
	public function filters($type, $trim=true)
	{
		if (!is_string($type) || !isset($this->filters[$type]) || !is_string($this->filters[$type])) {
			return false;
		}
		$filter = $this->filters[$type];
		if ($trim) {
			$filter = trim($filter, '/');
		}
		return $filter;
	}

	// Returns where is the user, home, pages, categories, tags..
	public function whereAmI()
	{
		return $this->whereAmI;
	}

	public function setWhereAmI($where)
	{
		$GLOBALS['WHERE_AM_I'] = $where;
		$this->whereAmI = $where;
	}

	public function notFound()
	{
		return $this->notFound;
	}

	public function pageNumber()
	{
		if (isset($this->parameters['page']) && is_scalar($this->parameters['page'])) {
			$page = filter_var($this->parameters['page'], FILTER_VALIDATE_INT);
			if ($page !== false && $page >= 1 && $page <= 1000000) return (int)$page;
		}
		return 1;
	}

	public function parameter($field)
	{
		if (is_string($field) && isset($this->parameters[$field]) && is_scalar($this->parameters[$field])) {
			return $this->parameters[$field];
		}
		return false;
	}

	public function setNotFound()
	{
		$this->setWhereAmI('page');
		$this->notFound = true;
		$this->httpCode = 404;
		$this->httpMessage = 'Not Found';
	}

	public function httpCode()
	{
		return $this->httpCode;
	}

	public function setHttpCode($code = 200)
	{
		$this->httpCode = $code;
	}

	public function httpMessage()
	{
		return $this->httpMessage;
	}

	public function setHttpMessage($msg = 'OK')
	{
		$this->httpMessage = $msg;
	}

	private function sortByLength($a, $b)
	{
		return strlen($b)-strlen($a);
	}

}
