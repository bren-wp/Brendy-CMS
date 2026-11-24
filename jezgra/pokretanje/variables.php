<?php defined('BRENDY') or die('Brendy CMS.');

/*
	Environment variables
	If you are going to do some changes is recommended do it before the installation
*/

// Log
define('LOG_SEP', ' | ');
define('LOG_TYPE_INFO', '[INFO]');
define('LOG_TYPE_WARN', '[WARN]');
define('LOG_TYPE_ERROR', '[ERROR]');

// Protecting against Symlink attacks
define('CHECK_SYMBOLIC_LINKS', TRUE);

// Alert status ok
define('ALERT_STATUS_OK', 0);

// Alert status fail
define('ALERT_STATUS_FAIL', 1);

// Profile image size
define('PROFILE_IMG_WIDTH', 400);
define('PROFILE_IMG_HEIGHT', 400);
define('PROFILE_IMG_QUALITY', 100); // 100%

// Items per page for admin area
define('ITEMS_PER_PAGE_ADMIN', 20);

// Password policy for newly created or changed passwords. Existing shorter
// passwords remain usable and are migrated to password_hash() after login.
define('PASSWORD_LENGTH', 12);
define('PASSWORD_MAX_LENGTH', 72); // Portable upper bound while bcrypt remains the fallback.
define('PASSWORD_HISTORY_LIMIT', 5); // Reject reuse of the current and five most recent credentials.
define('PASSWORD_ARGON_MEMORY_COST', 19456); // 19 MiB, OWASP-style shared-hosting friendly Argon2id baseline.
define('PASSWORD_ARGON_TIME_COST', 2);
define('PASSWORD_ARGON_THREADS', 1);

// Password salt length
define('SALT_LENGTH', 8);

// Page brake string
define('PAGE_BREAK', '<!-- pagebreak -->');

// Remember me
define('REMEMBER_COOKIE_USERNAME', 'BRENDY_REMEMBER_USERNAME');
define('REMEMBER_COOKIE_TOKEN', 'BRENDY_REMEMBER_TOKEN');
define('REMEMBER_COOKIE_EXPIRE_IN_DAYS', 30);

// Login abuse protection. IP throttling stops concentrated attacks while the
// hashed-account bucket also slows distributed credential stuffing.
define('LOGIN_ACCOUNT_FAILURES_ALLOWED', 20);
define('LOGIN_ACCOUNT_BLOCK_MINUTES', 15);
define('LOGIN_RATE_LIMIT_RETENTION', 5000);
define('TWO_FACTOR_LOGIN_TTL', 300); // 5 minutes to finish the second-factor challenge.
define('TWO_FACTOR_LOGIN_MAX_ATTEMPTS', 6);
define('TWO_FACTOR_SETUP_TTL', 600); // 10 minutes to finish authenticator enrollment.

// Filename
define('FILENAME', 'index.txt');

// Database date format
define('DB_DATE_FORMAT', 'Y-m-d H:i:s');

// Database date format
define('BACKUP_DATE_FORMAT', 'Y-m-d-H-i-s');

// Sitemap date format
define('SITEMAP_DATE_FORMAT', 'Y-m-d');

// Date format for Manage Content, Manage Users
define('ADMIN_PANEL_DATE_FORMAT', 'd.m.Y. H:i');

// Date format for Dashboard schedule posts
define('SCHEDULED_DATE_FORMAT', 'd.m.Y. H:i');

// Notifications date format
define('NOTIFICATIONS_DATE_FORMAT', 'd.m.Y. H:i');

// Manage content date format
define('MANAGE_CONTENT_DATE_FORMAT', 'd.m.Y. H:i');

// Amount of items to show on notification panel
define('NOTIFICATIONS_AMOUNT', 10);

// Full activity history retained for the admin audit log. Dashboard notifications
// still show only NOTIFICATIONS_AMOUNT latest entries.
define('SYSLOG_RETENTION_AMOUNT', 500);

// Per-page revision history. Autosaves are separate and are not counted here.
define('CONTENT_REVISION_RETENTION', 30);

// Token time to live for login via email. The offset is defined by http://php.net/manual/en/datetime.modify.php
define('TOKEN_EMAIL_TTL', '+15 minutes');

// Preview links are generated only after an authenticated autosave and expire
// quickly so a copied URL cannot expose an unpublished draft indefinitely.
define('PREVIEW_TOKEN_TTL', 900);

// Charset, default UTF-8.
define('CHARSET', 'UTF-8');

// Permissions for new directories
define('DIR_PERMISSIONS', 0755);

// Admin URI filter to access to the admin panel
define('ADMIN_URI_FILTER', 'admin');

// English is the technical fallback for incomplete optional translations.
// New Brendy installations and Site defaults use Croatian (hr_HR).
define('DEFAULT_LANGUAGE_FILE', 'hr_HR.json');

// Session idle timeout server side, gc_maxlifetime
// 3600 = 1 hour without activity.
define('SESSION_GC_MAXLIFETIME', 3600);

// Absolute authenticated-session lifetime. Even an actively used admin session
// is renewed only by signing in again after this period.
define('SESSION_ABSOLUTE_MAXLIFETIME', 43200); // 12 hours
define('SESSION_ID_ROTATE_INTERVAL', 900); // 15 minutes; limits exposure of a stolen session ID

// Sensitive administrator mutations require a fresh proof of the primary
// credential. Persistent Remember-me sessions never satisfy this boundary.
define('STEP_UP_AUTH_TTL', 900); // 15 minutes
define('STEP_UP_AUTH_MAX_ATTEMPTS', 5);
define('STEP_UP_RETURN_TTL', 900);

// Session lifetime of the cookie in seconds which is sent to the browser
// The value 0 means until the browser is closed
define('SESSION_COOKIE_LIFE_TIME', 0);

// Alert notification disappear in X seconds
define('ALERT_DISAPPEAR_IN', 3);

// Number of images to show in the media manager per page
define('MEDIA_MANAGER_NUMBER_OF_FILES', 24);

// Sort the image by date
define('MEDIA_MANAGER_SORT_BY_DATE', true);

// Constant arrays using define are not allowed in PHP 5.6 or earlier

// Type of pages included in the tag database
$GLOBALS['DB_TAGS_TYPES'] = array('published','static','sticky');

// Allowed image extensions — used by image upload endpoints (logo, profile picture, upload-images) and transformImage()
$GLOBALS['ALLOWED_IMG_EXTENSION'] = array('gif', 'png', 'jpg', 'jpeg', 'webp');

// Allowed image mime types
$GLOBALS['ALLOWED_IMG_MIMETYPES'] = array('image/gif', 'image/png', 'image/jpeg', 'image/webp');

// Allowed file extensions — used by API file upload and any non-image upload endpoint
$GLOBALS['ALLOWED_FILE_EXTENSIONS'] = array('gif', 'png', 'jpg', 'jpeg', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'tar', 'gz', 'mp3', 'mp4', 'wav', 'ogg', 'json', 'md');
