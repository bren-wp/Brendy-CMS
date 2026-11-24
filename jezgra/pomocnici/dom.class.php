<?php defined('BRENDY') or die('Brendy CMS.');

class DOM
{
    public static function getFirstImage($content)
    {
        if (!is_string($content) || $content === '' || !class_exists('DOMDocument')) return false;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadHTML('<meta http-equiv="content-type" content="text/html; charset=utf-8">' . $content, LIBXML_NONET)) return false;
            $images = $dom->getElementsByTagName('img');
            foreach ($images as $image) {
                $source = trim($image->getAttribute('src'));
                if ($source !== '') return $source;
            }
            return false;
        } catch (Throwable $e) {
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
