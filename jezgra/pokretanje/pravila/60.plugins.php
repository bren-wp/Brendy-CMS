<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Variables
// ============================================================================

$plugins = array(
	'siteHead'=>array(),
	'siteBodyBegin'=>array(),
	'siteBodyEnd'=>array(),
	'siteSidebar'=>array(),
	'beforeSiteLoad'=>array(),
	'afterSiteLoad'=>array(),

	'pageBegin'=>array(),
	'pageEnd'=>array(),

	'beforeAdminLoad'=>array(),
	'afterAdminLoad'=>array(),
	'adminHead'=>array(),
	'adminBodyBegin'=>array(),
	'adminBodyEnd'=>array(),
	'adminSidebar'=>array(),
	'adminContentSidebar'=>array(),
	'dashboard'=>array(),
	'editorToolbar'=>array(),

	'beforeAll'=>array(),
	'afterAll'=>array(),

	'paginator'=>array(),

	'afterPageCreate'=>array(),
	'afterPageModify'=>array(),
	'afterPageDelete'=>array(),
	'afterCategoryCreate'=>array(),
	'afterCategoryModify'=>array(),
	'afterCategoryDelete'=>array(),
	'afterSettingsModify'=>array(),

	'loginHead'=>array(),
	'loginBodyBegin'=>array(),
	'loginBodyEnd'=>array(),

	'all'=>array()
);

$pluginsEvents = $plugins;
unset($pluginsEvents['all']);

$pluginsInstalled = array();
// Metadata-only catalog. Entries here never imply that plugin.php was executed.
$pluginsCatalog = array();

// ============================================================================
// Functions
// ============================================================================

function brendyInactivePluginLoadTarget()
{
	global $url, $layout;
	if (!isset($url) || !is_object($url) || !method_exists($url, 'whereAmI') || $url->whereAmI() !== 'admin') return '';
	$slug = isset($layout['slug']) && is_string($layout['slug']) ? $layout['slug'] : '';
	if ($slug === 'api') return 'BrendyPluginAPI';
	if ($slug === 'install-plugin') {
		return normalizeBrendyPluginClassName(isset($layout['parameters']) && is_string($layout['parameters']) ? $layout['parameters'] : '');
	}
	if ($slug === 'install-theme') {
		// A theme identifies its optional companion by directory slug, while the
		// runtime registry is keyed by BrendyPlugin* class. Resolve the declared class
		// without executing plugin.php so the selected inactive companion can be
		// audited and loaded only on this explicit management surface.
		$themeDirectory = isset($layout['parameters']) && is_string($layout['parameters']) ? $layout['parameters'] : '';
		return brendyPluginClassNameForDirectory($themeDirectory, true);
	}
	return '';
}

function brendyAuditInactivePluginCatalog()
{
	global $url, $layout;
	if (!isset($url) || !is_object($url) || !method_exists($url, 'whereAmI') || $url->whereAmI() !== 'admin') return false;
	$slug = isset($layout['slug']) && is_string($layout['slug']) ? $layout['slug'] : '';
	return in_array($slug, array('plugins', 'system-health'), true);
}

