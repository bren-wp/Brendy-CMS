<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Variables
// ============================================================================
$themePlugin = getPluginByDirectory($site->theme()); // Returns active companion plugin object or False

// ============================================================================
// Functions
// ============================================================================

function brendyLoadThemeJson($filename, $themePath)
{
	$realTheme = realpath($themePath);
	$realFile = is_string($filename) ? realpath($filename) : false;
	if ($realTheme === false || $realFile === false || is_link($themePath) || is_link($filename) ||
		strpos($realFile, $realTheme . DS) !== 0) return false;
	$content = Filesystem::readFileBounded($realFile, 2097152, 2);
	$decoded = is_string($content) ? json_decode($content, true, 64) : null;
	return is_array($decoded) ? $decoded : false;
}

/**
 * Validate literal local references used by a theme. This intentionally checks
 * only deterministic PHP/CSS references; dynamic runtime URLs remain outside
 * the static health contract.
 */
function brendyThemeReferenceHealth($realThemePath)
{
	$issues = array();
	$themeRoot = realpath($realThemePath);
	if (!is_string($themeRoot) || is_link($realThemePath)) return array('source-root');
	$phpChecked = 0;
	$cssChecked = 0;

	try {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $entry) {
			if (!$entry->isFile() || $entry->isLink()) continue;
			$filename = $entry->getPathname();
			$extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
			$size = $entry->getSize();
			if (!is_int($size) || $size < 0 || $size > 2097152) continue;

			if ($extension === 'php' && $phpChecked < 200) {
				$phpChecked++;
				$source = Filesystem::readFileBounded($filename, 2097152, 0);
				if (!is_string($source)) { $issues[] = 'source-unreadable'; continue; }
				$references = array();
				if (preg_match_all('/[\'\"]((?:css|js|img|images|fonts)\/[A-Za-z0-9._\/-]+)[\'\"]/', $source, $matches)) {
					$references = array_merge($references, $matches[1]);
				}
				foreach (array_values(array_unique($references)) as $relative) {
					$target = $themeRoot . DS . str_replace('/', DS, $relative);
					$targetReal = realpath($target);
					if (!is_file($target) || is_link($target) || !is_string($targetReal) || strpos($targetReal, $themeRoot . DS) !== 0) {
						$issues[] = 'asset-missing-' . str_replace(array('/', DS), '-', $relative);
					}
				}
				if (preg_match_all('/THEME_DIR_PHP\s*\.\s*[\'\"]([A-Za-z0-9._\/-]+)[\'\"]/', $source, $includeMatches)) {
					foreach (array_values(array_unique($includeMatches[1])) as $relative) {
						$target = $themeRoot . DS . 'php' . DS . str_replace('/', DS, $relative);
						$targetReal = realpath($target);
						if (!is_file($target) || is_link($target) || !is_string($targetReal) || strpos($targetReal, $themeRoot . DS) !== 0) {
							$issues[] = 'include-missing-' . str_replace(array('/', DS), '-', $relative);
						}
					}
				}
			}

			if ($extension === 'css' && $cssChecked < 100) {
				$cssChecked++;
				$source = Filesystem::readFileBounded($filename, 2097152, 0);
				if (!is_string($source)) { $issues[] = 'asset-css-unreadable'; continue; }
				if (preg_match_all('/url\(\s*[\'\"]?([^\'\")]+)[\'\"]?\s*\)/i', $source, $urlMatches)) {
					foreach (array_slice(array_values(array_unique($urlMatches[1])), 0, 250) as $rawReference) {
						$reference = trim((string)$rawReference);
						if ($reference === '' || preg_match('#^(?:data:|https?:|//|\#|var\()#i', $reference)) continue;
						$reference = preg_split('/[?#]/', $reference, 2)[0];
						if ($reference === '' || strpos($reference, "\0") !== false) continue;
						$target = dirname($filename) . DS . str_replace('/', DS, $reference);
						$targetReal = realpath($target);
						if (!is_file($target) || is_link($target) || !is_string($targetReal) || strpos($targetReal, $themeRoot . DS) !== 0) {
							$issues[] = 'asset-css-missing';
						}
					}
				}
			}
		}
	} catch (Throwable $e) {
		$issues[] = 'source-scan';
	}
	return array_values(array_unique($issues));
}

