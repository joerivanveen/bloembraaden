<?php
declare(strict_types=1);

namespace Bloembraaden;
class Image extends BaseElement
{
    protected array $sizes;

    public function __construct(\stdClass $properties = null)
    {
        parent::__construct($properties);
        $this->type_name = 'image';
        $this->sizes = array(
            'huge' => 4000,
            'large' => 1600,
            'medium' => 800,
            'small' => 400,
            'tiny' => 200,
        ); // TODO in config or something?
    }

    public function create(?bool $online = true): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New image', 'peatcms'),
            'content_type' => 'image/jpg',
            'filename_saved' => '',
            'filename_original' => '',
            'extension' => 'tbd',
            'slug' => 'image',
            'online' => $online,
        ));
    }

    private function forgetOriginalFile(): bool
    {
        if (isset($this->row->filename_saved)) {
            return $this->update(array('filename_saved' => null));
        }

        return $this->update(array('src' => null));
    }

    // image resize: https://stackoverflow.com/questions/14649645/resize-image-in-php#answer-56039606
    public function process(LoggerInterface $logger, int $level = 1): bool
    {
        // the saved file should be split up in different sizes (according to instance?) and saved (compressed) under the slug name.
        // file name contains slug and instance_id and size denominator
        // TODO if the slug changes, the saved files need to change as well, check in history periodically
        // TODO and make it async, for instance with cron jobs
        $quality = array(0, 55, 65, 80)[$level] ?? 55;
        $data = array(); // the columns to update
        $path = Setup::$UPLOADS;
        if (isset($this->row->filename_saved)) {
            $path .= $this->row->filename_saved;
        } elseif (isset($this->row->src)) { // provision for instagram images...
            $src = $this->row->src;
            $data['src'] = $src; // we want this saved in the end
            $path .= $src;
        } else {
            $logger->log('Original no longer available');
            return false;
        }
        // check physical (image) file
        if (false === file_exists($path)) {
            $logger->log('Path does not exist');
            if (false === $this->forgetOriginalFile()) {
                $logger->log('Database not updated');
            }

            return false;
        }
        if (false === in_array(($type = exif_imagetype($path)),
                array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP, IMAGETYPE_WEBP)
            )) {
            $logger->log('Exif image type not recognized');
            // because the file is not recognizable for processing, we have to lose it
            if (false === $this->forgetOriginalFile()) {
                $logger->log('Database not updated');
            }

            return false;
        }
        $image_info = getimagesize($path);
        $width = $image_info[0];
        $height = $image_info[1];
        $bits = $image_info['bits'];
        $channels = $image_info['channels'];
        $memory_needed = $width * $height * $bits * $channels * 1.5;
        $memory_limit = (int)Help::getMemorySize(ini_get('memory_limit') ?: '128M');
        if ($memory_needed > $memory_limit) {
            $memory_needed_M = Help::getMemorySize((string)$memory_needed,'M');
            if ($memory_needed <= Setup::$MAX_MEMORY_LIMIT) {
                $logger->log("Increasing memory to $memory_needed_M");
                ini_set('memory_limit', $memory_needed_M);
            } else {
                $logger->log("Image too large for memory, needs $memory_needed_M");
                $logger->log('Current limit: ' . Help::getMemorySize((string)$memory_limit,'M'));
            }
        }
        switch (true) {
            case $type === IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($path);
                $data['extension'] = 'jpg';
                break;
            case $type === IMAGETYPE_PNG:
                $image = imagecreatefrompng($path);
                $data['extension'] = 'png';
                break;
            case $type === IMAGETYPE_GIF:
                $image = imagecreatefromgif($path);
                $data['extension'] = 'gif';
                break;
            case $type === IMAGETYPE_BMP:
                $image = imagecreatefrombmp($path);
                $data['extension'] = 'bmp';
                break;
            case $type === IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($path);
                $data['extension'] = 'webp';
                break;
            default:
                $this->addError("Image type $type cannot be processed");
                return false;
        }
        $logger->log(sprintf('Loaded %s image in memory', $data['extension']));
        // rotate and flip if necessary @since 0.11.0
        // https://stackoverflow.com/questions/52174789/warning-exif-read-dataphp3kladx-file-not-supported-in-home-i-public-html-ori
        $exif = @exif_read_data($path);
        if ($exif && !empty($exif['Orientation'])) {
            $orientation = $exif['Orientation'];
            $angle = 0;
            if (in_array($orientation, [3, 4])) {
                $angle = 180;
            } elseif (in_array($orientation, [5, 6])) {
                $angle = -90;
                Help::swapVariables($width, $height);
            } elseif (in_array($orientation, [7, 8])) {
                $angle = 90;
                Help::swapVariables($width, $height);
            }
            if (0 !== $angle) {
                $image = imagerotate($image, $angle, 0);
                $logger->log("Rotated image $angle degrees");
            }
            if (in_array($orientation, [2, 5, 7, 4])) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $logger->log('Image flipped as well');
            }
        }
        // define necessary paths
        $src = $this->getSlug() . '.webp'; // we save as webp by default with jpg fallback
        $my_path = Setup::$CDNPATH . $this->getInstanceId() . '/';
        // make sure the folders exist
        if (false === file_exists($my_path)) {
            if (false === mkdir($my_path, 0755, true)) {
                $logger->log('ERROR on filesystem');
                $this->handleErrorAndStop("Could not mkdir $my_path");
            }
        }
        // process and save the 5 sizes TODO compact this somewhere <- don't forget to include the check on existence
        foreach ($this->sizes as $size => $pixels) { // (e.g. 'small' => 400)
            set_time_limit(30);
            $subdir = "$my_path$size/";
            if (false === file_exists($subdir)) {
                if (false === mkdir($subdir, 0755, true)) {
                    $logger->log('ERROR on filesystem');
                    $this->handleErrorAndStop("Could not mkdir $subdir");
                }
            }
            if ($width > $height) { // landscape
                if ($width < $pixels) {
                    $newWidth = $width;
                    $newHeight = $height;
                } else {
                    $newWidth = $pixels;
                    $newHeight = (int)floor($pixels * $height / $width);
                }
            } else {
                if ($height < $pixels) {
                    $newHeight = $height;
                    $newWidth = $width;
                } else {
                    $newHeight = $pixels;
                    $newWidth = (int)floor($pixels * $width / $height);
                }
            }
            // create resized image
            $logger->log('Preparing new image');
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            //TRANSPARENT BACKGROUND
            $color = imagecolorallocatealpha($newImage, 0, 0, 0, 127); //fill transparent back
            imagefill($newImage, 0, 0, $color);
            imagesavealpha($newImage, true);
            //ROUTINE
            $logger->log("Resizing original to $newWidth × $newHeight");
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            // save the img
            $newPath = $subdir . $src; // webp path
            // never overwrite images
            $index = 1;
            while (file_exists($newPath)) {
                $index++;
                $newPath = "$subdir$index-$src";
            }
            $logger->log("Saving to $newPath");
            // save as jpg and as webp only @since 0.5.9
            $success = imagewebp($newImage, $newPath, $quality);
            if (true === $success) { // now save as jpg for fallback, without any alpha stuff
                $logger->log('Saved');
                $newImage = $this->removeAlpha($newImage, 255, 255, 255);
                $jpgPath = substr($newPath, 0, -4) . 'jpg';
                // in webserver config a rule redirects webp requests to jpg if the webp accept header is missing
                $success = imagejpeg($newImage, $jpgPath, $quality);
            } else {
                $logger->log('FAILED');
            }
            // automate css class for the image
            if ('small' === $size) {
                $brightness = $this->getBrightness($newImage);
                $logger->log("Brightness: $brightness");
                if (isset($this->row->css_class)) {
                    $css_class = $this->row->css_class;
                    // remove dark and brightness indicators from css_class
                    $css_class = preg_replace('/(bloembraaden-brightness)-\d+/', '', $css_class);
                    $css_class = str_replace('bloembraaden-dark', '', $css_class);
                } else {
                    $css_class = '';
                }
                // process new class
                if ($brightness < 45) {
                    $css_class .= ' bloembraaden-dark';
                }
                $css_class .= ' bloembraaden-brightness-' . min(floor($brightness / 7.9), 10);
                $data['css_class'] = trim(preg_replace('/( )+/', ' ', $css_class));
            }
            imagedestroy($newImage);
            // always requests webp, will be redirected to jpg if necessary webserver config
            $relativePath = substr($newPath, strlen(Setup::$CDNPATH));
            // remember values
            if (true === $success) {
                $logger->log('Saved fallback jpg image');
                $data["src_$size"] = $relativePath;
                $data["width_$size"] = $newWidth;
                $data["height_$size"] = $newHeight;
            } else {
                $this->addError(sprintf(__('Could not save image %s', 'peatcms'), $newPath));

                return false;
            }
        }
        imagedestroy($image);
        ini_set('memory_limit', Help::getMemorySize((string)$memory_limit, 'M'));
        // update the element
        $data['date_processed'] = 'NOW()';
        if (true === $this->update($data)) {
            $logger->log('Saved info to database');

            return true;
        }

        return false;
    }

    private function removeAlpha(\GdImage $img, int $red, int $green, int $blue): \GdImage
    {
        // check the values of RGB: must be a positive int smaller than 256
        $red = min(abs($red), 255);
        $green = min(abs($green), 255);
        $blue = min(abs($blue), 255);
        // get image width and height
        $w = imagesx($img);
        $h = imagesy($img);
        // turn alpha blending off
        imagealphablending($img, false);
        // set the color
        $color = imagecolorallocate($img, $red, $green, $blue);
        // loop through the image and replace any alpha'd pixel by the supplied background color
        // TODO mix the colors so it doesn't get jagged
        for ($x = 0; $x < $w; $x++)
            for ($y = 0; $y < $h; $y++) {
                if (0 !== (imagecolorat($img, $x, $y) >> 24) & 0xFF) {
                    //set pixel with the new color
                    if (!imagesetpixel($img, $x, $y, $color)) {
                        $this->handleErrorAndStop('image processing error');
                    }
                }
            }

        return $img;
    }

    /**
     * Gets overall brightness of an image in percent, 0 = black, 100 = white, discards 5% padding.
     *
     * @param $img
     * @return float
     */
    protected function getBrightness($img)
    {
        $width = imagesx($img);
        $padding_width = (int)floor($width / 20);
        $height = imagesy($img);
        $padding_height = (int)floor($height / 20);
        $totalBrightness = 0;
        for ($x = $padding_width; $x < $width - $padding_width; $x++) {
            for ($y = $padding_height; $y < $height - $padding_height; $y++) {
                $rgb = imagecolorat($img, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $totalBrightness += (max($red, $green, $blue) + min($red, $green, $blue)) / 2;
            }
        }
        imagedestroy($img);

        return ($totalBrightness / ($width * $height)) / 2.55;
    }

    public function completeRowForOutput(): void // override from base element class
    {
        // TODO make this better
        $cdnroot = Setup::$CDNROOT;
        $this->row->src_tiny = $cdnroot . $this->row->src_tiny;
        $this->row->src_small = $cdnroot . $this->row->src_small;
        $this->row->src_medium = $cdnroot . $this->row->src_medium;
        $this->row->src_large = $cdnroot . $this->row->src_large;
        $this->row->src_huge = $cdnroot . $this->row->src_huge;
    }
}
