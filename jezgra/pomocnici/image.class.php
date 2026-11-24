<?php defined('BRENDY') or die('Brendy CMS.');

class Image {

    private $image;
    private $width;
    private $height;
    private $imageResized;

    public function __destruct()
    {
        if ($this->imageResized && $this->imageResized !== $this->image) {
            imagedestroy($this->imageResized);
        }
        if ($this->image) {
            imagedestroy($this->image);
        }
    }

    public function setImage($fileName, $newWidth, $newHeight, $option="auto")
    {
        $newWidth = filter_var($newWidth, FILTER_VALIDATE_INT);
        $newHeight = filter_var($newHeight, FILTER_VALIDATE_INT);
        if ($newWidth === false || $newHeight === false || $newWidth < 1 || $newHeight < 1 ||
            $newWidth > 8192 || $newHeight > 8192) {
            return false;
        }
		if (!is_string($fileName) || !is_file($fileName) || is_link($fileName)) {
			return false;
		}

		// Read dimensions before asking GD to decode the full bitmap. Compressed
		// image files can expand to hundreds of megabytes and otherwise exhaust
		// PHP memory before the post-decode checks get a chance to run.
		$imageInfo = @getimagesize($fileName);
		if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1])) {
			return false;
		}
		$this->width = (int)$imageInfo[0];
		$this->height = (int)$imageInfo[1];
		if ($this->width < 1 || $this->height < 1 || $this->width > BRENDY_MAX_IMAGE_DIMENSION || $this->height > BRENDY_MAX_IMAGE_DIMENSION ||
			($this->width * $this->height) > BRENDY_MAX_IMAGE_PIXELS) {
			return false;
		}

		$dimensions = $this->getDimensions($newWidth, $newHeight, $option);
		$optimalWidth = (int)$dimensions['optimalWidth'];
		$optimalHeight = (int)$dimensions['optimalHeight'];
		if ($optimalWidth < 1 || $optimalHeight < 1 || $optimalWidth > BRENDY_MAX_IMAGE_DIMENSION || $optimalHeight > BRENDY_MAX_IMAGE_DIMENSION ||
			($optimalWidth * $optimalHeight) > BRENDY_MAX_IMAGE_PIXELS ||
			!$this->hasMemoryForResize($optimalWidth, $optimalHeight, $newWidth, $newHeight, $option)) {
			return false;
		}

        // *** Open up the file
        $this->image = $this->openImage($fileName);

        // *** Check if image was opened successfully
        if ($this->image === false) {
            return false;
        }

		// Confirm that the decoder produced the same dimensions advertised by the
		// header used for the memory preflight.
		if (imagesx($this->image) !== $this->width || imagesy($this->image) !== $this->height) {
			return false;
		}

		return $this->resizeImage($newWidth, $newHeight, $option);
    }

	private function hasMemoryForResize($optimalWidth, $optimalHeight, $newWidth, $newHeight, $option)
	{
		$sourcePixels = $this->width * $this->height;
		$resizePixels = $optimalWidth * $optimalHeight;
		$cropPixels = ($option === 'crop') ? ($newWidth * $newHeight) : 0;
		// Eight bytes per pixel is deliberately conservative for GD's true-colour
		// buffers and allocator overhead; retain another 16 MiB for PHP/runtime data.
		$estimated = (($sourcePixels + $resizePixels + $cropPixels) * 8) + 16777216;
		$limit = $this->memoryLimitBytes();
		if ($limit < 0) return true;

		$ceiling = (int)floor($limit * 0.90);
		return memory_get_usage(true) + $estimated <= $ceiling;
	}

	private function memoryLimitBytes()
	{
		$value = trim((string)ini_get('memory_limit'));
		if ($value === '' || $value === '-1') return -1;
		if (!preg_match('/^([0-9]+)\s*([KMG]?)B?$/iD', $value, $matches)) return 0;
		$bytes = (int)$matches[1];
		switch (strtoupper($matches[2])) {
			case 'G': $bytes *= 1024;
			case 'M': $bytes *= 1024;
			case 'K': $bytes *= 1024;
		}
		return $bytes;
	}

	    public function saveImage($savePath, $imageQuality="100", $forceJPG=false, $forcePNG=false)
    {
		if (!$this->imageResized || !is_string($savePath) || strrpos($savePath, '.') === false ||
			!is_dir(dirname($savePath))) {
			return false;
		}
		$imageQuality = max(0, min(100, (int) $imageQuality));
        $extension = strtolower(pathinfo($savePath, PATHINFO_EXTENSION));

        // Remove the extension
        $filename = substr($savePath, 0,strrpos($savePath,'.'));

        if ($forcePNG) {
            $extension = 'png';
        } elseif ($forceJPG) {
            $extension = 'jpg';
        }

	        $path_complete = $filename.'.'.$extension;
			if (is_link($path_complete) || (file_exists($path_complete) && !is_file($path_complete)) ||
				is_link(dirname($path_complete))) return false;
			$temporary = Filesystem::temporaryFile(dirname($path_complete), '.brendy-image-', 0600);
			if ($temporary === false) return false;

			$saved = false;
		switch ($extension) {
            case 'jpg':
            case 'jpeg':
                // Checking for JPG support
				if ((imagetypes() & IMG_JPG) && function_exists('imagejpeg')) {
					$saved = imagejpeg($this->imageResized, $temporary, $imageQuality);
                }
                break;

            case 'gif':
                // Checking for GIF support
				if ((imagetypes() & IMG_GIF) && function_exists('imagegif')) {
					$saved = imagegif($this->imageResized, $temporary);
                }
                break;

            case 'png':
                // *** Scale quality from 0-100 to 0-9
                $scaleQuality = round(($imageQuality/100) * 9);

                // *** Invert quality setting as 0 is best, not 9
                $invertScaleQuality = 9 - $scaleQuality;

                // Checking for PNG support
				if ((imagetypes() & IMG_PNG) && function_exists('imagepng')) {
					$saved = imagepng($this->imageResized, $temporary, $invertScaleQuality);
                }
                break;
            case 'webp':
                // Checking for WEBP support
				if ((imagetypes() & IMG_WEBP) && function_exists('imagewebp')) {
					$saved = imagewebp($this->imageResized, $temporary, $imageQuality);
                }
                break;

            default:
                // Fail extension detection
                break;
		}
			if (!$saved || !is_file($temporary) || @filesize($temporary) < 1) {
				if (is_file($temporary)) @unlink($temporary);
				return false;
			}
			if (!Filesystem::publishStagedFile($temporary, $path_complete, 0644)) {
				if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
				return false;
			}
			return true;
    }

    private function openImage($file)
    {
        // *** Get extension
        $extension = strtolower(strrchr($file, '.'));

        // GD may be compiled without support for some formats (often WebP, and
        // sometimes JPEG on minimal builds). Guard each decoder with both a
        // runtime capability check and a function_exists() check to avoid a
        // fatal "call to undefined function" on stripped builds.
        $types = imagetypes();
        switch($extension)
        {
            case '.jpg':
            case '.jpeg':
                if (!($types & IMG_JPG) || !function_exists('imagecreatefromjpeg')) {
                    return false;
                }
				$img = @imagecreatefromjpeg($file);
                break;
            case '.gif':
                if (!($types & IMG_GIF) || !function_exists('imagecreatefromgif')) {
                    return false;
                }
				$img = @imagecreatefromgif($file);
                break;
            case '.png':
                if (!($types & IMG_PNG) || !function_exists('imagecreatefrompng')) {
                    return false;
                }
				$img = @imagecreatefrompng($file);
                break;
            case '.webp':
                if (!($types & IMG_WEBP) || !function_exists('imagecreatefromwebp')) {
                    return false;
                }
				$img = @imagecreatefromwebp($file);
                break;
            default:
                $img = false;
                break;
        }
        return $img;
    }

    private function resizeImage($newWidth, $newHeight, $option)
    {
        // *** Get optimal width and height - based on $option
        $optionArray = $this->getDimensions($newWidth, $newHeight, $option);

        $optimalWidth  = (int) $optionArray['optimalWidth'];
        $optimalHeight = (int) $optionArray['optimalHeight'];


        // *** Resample - create image canvas of x, y size
		if ($optimalWidth < 1 || $optimalHeight < 1 || ($optimalWidth * $optimalHeight) > BRENDY_MAX_IMAGE_PIXELS) {
			return false;
		}
		$this->imageResized = imagecreatetruecolor($optimalWidth, $optimalHeight);
		if ($this->imageResized === false) {
			return false;
		}
        imagealphablending($this->imageResized, false);
        imagesavealpha($this->imageResized, true);
		if (!imagecopyresampled($this->imageResized, $this->image, 0, 0, 0, 0, $optimalWidth, $optimalHeight, $this->width, $this->height)) {
			return false;
		}


        // *** if option is 'crop', then crop too
        if ($option === 'crop') {
			return $this->crop($optimalWidth, $optimalHeight, $newWidth, $newHeight);
        }
		return true;
    }

    private function getDimensions($newWidth, $newHeight, $option)
    {

	        if (!in_array($option, array('crop', 'exact'), true) &&
				($this->width < $newWidth) && ($this->height < $newHeight))
        {
            return array('optimalWidth' => $this->width, 'optimalHeight' => $this->height);
        }

        switch ($option)
        {
            case 'exact':
                $optimalWidth = $newWidth;
                $optimalHeight= $newHeight;
                break;
            case 'portrait':
                $optimalWidth = $this->getSizeByFixedHeight($newHeight);
                $optimalHeight= $newHeight;
                break;
            case 'landscape':
                $optimalWidth = $newWidth;
                $optimalHeight= $this->getSizeByFixedWidth($newWidth);
                break;
            case 'auto':
                $optionArray = $this->getSizeByAuto($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
            case 'crop':
                $optionArray = $this->getOptimalCrop($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
            default:
                $optionArray = $this->getSizeByAuto($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
        }

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    private function getSizeByFixedHeight($newHeight)
    {
        $ratio = $this->width / $this->height;
        $newWidth = $newHeight * $ratio;
        return $newWidth;
    }

    private function getSizeByFixedWidth($newWidth)
    {
        $ratio = $this->height / $this->width;
        $newHeight = $newWidth * $ratio;
        return $newHeight;
    }

    private function getSizeByAuto($newWidth, $newHeight)
    {
        if ($this->height < $this->width)
            // *** Image to be resized is wider (landscape)
        {
            $optimalWidth = $newWidth;
            $optimalHeight= $this->getSizeByFixedWidth($newWidth);
        }
        elseif ($this->height > $this->width)
            // *** Image to be resized is taller (portrait)
        {
            $optimalWidth = $this->getSizeByFixedHeight($newHeight);
            $optimalHeight= $newHeight;
        }
        else
            // *** Image to be resizerd is a square
        {
            if ($newHeight < $newWidth) {
                $optimalWidth = $newWidth;
                $optimalHeight= $this->getSizeByFixedWidth($newWidth);
            } else if ($newHeight > $newWidth) {
                $optimalWidth = $this->getSizeByFixedHeight($newHeight);
                $optimalHeight= $newHeight;
            } else {
                // *** Sqaure being resized to a square
                $optimalWidth = $newWidth;
                $optimalHeight= $newHeight;
            }
        }

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    private function getOptimalCrop($newWidth, $newHeight)
    {

        $heightRatio = $this->height / $newHeight;
        $widthRatio  = $this->width /  $newWidth;

        if ($heightRatio < $widthRatio) {
            $optimalRatio = $heightRatio;
        } else {
            $optimalRatio = $widthRatio;
        }

        $optimalHeight = $this->height / $optimalRatio;
        $optimalWidth  = $this->width  / $optimalRatio;

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    private function crop($optimalWidth, $optimalHeight, $newWidth, $newHeight)
    {
        // *** Find center - this will be used for the crop
        $cropStartX = (int) (( $optimalWidth / 2) - ( $newWidth /2 ));
        $cropStartY = (int) (( $optimalHeight/ 2) - ( $newHeight/2 ));

        $crop = $this->imageResized;

        // *** Now crop from center to exact requested size
		$this->imageResized = imagecreatetruecolor($newWidth , $newHeight);
		if ($this->imageResized === false) {
			imagedestroy($crop);
			return false;
		}
        imagealphablending($this->imageResized, false);
        imagesavealpha($this->imageResized, true);
		$copied = imagecopyresampled($this->imageResized, $crop , 0, 0, $cropStartX, $cropStartY, $newWidth, $newHeight , $newWidth, $newHeight);
		imagedestroy($crop);
		return $copied;
    }
}
