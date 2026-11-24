<?php defined('BRENDY') or die('Brendy CMS.');

// A Tiny Cloud browser API key is not a self-hosted license key. Only authenticated
// editor pages may receive the cloud URL. No external request is made by PHP.
function brendyTinyCloudUrl($authenticated, $controller, $contentDirectory, $forceLocal = false)
{
    if ($authenticated !== true || !in_array($controller, array('new-content', 'edit-content'), true) || $forceLocal) return '';
    $mode = getenv('BRENDY_TINYMCE_MODE');
    $key = getenv('BRENDY_TINYMCE_API_KEY');
    if ($mode === false || $key === false) {
        $raw = Filesystem::readFileBounded(rtrim($contentDirectory, '/\\') . DIRECTORY_SEPARATOR . '.tinymce-cloud.json', 2048, 2);
        $stored = is_string($raw) ? json_decode($raw, true, 4) : null;
        if (is_array($stored)) {
            if ($mode === false) $mode = $stored['mode'] ?? '';
            if ($key === false) $key = $stored['apiKey'] ?? '';
        }
    }
    // Explicit environment opt-in required while upstream cloud sanitizer is unverified.
    if (getenv('BRENDY_TINYMCE_MODE') !== 'cloud') return '';
    if ($mode !== 'cloud' || !is_string($key) || preg_match('/\A[a-zA-Z0-9_-]{20,128}\z/', $key) !== 1) return '';
    return 'https://cdn.tiny.cloud/1/' . rawurlencode($key) . '/tinymce/' . rawurlencode(BRENDY_EDITOR_VERSION) . '/tinymce.min.js';
}
