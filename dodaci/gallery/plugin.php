<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginGallery extends BrendyPlugin
{
	private $thumbnailCache = array();

	public function init()
	{
		$this->dbFields = array(
			'galleries' => '{}'
		);

		// Hide default Save/Cancel buttons, the plugin manages its own forms
		$this->formButtons = false;
	}

	public function install($position = 1)
	{
		if (!parent::install($position)) return false;
		$galleriesPath = $this->galleriesPath();
		if (!Filesystem::directoryExists($galleriesPath)) {
			if (!Filesystem::mkdir($galleriesPath, true)) {
				parent::uninstall();
				return false;
			}
		}
		return true;
	}

	private function galleriesPath()
	{
		return PATH_UPLOADS . 'galleries' . DS;
	}

	private function galleriesHtmlPath()
	{
		return HTML_PATH_UPLOADS . 'galleries/';
	}

	private function resolveThumbnail($galleryDir, $filename)
	{
		$cacheKey = $galleryDir . "\0" . $filename;
		if (array_key_exists($cacheKey, $this->thumbnailCache)) return $this->thumbnailCache[$cacheKey];
		$thumbDir = $galleryDir . 'thumbnails' . DS;
		$exact = $thumbDir . $filename;
		if (is_file($exact) && !is_link($exact)) return $this->thumbnailCache[$cacheKey] = $filename;

		$base = pathinfo($filename, PATHINFO_FILENAME);
		$candidate = $base . '.jpg';
		$candidatePath = $thumbDir . $candidate;
		$originalCandidate = $galleryDir . $candidate;
		if ($candidate !== $filename && is_file($candidatePath) && !is_link($candidatePath) && !is_file($originalCandidate)) {
			return $this->thumbnailCache[$cacheKey] = $candidate;
		}
		$this->thumbnailCache[$cacheKey] = null;
		return null;
	}

	private function imageAltText($filename, $fallback = '')
	{
		$stem = is_string($filename) ? pathinfo($filename, PATHINFO_FILENAME) : '';
		$alt = preg_replace('/[-_]+/u', ' ', $stem);
		if (!is_string($alt)) $alt = '';
		$alt = preg_replace('/\s+/u', ' ', trim($alt));
		if (!is_string($alt) || !Theme::hasText($alt)) $alt = is_scalar($fallback) ? trim((string)$fallback) : '';
		return mb_substr($alt, 0, 160, CHARSET);
	}

	private function getGalleries()
	{
		$raw = isset($this->db['galleries']) && is_string($this->db['galleries']) ? $this->db['galleries'] : '{}';
		$galleries = json_decode($raw, true, 32);
		if (!is_array($galleries)) {
			return array();
		}
		$clean = array();
		foreach (array_slice($galleries, 0, 1000, true) as $slug => $gallery) {
			if (!$this->validGallerySlug($slug) || !is_array($gallery)) {
				continue;
			}
			$name = isset($gallery['name']) && is_scalar($gallery['name']) ? (string)$gallery['name'] : '';
			$description = isset($gallery['description']) && is_scalar($gallery['description']) ? (string)$gallery['description'] : '';
			if ($name === '' || Text::length($name) > 200 || Text::length($description) > 1000) {
				continue;
			}
			$images = array();
			if (isset($gallery['images']) && is_array($gallery['images'])) {
				foreach (array_slice($gallery['images'], 0, 10000) as $image) {
					if (is_string($image) && preg_match('/^[A-Za-z0-9._-]+$/D', $image) === 1 &&
						!brendyHasDangerousExecutableFilename($image) &&
						in_array(Text::lowercase(Filesystem::extension($image)), $GLOBALS['ALLOWED_IMG_EXTENSION'], true)) {
						$images[] = $image;
					}
				}
			}
			$gallery['name'] = $name;
			$gallery['description'] = $description;
			$gallery['images'] = array_values(array_unique($images));
			$clean[$slug] = $gallery;
		}
		return $clean;
	}

	private function saveGalleries($galleries)
	{
		if (!is_array($galleries) || count($galleries) > 1000) return false;
		try {
			$json = json_encode($galleries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			Log::set(__METHOD__ . LOG_SEP . 'Gallery data contains invalid UTF-8.', LOG_TYPE_ERROR);
			return false;
		}
		if (!is_string($json) || strlen($json) > 16777216) return false;
		$before = $this->db;
		$this->db['galleries'] = $json;
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	private function scriptJson($value)
	{
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
		return is_string($json) ? $json : 'null';
	}


	private function validGallerySlug($slug)
	{
		return is_string($slug) && strlen($slug) <= 100 && preg_match('/^[a-z0-9-]+$/D', $slug) === 1;
	}


	private function generateSlug($name)
	{
		$slug = Text::removeSpecialCharacters($name, '-');
		$slug = Text::removeQuotes($slug);
		$slug = Text::removeSpaces($slug, '-');
		$slug = Text::lowercase($slug);
		return substr(trim($slug, '-'), 0, 100);
	}

	// =========================================================================
	// Admin form (Gallery management UI)
	// =========================================================================

	public function form()
	{
		global $L;

		$galleries = $this->getGalleries();
		$action = isset($_GET['action']) && is_string($_GET['action']) ? strtolower(trim($_GET['action'])) : 'list';
		if (!in_array($action, array('list', 'manage'), true)) $action = 'list';
		$gallerySlug = isset($_GET['gallery']) && is_string($_GET['gallery']) ? trim($_GET['gallery']) : '';
		if ($gallerySlug !== '' && !$this->validGallerySlug($gallerySlug)) {
			$gallerySlug = '';
		}

		$html = '';

		if ($action === 'manage' && $gallerySlug !== '' && isset($galleries[$gallerySlug])) {
			$html .= $this->formManageGallery($gallerySlug, $galleries[$gallerySlug]);
		} else {
			$html .= $this->formListGalleries($galleries);
		}

		return $html;
	}

	private function formListGalleries($galleries)
	{
		global $L;

		$html = '';

		// Create gallery form
		$html .= $this->settingsIntro();

		$html .= '<h5 class="mb-3">' . $L->get('create-gallery') . '</h5>';
		$html .= '<div class="row mb-4 align-items-end">';
		$html .= '<div class="col-sm-4">';
		$html .= '<input type="text" class="form-control" id="jsGalleryName" name="galleryName" placeholder="' . $L->get('gallery-name') . '" required>';
		$html .= '</div>';
		$html .= '<div class="col-sm-5">';
		$html .= '<input type="text" class="form-control" id="jsGalleryDescription" name="galleryDescription" placeholder="' . $L->get('gallery-description') . '">';
		$html .= '</div>';
		$html .= '<div class="col-sm-3">';
		$html .= '<button type="submit" name="createGallery" value="true" class="btn btn-sm btn-primary">' . $L->get('create-gallery') . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		// Gallery list
		$html .= '<h5 class="mb-3">' . $L->get('galleries') . '</h5>';

		if (empty($galleries)) {
			$html .= '<div class="alert alert-info" role="alert">' . $L->get('no-galleries') . '</div>';
		} else {
			$html .= '<div class="table-responsive">';
			$html .= '<table class="table table-striped">';
			$html .= '<thead><tr>';
			$html .= '<th>' . $L->get('gallery-name') . '</th>';
			$html .= '<th>' . $L->get('gallery-description') . '</th>';
			$html .= '<th>' . $L->get('images-count') . '</th>';
			$html .= '<th></th>';
			$html .= '</tr></thead>';
			$html .= '<tbody>';

			foreach ($galleries as $slug => $gallery) {
				$imageCount = isset($gallery['images']) ? count($gallery['images']) : 0;
				$manageUrl = HTML_PATH_ADMIN_ROOT . 'configure-plugin/BrendyPluginGallery?action=manage&gallery=' . rawurlencode($slug);
				$html .= '<tr>';
				$html .= '<td><strong>' . Sanitize::html($gallery['name']) . '</strong></td>';
				$html .= '<td>' . Sanitize::html($gallery['description']) . '</td>';
				$html .= '<td>' . $imageCount . ' ' . $L->get('images-count') . '</td>';
				$html .= '<td class="text-end">';
				$html .= '<a href="' . $manageUrl . '" class="btn btn-sm btn-outline-primary me-1">' . $L->get('manage-images') . '</a>';
				$confirmDelete = htmlspecialchars($L->get('delete-gallery-confirm'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
				$html .= '<button type="submit" name="deleteGallery" value="' . $slug . '" class="btn btn-sm btn-outline-danger" formnovalidate data-brendy-confirm="' . $confirmDelete . '">' . $L->get('delete-gallery') . '</button>';
				$html .= '</td>';
				$html .= '</tr>';
			}

			$html .= '</tbody></table></div>';
		}

		return $html;
	}

	private function formManageGallery($slug, $gallery)
	{
		global $L;
		global $security;

		$thumbnailsHtmlPath = $this->galleriesHtmlPath() . $slug . '/thumbnails/';
		$imagesHtmlPath = $this->galleriesHtmlPath() . $slug . '/';
		$galleryDir = $this->galleriesPath() . $slug . DS;
		$backUrl = HTML_PATH_ADMIN_ROOT . 'configure-plugin/BrendyPluginGallery';

		$html = '';

		// Back link
		$html .= '<a href="' . $backUrl . '" class="btn btn-sm btn-outline-secondary mb-3">&larr; ' . $L->get('back-to-galleries') . '</a>';

		// Edit gallery info
		$html .= '<div class="row mb-3 align-items-end">';
		$html .= '<div class="col-sm-3">';
		$html .= '<label class="small text-muted">' . $L->get('gallery-name') . '</label>';
		$html .= '<input type="text" class="form-control" name="editGalleryName" value="' . Sanitize::html($gallery['name']) . '" placeholder="' . $L->get('gallery-name') . '">';
		$html .= '</div>';
		$html .= '<div class="col-sm-3">';
		$html .= '<label class="small text-muted">' . $L->get('gallery-slug') . '</label>';
		$html .= '<input type="text" class="form-control" name="editGalleryNewSlug" value="' . Sanitize::html($slug) . '" placeholder="' . $L->get('gallery-slug') . '">';
		$html .= '</div>';
		$html .= '<div class="col-sm-3">';
		$html .= '<label class="small text-muted">' . $L->get('gallery-description') . '</label>';
		$html .= '<input type="text" class="form-control" name="editGalleryDescription" value="' . Sanitize::html($gallery['description']) . '" placeholder="' . $L->get('gallery-description') . '">';
		$html .= '</div>';
		$html .= '<div class="col-sm-3">';
		$html .= '<input type="hidden" name="editGallerySlug" value="' . Sanitize::html($slug) . '">';
		$html .= '<button type="submit" name="editGallery" value="true" class="btn btn-sm btn-primary">' . $L->get('edit-gallery') . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		// Upload area
		$html .= '<div class="gallery-upload-area mb-4" id="jsGalleryDropzone">';
		$html .= '<div class="gallery-upload-inner text-center p-4 border border-dashed rounded">';
		$html .= '<p class="mb-2"><i class="bi bi-cloud-arrow-up brendy-upload-icon" aria-hidden="true"></i></p>';
		$html .= '<p class="mb-2">' . $L->get('drop-images-here') . '</p>';
		$html .= '<input type="file" id="jsGalleryFileInput" multiple accept="image/*" class="d-none">';
		$html .= '<button type="button" id="jsGalleryUploadButton" class="btn btn-sm btn-outline-primary">' . $L->get('upload-images') . '</button>';
		$html .= '</div>';
		$html .= '<div class="progress mt-2" hidden>';
		$html .= '<div id="jsGalleryProgressBar" class="progress-bar bg-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>';
		$html .= '</div>';
		$html .= '<div id="jsGalleryUploadMsg" class="mt-2 small"></div>';
		$html .= '</div>';

		// Image grid
		$images = isset($gallery['images']) ? $gallery['images'] : array();
		$html .= '<div class="row" id="jsGalleryImageGrid">';

		if (empty($images)) {
			$html .= '<div class="col-12"><p class="text-muted">' . $L->get('no-images') . '</p></div>';
		} else {
			foreach ($images as $image) {
				$thumbFilename = $this->resolveThumbnail($galleryDir, $image);
				$thumbUrl = ($thumbFilename !== null) ? $thumbnailsHtmlPath . rawurlencode($thumbFilename) : $imagesHtmlPath . rawurlencode($image);
				$html .= '<div class="col-6 col-md-3 mb-3" id="jsGalleryImage-' . Sanitize::html($image) . '">';
				$html .= '<div class="card">';
				$html .= '<img src="' . Sanitize::html($thumbUrl) . '" class="card-img-top" alt="' . Sanitize::html($image) . '" loading="lazy" decoding="async">';
				$html .= '<div class="card-body p-2 text-center">';
				$html .= '<small class="text-muted d-block text-truncate">' . Sanitize::html($image) . '</small>';
				$html .= '<button type="button" class="btn btn-sm btn-outline-danger mt-1 js-gallery-delete-image" data-gallery="' . Sanitize::html($slug) . '" data-filename="' . Sanitize::html($image) . '">' . $L->get('delete-image') . '</button>';
				$html .= '</div></div></div>';
			}
		}

		$html .= '</div>';

		// Non-executable JSON configuration consumed by gallery-admin.js.
		$config = array(
			'webhookUrl' => HTML_PATH_ROOT . 'gallery/',
			'csrf' => $security->getTokenCSRF(),
			'slug' => $slug,
			'maxFiles' => 20,
			'labels' => array(
				'deleteConfirm' => $L->get('delete-image-confirm'),
				'unsupportedType' => $L->get('unsupported-file-type'),
				'uploadFailed' => $L->get('upload-failed')
			)
		);
		$html .= '<script type="application/json"' . brendyCspNonceAttribute() . ' id="brendy-gallery-admin-config">' . $this->scriptJson($config) . '</script>';

		return $html;
	}

	// =========================================================================
	// POST handler
	// =========================================================================

	public function post()
	{
		global $L;

		$actions = (int)isset($_POST['createGallery']) + (int)isset($_POST['editGallery']) + (int)isset($_POST['deleteGallery']);
		if ($actions > 1) {
			Alert::set($L->get('invalid-gallery-name'), ALERT_STATUS_FAIL);
			return false;
		}
		if ($actions === 0) return true;
		if (!$this->acquireOperationLock('brendy-gallery.lock')) {
			Alert::set($L->get('gallery-operation-in-progress'), ALERT_STATUS_FAIL);
			return false;
		}

		try {
			$galleries = $this->getGalleries();

			// Create gallery
			if (isset($_POST['createGallery'])) {
				$name = isset($_POST['galleryName']) && is_string($_POST['galleryName']) ? trim($_POST['galleryName']) : '';
				$description = isset($_POST['galleryDescription']) && is_string($_POST['galleryDescription']) ? trim($_POST['galleryDescription']) : '';
				if ($name === '' || Text::length($name) > 200 || Text::length($description) > 1000) {
					Alert::set($L->get('invalid-gallery-name'), ALERT_STATUS_FAIL);
					return false;
				}

				$slug = $this->generateSlug($name);
				if (!$this->validGallerySlug($slug) || isset($galleries[$slug])) return false;

				$galleryDir = $this->galleriesPath() . $slug . DS;
				$thumbDir = $galleryDir . 'thumbnails' . DS;
				if (file_exists($galleryDir) || is_link($galleryDir) || !Filesystem::mkdir($galleryDir, true)) return false;
				if (!Filesystem::mkdir($thumbDir, true)) {
					if (!Filesystem::deleteRecursive($galleryDir)) {
						Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back a partially created gallery directory.', LOG_TYPE_ERROR);
					}
					return false;
				}

				$galleries[$slug] = array(
					'name' => $name,
					'description' => $description,
					'date' => Date::current('Y-m-d'),
					'images' => array()
				);
				if (!$this->saveGalleries($galleries)) {
					if (!Filesystem::deleteRecursive($galleryDir)) {
						Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back a gallery directory after a database save failure.', LOG_TYPE_ERROR);
					}
					return false;
				}
				return true;
			}

			// Edit gallery
			if (isset($_POST['editGallery'])) {
				$oldSlug = isset($_POST['editGallerySlug']) && is_string($_POST['editGallerySlug']) ? $_POST['editGallerySlug'] : '';
				$name = isset($_POST['editGalleryName']) && is_string($_POST['editGalleryName']) ? trim($_POST['editGalleryName']) : '';
				$description = isset($_POST['editGalleryDescription']) && is_string($_POST['editGalleryDescription']) ? trim($_POST['editGalleryDescription']) : '';
				$newSlugInput = isset($_POST['editGalleryNewSlug']) && is_string($_POST['editGalleryNewSlug']) ? trim($_POST['editGalleryNewSlug']) : '';
				$newSlug = $this->generateSlug($newSlugInput);

				if (!$this->validGallerySlug($oldSlug) || !isset($galleries[$oldSlug]) ||
					$name === '' || Text::length($name) > 200 || Text::length($description) > 1000 ||
					!$this->validGallerySlug($newSlug)) return false;

				$galleryData = $galleries[$oldSlug];
				$galleryData['name'] = $name;
				$galleryData['description'] = $description;

				if ($newSlug !== $oldSlug) {
					if (isset($galleries[$newSlug])) return false;
					$oldDir = $this->galleriesPath() . $oldSlug;
					$newDir = $this->galleriesPath() . $newSlug;
					// The JSON row and its media directory are one logical object. Do
					// not commit a new slug if the source directory is already missing
					// or unsafe, otherwise every existing image URL becomes orphaned.
					if (!Filesystem::directoryExists($oldDir) || file_exists($newDir) || is_link($newDir) ||
						!Filesystem::mv($oldDir, $newDir)) return false;
					unset($galleries[$oldSlug]);
					$galleries[$newSlug] = $galleryData;
					if (!$this->saveGalleries($galleries)) {
						if (Filesystem::directoryExists($newDir) && !Filesystem::mv($newDir, $oldDir)) {
							Log::set(__METHOD__ . LOG_SEP . 'Gallery slug DB rollback succeeded but directory rollback failed.', LOG_TYPE_ERROR);
						}
						return false;
					}
				} else {
					$galleries[$oldSlug] = $galleryData;
					if (!$this->saveGalleries($galleries)) return false;
				}
				return true;
			}

			// Delete gallery
			$slug = is_string($_POST['deleteGallery']) ? $_POST['deleteGallery'] : '';
			if (!$this->validGallerySlug($slug) || !isset($galleries[$slug])) return false;

			$galleryDir = $this->galleriesPath() . $slug;
			$stagingDir = PATH_TMP . 'gallery-delete-' . bin2hex(random_bytes(8));
			$moved = false;
			if ((file_exists($galleryDir) || is_link($galleryDir)) && !Filesystem::directoryExists($galleryDir)) return false;
			if (Filesystem::directoryExists($galleryDir)) {
				if (!Filesystem::directoryExists(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) return false;
				if (!Filesystem::mv($galleryDir, $stagingDir)) return false;
				$moved = true;
			}
			unset($galleries[$slug]);
			if (!$this->saveGalleries($galleries)) {
				if ($moved && !Filesystem::mv($stagingDir, $galleryDir)) {
					Log::set(__METHOD__ . LOG_SEP . 'Gallery database deletion failed and directory rollback was incomplete.', LOG_TYPE_ERROR);
				}
				return false;
			}
			if ($moved && !Filesystem::deleteRecursive($stagingDir)) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to remove a staged gallery.', LOG_TYPE_ERROR);
			}
			return true;
		} finally {
			$this->releaseOperationLock('brendy-gallery.lock');
		}
	}

	// =========================================================================
	// Webhook AJAX endpoints (runs via beforeAll on site side)
	// =========================================================================

	public function beforeAll()
	{
		$URI = $this->webhook('gallery', $returnsAfterURI = true, $fixed = false);
		if ($URI === false) {
			return false;
		}

		// Check user is logged in
		$login = new Login();
		if (!$login->isLogged()) {
			$this->webhookResponse(1, 'Unauthorized', array(), 401);
		}

		// Check user role
		$username = Session::get('username');
		try {
			$user = new User($username);
			if (!in_array($user->role(), array('admin', 'editor'), true)) {
				$this->webhookResponse(1, 'Access denied', array(), 403);
			}
		} catch (Throwable $e) {
			$this->webhookResponse(1, 'Access denied', array(), 403);
		}

		$method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
		$parts = explode('/', trim($URI, '/'));
		$endpoint = isset($parts[0]) ? $parts[0] : '';
		if (count($parts) !== 1) {
			$this->webhookResponse(1, 'Invalid endpoint', array(), 404);
		}

		if ($method === 'POST') {
			global $security;
			if (!is_object($security) || !method_exists($security, 'sameOriginRequest') || !$security->sameOriginRequest()) {
				$this->webhookResponse(1, 'Forbidden', array(), 403);
			}
		}

		if ($method === 'POST' && $endpoint === 'upload') {
			if (!$this->acquireOperationLock('brendy-gallery.lock')) $this->webhookResponse(1, 'Another gallery operation is running.', array(), 409);
			$this->webhookUpload();
		} elseif ($method === 'POST' && $endpoint === 'delete-image') {
			if (!$this->acquireOperationLock('brendy-gallery.lock')) $this->webhookResponse(1, 'Another gallery operation is running.', array(), 409);
			$this->webhookDeleteImage();
		} elseif ($method === 'GET' && $endpoint === 'list') {
			$this->webhookList();
		} else {
			$this->webhookResponse(1, 'Invalid endpoint', array(), 404);
		}
	}

	private function webhookResponse($status, $message, $data = array(), $httpCode = null)
	{
		if (!is_array($data)) $data = array();
		$output = $data;
		// Reserved response fields are controlled by the endpoint, never by payload data.
		$output['status'] = $status;
		$output['message'] = is_scalar($message) ? (string)$message : '';
		$code = $httpCode === null ? ($status === 0 ? 200 : 400) : (int)$httpCode;
		$code = in_array($code, array(200, 400, 401, 403, 404, 409, 413, 500), true) ? $code : 400;
		$this->releaseOperationLock();
		sendJsonResponse($output, $code, '{"status":1,"message":"Response encoding failed"}');
	}

	private function webhookUpload()
	{
		global $security;

		if (!empty($_SERVER['CONTENT_LENGTH']) && empty($_FILES) && empty($_POST)) {
			$limit = ini_get('post_max_size');
			$this->webhookResponse(1, 'File too large (server limit: ' . $limit . ')', array(), 413);
		}

		$token = isset($_POST['tokenCSRF']) ? $_POST['tokenCSRF'] : '';
		if (!$security->validateTokenCSRF($token)) {
			$this->webhookResponse(1, 'Invalid CSRF token', array(), 403);
		}

		$gallerySlug = isset($_POST['gallery']) && is_string($_POST['gallery']) ? $_POST['gallery'] : '';

		// Validate slug: strict whitelist (matches generateSlug() output).
		// Type-check before preg_match so malformed array input cannot trigger a
		// PHP 8 TypeError and turn a rejected request into a 500 response.
		if (!$this->validGallerySlug($gallerySlug)) {
			$this->webhookResponse(1, 'Invalid gallery');
		}

		$galleries = $this->getGalleries();
		if (!isset($galleries[$gallerySlug])) {
			$this->webhookResponse(1, 'Gallery not found');
		}

		$galleryDir = $this->galleriesPath() . $gallerySlug . DS;
		$thumbDir = $galleryDir . 'thumbnails' . DS;

		if (!Filesystem::directoryExists($thumbDir) && !Filesystem::mkdir($thumbDir, true)) {
			$this->webhookResponse(1, 'Gallery directory is unavailable');
		}

		if (empty($_FILES['images']) || !is_array($_FILES['images']) ||
			!isset($_FILES['images']['name'], $_FILES['images']['tmp_name'], $_FILES['images']['error']) ||
			!is_array($_FILES['images']['name']) || count($_FILES['images']['name']) > 20) {
			$this->webhookResponse(1, 'No files uploaded');
		}

		// Ensure temp directory exists
		if (!Filesystem::directoryExists(PATH_TMP)) {
			Filesystem::mkdir(PATH_TMP, true);
		}

		$uploaded = array();
		$errors = array();

		foreach ($_FILES['images']['name'] as $i => $filename) {
			if (!is_string($filename) || !isset($_FILES['images']['error'][$i], $_FILES['images']['tmp_name'][$i])) {
				$errors[] = 'Invalid upload.';
				continue;
			}
			// Check for upload errors
			$uploadError = $_FILES['images']['error'][$i];
			if ($uploadError !== UPLOAD_ERR_OK) {
				if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
					$errors[] = $filename . ': file too large (limit: ' . Filesystem::bytesToHumanFileSize(min((int)Text::toBytes(ini_get('upload_max_filesize')), BRENDY_MAX_IMAGE_UPLOAD_BYTES)) . ')' ;
				} else {
					$errors[] = $filename . ': upload error';
				}
				continue;
			}

			$safeFilename = brendySanitizeUploadedFilename($filename, 'image');
			if ($safeFilename === false) {
				$errors[] = 'Invalid filename.';
				continue;
			}

			// Validate extension and decoded image data.
			$fileExtension = Text::lowercase(Filesystem::extension($safeFilename));
			if (!in_array($fileExtension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true) ||
				!validateUploadedImage($_FILES['images']['tmp_name'][$i], $safeFilename)) {
				$errors[] = $filename . ': unsupported file type';
				continue;
			}

			// Move to temp using the same core filename policy as admin/API uploads.
			$tmpName = Filesystem::nextFilename($safeFilename, PATH_TMP);
			if ($tmpName === false) {
				$errors[] = $filename . ': no safe temporary filename available';
				continue;
			}
			$tmpFile = PATH_TMP . $tmpName;
			$moved = moveUploadedFileSafely($_FILES['images']['tmp_name'][$i], $tmpFile);
			if (!$moved) {
				$errors[] = $filename . ': failed to move to temp';
				continue;
			}

			// Transform image and generate thumbnail
			$image = transformImage($tmpFile, $galleryDir, $thumbDir);
			if ($image) {
				@chmod($image, 0644);
				$finalFilename = Filesystem::filename($image);
				$uploaded[] = $finalFilename;
			} else {
				// Clean up temp file on failure
				if (file_exists($tmpFile)) {
					Filesystem::rmfile($tmpFile);
				}
				$errors[] = $filename . ': processing failed';
			}
		}

		// Update gallery image list in DB
		if (!empty($uploaded)) {
			$galleries = $this->getGalleries();
			if (isset($galleries[$gallerySlug])) {
				$currentImages = isset($galleries[$gallerySlug]['images']) ? $galleries[$gallerySlug]['images'] : array();
				$galleries[$gallerySlug]['images'] = array_values(array_unique(array_merge($currentImages, $uploaded)));
				if (!$this->saveGalleries($galleries)) {
					foreach ($uploaded as $uploadedFile) {
						if (is_file($galleryDir . $uploadedFile)) Filesystem::rmfile($galleryDir . $uploadedFile);
						$thumbnailFile = $this->resolveThumbnail($galleryDir, $uploadedFile);
						if ($thumbnailFile !== null && is_file($thumbDir . $thumbnailFile)) Filesystem::rmfile($thumbDir . $thumbnailFile);
					}
					$this->webhookResponse(1, 'Unable to save the gallery image list');
				}
			}
		}

		if (empty($uploaded)) {
			$this->webhookResponse(1, !empty($errors) ? $errors[0] : 'Upload failed', array('errors' => $errors));
		}

		$this->webhookResponse(0, 'Upload complete', array(
			'images' => $uploaded,
			'errors' => $errors
		));
	}

	private function webhookDeleteImage()
	{
		global $security;

		// CSRF validation
		$token = isset($_POST['tokenCSRF']) ? $_POST['tokenCSRF'] : '';
		if (!$security->validateTokenCSRF($token)) {
			$this->webhookResponse(1, 'Invalid CSRF token', array(), 403);
		}

		$gallerySlug = isset($_POST['gallery']) && is_string($_POST['gallery']) ? $_POST['gallery'] : '';
		$filename = isset($_POST['filename']) && is_string($_POST['filename']) ? $_POST['filename'] : '';

		if ($gallerySlug === '' || $filename === '') {
			$this->webhookResponse(1, 'Missing parameters');
		}

		// Strict whitelist validation: slugs are [a-z0-9-]; filenames are
		// generated/sanitized by the media pipeline, so a conservative local filename whitelist is sufficient.
		if (!$this->validGallerySlug($gallerySlug) || !is_string($filename) || strlen($filename) > 255 ||
			preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1 ||
			!in_array(Text::lowercase(Filesystem::extension($filename)), $GLOBALS['ALLOWED_IMG_EXTENSION'], true)) {
			$this->webhookResponse(1, 'Invalid parameters');
		}

		$galleries = $this->getGalleries();
		if (!isset($galleries[$gallerySlug])) {
			$this->webhookResponse(1, 'Gallery not found');
		}

		$galleryDir = $this->galleriesPath() . $gallerySlug . DS;
		$imageFile = $galleryDir . $filename;
		$deleteTargets = array();
		if (Sanitize::pathFile($galleryDir, $filename) && is_file($imageFile) && !is_link($imageFile)) {
			$deleteTargets[] = $imageFile;
		}
		$thumbFilename = $this->resolveThumbnail($galleryDir, $filename);
		if ($thumbFilename !== null) {
			$thumbPath = $galleryDir . 'thumbnails' . DS . $thumbFilename;
			if (Sanitize::pathFile($galleryDir . 'thumbnails' . DS, $thumbFilename) && is_file($thumbPath) && !is_link($thumbPath)) {
				$deleteTargets[] = $thumbPath;
			}
		}

		$stagedDeletion = stageFilesForDeletion($deleteTargets, 'gallery');
		if ($stagedDeletion === false) $this->webhookResponse(1, 'Unable to stage gallery image deletion', array(), 500);

		$images = $galleries[$gallerySlug]['images'];
		$galleries[$gallerySlug]['images'] = array_values(array_diff($images, array($filename)));
		if (!$this->saveGalleries($galleries)) {
			if (!rollbackStagedDeletion($stagedDeletion)) {
				Log::set(__METHOD__ . LOG_SEP . 'Gallery DB save failed and staged image rollback was incomplete.', LOG_TYPE_ERROR);
			}
			$this->webhookResponse(1, 'Unable to update gallery', array(), 500);
		}
		if (!purgeStagedDeletion($stagedDeletion)) {
			Log::set(__METHOD__ . LOG_SEP . 'Staged gallery image could not be purged.', LOG_TYPE_ERROR);
		}

		$this->webhookResponse(0, 'Image deleted', array(
			'filename' => $filename
		));
	}

	private function webhookList()
	{
		$galleries = $this->getGalleries();
		$result = array();

		foreach ($galleries as $slug => $gallery) {
			$images = isset($gallery['images']) ? $gallery['images'] : array();
			$galleryDir = $this->galleriesPath() . $slug . DS;
			$galleryHtmlPath = $this->galleriesHtmlPath() . $slug . '/';
			$thumbnails = array();
			foreach ($images as $image) {
				$thumbFilename = $this->resolveThumbnail($galleryDir, $image);
				$thumbnails[] = array(
					'filename' => $image,
					'thumbnail' => ($thumbFilename !== null) ? $galleryHtmlPath . 'thumbnails/' . rawurlencode($thumbFilename) : $galleryHtmlPath . rawurlencode($image),
					'full' => $galleryHtmlPath . rawurlencode($image)
				);
			}
			$result[] = array(
				'name' => $gallery['name'],
				'slug' => $slug,
				'description' => $gallery['description'],
				'imageCount' => count($images),
				'images' => $thumbnails
			);
		}

		$this->webhookResponse(0, 'OK', array('galleries' => $result));
	}

	// =========================================================================
	// Editor toolbar hook
	// =========================================================================

	public function editorToolbar()
	{
		global $L;

		$html = '<button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#jsGallerySelectModal">';
		$html .= '<i class="bi bi-images" aria-hidden="true"></i> ' . $L->get('galleries');
		$html .= '</button>';

		return $html;
	}

	// =========================================================================
	// Frontend rendering (site side)
	// =========================================================================

	private $hasGalleries = false;

	public function beforeSiteLoad()
	{
		if ($GLOBALS['WHERE_AM_I'] === 'page') {
			$content = $this->parseGalleries($GLOBALS['page']);
			if ($content !== false) {
				$GLOBALS['page']->setField('content', $content);
			}
		} else {
			foreach ($GLOBALS['content'] as $key => $page) {
				$content = $this->parseGalleries($GLOBALS['content'][$key]);
				if ($content !== false) {
					$GLOBALS['content'][$key]->setField('content', $content);
				}
			}
		}
	}

	private function parseGalleries($page)
	{
		$content = $page->contentRaw();
		if (strpos($content, '[gallery:') === false) {
			return false;
		}

		$this->hasGalleries = true;
		$galleries = $this->getGalleries();

		// Replace gallery tags BEFORE Markdown parsing to avoid nesting issues
		$content = preg_replace_callback(
			'/\[gallery:([\w-]+)\]/',
			function ($matches) use ($galleries) {
				$slug = $matches[1];
				if (isset($galleries[$slug])) {
					return $this->renderGalleryHtml($slug, $galleries[$slug]);
				}
				return '';
			},
			$content
		);

		// Parse Markdown after gallery shortcode expansion.
		if (MARKDOWN_PARSER) {
			$parsedown = new Parsedown();
			$content = $parsedown->text($content);
		}

		// Convert relative image URLs to absolute
		if (IMAGE_RELATIVE_TO_ABSOLUTE) {
			$domain = IMAGE_RESTRICT ? DOMAIN_UPLOADS_PAGES . $page->uuid() . '/' : DOMAIN_UPLOADS;
			$content = Text::imgRel2Abs($content, $domain);
		}

		return $content;
	}

	private function renderGalleryHtml($slug, $gallery)
	{
		$images = isset($gallery['images']) ? $gallery['images'] : array();
		if (empty($images)) {
			return '';
		}

		$basePath = $this->galleriesHtmlPath() . $slug . '/';
		$thumbPath = $basePath . 'thumbnails/';
		$galleryDir = $this->galleriesPath() . $slug . DS;

		$safeSlug = Theme::escape($slug);
		$html = '<div class="brendy-gallery" data-gallery="' . $safeSlug . '">';
		$html .= '<div class="brendy-gallery-grid">';

		foreach ($images as $image) {
			if (!is_file($galleryDir . $image) || is_link($galleryDir . $image)) continue;
			$thumbFilename = $this->resolveThumbnail($galleryDir, $image);
			$thumbUrl = ($thumbFilename !== null) ? $thumbPath . rawurlencode($thumbFilename) : $basePath . rawurlencode($image);
			$alt = $this->imageAltText($image, isset($gallery['name']) ? $gallery['name'] : '');
			$html .= '<a href="' . Theme::escape($basePath . rawurlencode($image)) . '" class="brendy-gallery-item" data-gallery-group="' . $safeSlug . '">';
			$html .= '<img src="' . Theme::escape($thumbUrl) . '" alt="' . Theme::escape($alt) . '" loading="lazy" decoding="async">';
			$html .= '</a>';
		}

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	public function siteHead()
	{
		return $this->includeCSS('gallery.css');
	}

	public function siteBodyEnd()
	{
		if (!$this->hasGalleries) {
			return false;
		}
		global $L;
		$config = array('labels' => array(
			'dialog' => $L->get('gallery-dialog-label'),
			'previous' => $L->get('gallery-previous-image'),
			'next' => $L->get('gallery-next-image'),
			'close' => $L->get('gallery-close')
		));
		return '<script type="application/json"' . brendyCspNonceAttribute() . ' id="brendy-gallery-frontend-config">' .
			$this->scriptJson($config) . '</script>' . $this->includeJS('gallery.js');
	}

	// =========================================================================
	// Admin hooks
	// =========================================================================

	public function adminHead()
	{
		if (!in_array($GLOBALS['ADMIN_CONTROLLER'], array('configure-plugin', 'new-content', 'edit-content'), true)) {
			return false;
		}

		$html = $this->includeCSS('gallery-admin.css');
		$html .= $this->includeJS('gallery-admin.js');
		return $html;
	}

	public function adminSidebar()
	{
		global $L;
		global $login;
		if (!$login || $login->role() !== 'admin') {
			return '';
		}
		$html = '<a class="nav-link" href="' . HTML_PATH_ADMIN_ROOT . 'configure-plugin/BrendyPluginGallery">';
		$html .= '<i class="bi bi-images" aria-hidden="true"></i><span>' . Theme::escape($L->get('galleries')) . '</span>';
		$html .= '</a>';
		return $html;
	}

	public function adminBodyEnd()
	{
		if (!in_array($GLOBALS['ADMIN_CONTROLLER'], array('new-content', 'edit-content'), true)) {
			return false;
		}

		global $L;
		global $security;

		$html = '';

		// Gallery selection modal for the editor
		$html .= '<div id="jsGallerySelectModal" class="modal" tabindex="-1" role="dialog">';
		$html .= '<div class="modal-dialog modal-lg" role="document">';
		$html .= '<div class="modal-content">';
		$html .= '<div class="modal-header">';
		$html .= '<h5 class="modal-title">' . $L->get('select-gallery') . '</h5>';
		$html .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . htmlspecialchars($L->get('close'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"></button>';
		$html .= '</div>';
		$html .= '<div class="modal-body" id="jsGallerySelectBody">';
		$html .= '<p class="text-muted">' . $L->get('Loading...') . '</p>';
		$html .= '</div>';
		$html .= '</div></div></div>';

		// Non-executable JSON configuration for editor integration.
		$config = array(
			'webhookUrl' => HTML_PATH_ROOT . 'gallery/',
			'labels' => array(
				'insert' => $L->get('insert-gallery'),
				'noGalleries' => $L->get('no-galleries'),
				'images' => $L->get('images-count'),
				'loading' => $L->get('loading'),
				'loadFailed' => $L->get('gallery-load-failed')
			)
		);
		$html .= '<script type="application/json"' . brendyCspNonceAttribute() . ' id="brendy-gallery-admin-config">' . $this->scriptJson($config) . '</script>';

		return $html;
	}
}