function buildThemes()
{
	global $site;

	$themes = array();
	$themesRoot = realpath(PATH_THEMES);
	if ($themesRoot === false) return $themes;
	$themesPaths = array_slice(Filesystem::listDirectories(PATH_THEMES), 0, 1000);

	foreach ($themesPaths as $themePath) {
		$realThemePath = realpath($themePath);
		if ($realThemePath === false || is_link($themePath) || strpos($realThemePath, $themesRoot . DS) !== 0) continue;
		// Check if the theme is translated.
		$languageFilename = $realThemePath . DS . 'jezici' . DS . $site->language() . '.json';
		$database = brendyLoadThemeJson($languageFilename, $realThemePath);
		if ($database === false) {
			$languageFilename = $realThemePath . DS . 'jezici' . DS . DEFAULT_LANGUAGE_FILE;
			$database = brendyLoadThemeJson($languageFilename, $realThemePath);
		}
		if ($database === false || !isset($database['theme-data']) || !is_array($database['theme-data'])) {
			Log::set('99.themes.php' . LOG_SEP . 'Language file error on theme ' . basename($realThemePath));
			continue;
		}
		$themeData = $database['theme-data'];
		$name = isset($themeData['name']) && is_scalar($themeData['name']) ? (string)$themeData['name'] : basename($realThemePath);
		$description = isset($themeData['description']) && is_scalar($themeData['description']) ? (string)$themeData['description'] : '';
		if (strlen($name) > 500 || strlen($description) > 10000) continue;
		$theme = array('name' => $name, 'description' => $description, 'dirname' => basename($realThemePath));

		$metadata = brendyLoadThemeJson($realThemePath . DS . 'metadata.json', $realThemePath);
		if ($metadata === false) continue;
		foreach (array_slice($metadata, 0, 100, true) as $key => $value) {
			if (is_string($key) && strlen($key) <= 64 && is_scalar($value) && strlen((string)$value) <= 10000) {
				$theme[$key] = (string)$value;
			}
		}
		$theme['compatible'] = function_exists('brendyExtensionMetadataCompatible')
			? brendyExtensionMetadataCompatible($metadata) : false;
		$theme['icon'] = isset($theme['icon']) && preg_match('/^[a-z0-9-]{1,50}$/D', $theme['icon']) === 1
			? $theme['icon'] : 'palette2';
		$theme['category'] = isset($theme['category']) && $theme['category'] === 'theme' ? 'theme' : 'theme';
		$healthIssues = array();
		foreach (array('author', 'website', 'version', 'releaseDate', 'brendyRelease', 'license', 'compatible', 'notes', 'icon', 'category') as $field) {
			if (!isset($metadata[$field]) || !is_scalar($metadata[$field]) || trim((string)$metadata[$field]) === '') $healthIssues[] = 'metadata-' . $field;
		}
		foreach (array('index.php', 'metadata.json', 'jezici' . DS . DEFAULT_LANGUAGE_FILE) as $requiredFile) {
			$requiredPath = $realThemePath . DS . $requiredFile;
			if (!is_file($requiredPath) || is_link($requiredPath) || !is_readable($requiredPath)) $healthIssues[] = 'source-' . str_replace(DS, '-', $requiredFile);
		}
		if (function_exists('brendyValidateExtensionMetadata') &&
			!brendyValidateExtensionMetadata($metadata, 'theme', false)) $healthIssues[] = 'metadata-format';
		$healthIssues = array_merge($healthIssues, brendyThemeReferenceHealth($realThemePath));
		if (function_exists('brendyValidateExtensionPhpTreeSecurity') && !brendyValidateExtensionPhpTreeSecurity($realThemePath)) $healthIssues[] = 'source-security';
		if (!$theme['compatible']) $healthIssues[] = 'compatibility';
		$healthIssues = array_values(array_unique($healthIssues));
		$themeErrors = array_filter($healthIssues, static function ($issue) {
			return $issue === 'metadata-format' || strpos($issue, 'source-') === 0 ||
				strpos($issue, 'include-missing-') === 0 || strpos($issue, 'asset-missing-') === 0 ||
				strpos($issue, 'asset-css-') === 0;
		});
		$theme['health'] = array(
			'status' => empty($healthIssues) ? 'ok' : (!empty($themeErrors) ? 'error' : 'warning'),
			'issues' => $healthIssues
		);
		$modified = @filemtime($realThemePath . DS . 'metadata.json');
		$theme['lastModified'] = is_int($modified) ? $modified : 0;
		$themes[] = $theme;
	}

	return $themes;
}

// ============================================================================
// Main
// ============================================================================

// Load the language file
$languageFilename = THEME_DIR . 'jezici' . DS . $site->language() . '.json';
$database = brendyLoadThemeJson($languageFilename, THEME_DIR);
if ($database === false) $database = brendyLoadThemeJson(THEME_DIR . 'jezici' . DS . DEFAULT_LANGUAGE_FILE, THEME_DIR);
if (is_array($database)) {
	unset($database['theme-data']);
	if (!empty($database)) $L->add($database);
}
