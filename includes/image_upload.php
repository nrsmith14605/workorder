<?php

/**
 * Process multiple uploaded image files using ImageMagick.
 * Resizes to max 1200px on longest side, strips EXIF, saves as JPEG.
 * Returns array of relative paths (e.g. ['wo_imgs/WO-PENDING_...jpg', ...]).
 *
 * @param array  $files      $_FILES['photos'] multi-file array
 * @param string $upload_dir Absolute path to destination directory (with trailing slash)
 */
function process_uploaded_images(array $files, string $upload_dir): array {
    if (!class_exists('Imagick')) return [];

    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'heic'];
    $paths = [];

    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $count = count($files['name'] ?? []);
    for ($i = 0; $i < min($count, 5); $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts)) continue;

        $uid      = substr(uniqid(), -8);
        $filename = 'WO-PENDING_' . date('Ymd') . '_' . $uid . '.jpg';
        $dest     = $upload_dir . $filename;

        try {
            $img = new Imagick($files['tmp_name'][$i]);
            $img->autoOrient();

            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w > 1200 || $h > 1200) {
                $img->resizeImage(1200, 1200, Imagick::FILTER_LANCZOS, 1, true);
            }

            $img->stripImage();
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(82);
            $img->writeImage($dest);
            $img->destroy();

            $paths[] = 'wo_imgs/' . $filename;
        } catch (\Throwable $e) {
            continue;
        }
    }

    return $paths;
}
