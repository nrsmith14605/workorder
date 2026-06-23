<?php
$source     = __DIR__ . '/top_logo.png';
$cache_file = __DIR__ . '/top_logo-64.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');

if (file_exists($cache_file)) {
    readfile($cache_file);
    exit;
}

$target_h = 64;

if (class_exists('Imagick')) {
    $img = new Imagick($source);
    $img->resizeImage(0, $target_h, Imagick::FILTER_LANCZOS, 1);
    $img->setImageFormat('png');
    $png = $img->getImageBlob();
    $img->destroy();
} else {
    $src  = imagecreatefromstring(file_get_contents($source));
    $orig_w = imagesx($src);
    $orig_h = imagesy($src);
    $target_w = (int)round($orig_w * $target_h / $orig_h);

    $out = imagecreatetruecolor($target_w, $target_h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $transparent);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $target_w, $target_h, $orig_w, $orig_h);
    imagedestroy($src);

    ob_start();
    imagepng($out, null, 9);
    $png = ob_get_clean();
    imagedestroy($out);
}

file_put_contents($cache_file, $png);
echo $png;