function buildPlugins()
{
	global $plugins;
	global $pluginsEvents;
	global $pluginsInstalled;
	global $L;
	global $site;
	global $pluginsCatalog;
	$inactiveLoadTarget = brendyInactivePluginLoadTarget();
	$auditInactiveCatalog = brendyAuditInactivePluginCatalog();

	// Get declared classes before loading plugin classes.
	$declaredClassesBeforePlugins = get_declared_classes();

	$pluginsRoot = realpath(PATH_PLUGINS);
	if ($pluginsRoot === false) return;
	$list = array_slice(Filesystem::listDirectories(PATH_PLUGINS), 0, 1000);

	// Resolve class ownership before including any extension PHP. PHP class names
	// are case-insensitive, so two directories declaring the same BrendyPlugin*
	// class would otherwise cause a fatal redeclaration and take down the CMS.
	$pluginClassByPath = array();
	$pluginClassOwners = array();
	foreach ($list as $candidatePath) {
		$realCandidate = realpath($candidatePath);
		$candidateFile = $realCandidate === false ? false : $realCandidate . DS . 'plugin.php';
		if ($realCandidate === false || is_link($candidatePath) ||
			strpos($realCandidate, $pluginsRoot . DS) !== 0 || !is_file($candidateFile) || is_link($candidateFile)) continue;
		$directory = basename($realCandidate);
		if (brendyIsRetiredBundledPluginDirectory($directory)) continue;
		$declared = brendyPluginClassNameFromSource($candidateFile);
		$pluginClassByPath[$realCandidate] = $declared;
		if ($declared !== '') $pluginClassOwners[strtolower($declared)][] = $directory;
	}

	foreach ($list as $pluginPath) {
		$realPluginPath = realpath($pluginPath);
		$pluginFile = $realPluginPath === false ? false : $realPluginPath . DS . 'plugin.php';
		if ($realPluginPath === false || is_link($pluginPath) ||
			strpos($realPluginPath, $pluginsRoot . DS) !== 0 || !is_file($pluginFile) || is_link($pluginFile)) continue;

		// Metadata is parsed independently from runtime compatibility. This allows an
		// incompatible extension to remain visible in the administrator catalog
		// without executing its PHP, while activation/runtime stay fail-closed.
		$directoryName = basename($realPluginPath);
		if (brendyIsRetiredBundledPluginDirectory($directoryName)) {
			Log::set(__FILE__ . LOG_SEP . 'Ignored retired bundled plugin source ' . $directoryName, LOG_TYPE_WARN);
			continue;
		}
		$metadataFile = $realPluginPath . DS . 'metadata.json';
		$metadataRaw = Filesystem::readFileBounded($metadataFile, 1048576, 2);
		$metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true, 64) : null;
		$metadataValid = brendyValidateExtensionMetadata($metadata, 'plugin', false);
		$compatible = $metadataValid && brendyExtensionMetadataCompatible($metadata);
		$className = isset($pluginClassByPath[$realPluginPath]) ? $pluginClassByPath[$realPluginPath] : '';
		$classCollision = $className !== '' && isset($pluginClassOwners[strtolower($className)]) &&
			count($pluginClassOwners[strtolower($className)]) > 1;
		$databaseDirectory = PATH_PLUGINS_DATABASES . $directoryName;
		$workspaceDirectory = PATH_WORKSPACES . $directoryName . DS;
		$databaseFile = $databaseDirectory . DS . 'db.php';
		$databaseFileExists = is_file($databaseFile) && !is_link($databaseFile);
		$stateExists = $databaseFileExists || file_exists($databaseDirectory) || is_link($databaseDirectory) ||
			file_exists($workspaceDirectory) || is_link($workspaceDirectory);
		$databaseHealth = $databaseFileExists ? BrendyJsonDatabase::inspectFile($databaseFile, true, 16777216) : array('ok' => false, 'reason' => 'absent');
		$databaseOk = $databaseFileExists && is_array($databaseHealth) && !empty($databaseHealth['ok']);
		$sourceSafe = null;
		$loadThisInactive = !$stateExists && $inactiveLoadTarget !== '' && $className !== '' && hash_equals($inactiveLoadTarget, $className);
		if ($auditInactiveCatalog || $databaseOk || $loadThisInactive) {
			$sourceSafe = brendyValidateExtensionPhpTreeSecurity($realPluginPath);
		}
		$catalogKey = ($className !== '' && !$classCollision) ? $className : '@' . $directoryName;
		$pluginsCatalog[$catalogKey] = array(
			'directory' => $directoryName,
			'className' => $className,
			'classCollision' => $classCollision,
			'metadata' => is_array($metadata) ? $metadata : array(),
			'metadataValid' => $metadataValid,
			'compatible' => $compatible,
			'installed' => $stateExists,
			'databaseOk' => $databaseOk,
			'databaseReason' => is_array($databaseHealth) && isset($databaseHealth['reason']) ? (string)$databaseHealth['reason'] : 'invalid',
			'sourceSafe' => $sourceSafe
		);

		if (!$metadataValid) {
			Log::set(__FILE__ . LOG_SEP . 'Blocked plugin with invalid metadata ' . $directoryName, LOG_TYPE_ERROR);
			continue;
		}
		if ($className === '') {
			Log::set(__FILE__ . LOG_SEP . 'Blocked plugin without exactly one valid BrendyPlugin class ' . $directoryName, LOG_TYPE_ERROR);
			continue;
		}
		if ($classCollision) {
			Log::set(__FILE__ . LOG_SEP . 'Blocked duplicate plugin class ' . $className . LOG_SEP .
				implode(', ', array_slice($pluginClassOwners[strtolower($className)], 0, 10)), LOG_TYPE_ERROR);
			continue;
		}
		if (!$compatible) {
			// Incompatible plugin code is never included. The metadata-only catalog
			// above keeps it removable and diagnosable after a core upgrade.
			continue;
		}
		if (!$databaseOk && !$loadThisInactive) continue;
		if ($sourceSafe !== true) {
			if ($sourceSafe === null) $sourceSafe = brendyValidateExtensionPhpTreeSecurity($realPluginPath);
			$pluginsCatalog[$catalogKey]['sourceSafe'] = $sourceSafe;
		}
		if ($sourceSafe !== true) {
			Log::set(__FILE__ . LOG_SEP . 'Blocked unsafe or modified plugin ' . $directoryName, LOG_TYPE_ERROR);
			continue;
		}
		try {
			include_once($pluginFile);
		} catch (Throwable $e) {
			Log::set(__FILE__ . LOG_SEP . 'Unable to load plugin ' . $directoryName, LOG_TYPE_ERROR);
		}
	}

	// Get plugin classes loaded by the files above.
	$declaredPluginClasses = array_diff(get_declared_classes(), $declaredClassesBeforePlugins);

	foreach ($declaredPluginClasses as $brendyPluginClass) {
		try {
			$reflection = new ReflectionClass($brendyPluginClass);
			$classFile = $reflection->getFileName();
			$realClassFile = is_string($classFile) ? realpath($classFile) : false;
			if (!$reflection->isSubclassOf('BrendyPlugin') || !$reflection->isInstantiable() ||
				($reflection->getConstructor() && $reflection->getConstructor()->getNumberOfRequiredParameters() > 0) ||
				$realClassFile === false || strpos($realClassFile, $pluginsRoot . DS) !== 0) continue;
			$brendyPlugin = $reflection->newInstance();
		} catch (Throwable $e) {
			Log::set(__FILE__ . LOG_SEP . 'Unable to initialize plugin class.', LOG_TYPE_ERROR);
			continue;
		}

		$pluginDirectory = realpath(PATH_PLUGINS . $brendyPlugin->directoryName());
		if ($pluginDirectory === false || strpos($pluginDirectory, $pluginsRoot . DS) !== 0) continue;
		$languageDirectory = $pluginDirectory . DS . 'jezici' . DS;
		$realLanguageDirectory = realpath($languageDirectory);
		$languageFilename = $languageDirectory . $site->language() . '.json';
		if (!is_file($languageFilename) || is_link($languageFilename)) {
			$languageFilename = $languageDirectory . DEFAULT_LANGUAGE_FILE;
		}
		$realLanguageFile = realpath($languageFilename);
		$database = array();
		if ($realLanguageDirectory !== false && strpos($realLanguageDirectory, $pluginDirectory . DS) === 0 &&
			$realLanguageFile !== false &&
			strpos($realLanguageFile, $realLanguageDirectory . DS) === 0) {
			$languageString = Filesystem::readFileBounded($realLanguageFile, 2097152, 2);
			$decoded = is_string($languageString) ? json_decode($languageString, true, 64) : null;
			if (is_array($decoded)) $database = $decoded;
		}
		if (isset($database['plugin-data']) && is_array($database['plugin-data'])) {
			foreach (array('name', 'description') as $metadataField) {
				if (isset($database['plugin-data'][$metadataField]) && is_scalar($database['plugin-data'][$metadataField])) {
					$brendyPlugin->setMetadata($metadataField, (string)$database['plugin-data'][$metadataField]);
				}
			}
		}
		unset($database['plugin-data']);
		if (!empty($database)) $L->add($database);

		// $plugins['all'] Array with all plugins, installed and not installed
		$plugins['all'][$brendyPluginClass] = $brendyPlugin;

		// Execute hooks only when persisted state, reserved fields and core
		// compatibility all pass the runtime-ready contract. A broken installed
		// plugin remains in $plugins['all'] for diagnostics/uninstall only.
		if ($brendyPlugin->runtimeReady()) {
			// Include custom hooks
			if (is_array($brendyPlugin->customHooks)) {
				foreach (array_slice($brendyPlugin->customHooks, 0, 100) as $customHook) {
					if (!is_string($customHook) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $customHook) !== 1) continue;
					if (!isset($plugins[$customHook])) {
						$plugins[$customHook] = array();
						$pluginsEvents[$customHook] = array();
					}
				}
			}

			$pluginsInstalled[$brendyPluginClass] = $brendyPlugin;
			foreach ($pluginsEvents as $event=>$value) {
				if (method_exists($brendyPlugin, $event)) {
					array_push($plugins[$event], $brendyPlugin);
				}
			}
		}

	}

	// Sort once after all plugins have been registered.
	uasort($plugins['siteSidebar'], function ($a, $b) {
		return $a->position() <=> $b->position();
	});
}

// ============================================================================
// Main
// ============================================================================

buildPlugins();
