<?php defined('BRENDY') or die('Brendy CMS.');

// Jedini izvor istine za identitet izdanja. Installer i runtime učitavaju
// iste vrijednosti kako se build/verzija ne bi razišli tijekom nadogradnje.
define('BRENDY_NAME',           'Brendy CMS');
define('BRENDY_VERSION',        '2.42.28');
define('BRENDY_CODENAME',       ''); // Deprecated compatibility constant; codenames are no longer part of the product brand.
define('BRENDY_RELEASE_DATE',   '2026-11-25');
define('BRENDY_BUILD',          202611252328);
define('BRENDY_RELEASE_NAME',   BRENDY_NAME);
define('BRENDY_EDITOR_NAME',    'TinyMCE');
define('BRENDY_EDITOR_VERSION', '8.10.2');
define('BRENDY_EDITOR_INTEGRATION_VERSION', '2.0.3');
define('BRENDY_AUTHOR',         'Brendigo');
define('BRENDY_WEBSITE',        'https://brendigo.com');
define('BRENDY_EXTENSION_API',  '2.0.0');
define('BRENDY_CORE_COMPATIBILITY', BRENDY_EXTENSION_API);
