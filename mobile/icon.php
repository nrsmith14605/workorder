<?php
$size = in_array((int)($_GET['size'] ?? 192), [120, 152, 167, 180, 192, 512]) ? (int)$_GET['size'] : 192;
$cache_file = __DIR__ . "/icon-{$size}.png";
$source     = __DIR__ . '/icon_template.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');

if (file_exists($cache_file)) {
    readfile($cache_file);
    exit;
}

if (class_exists('Imagick')) {
    $img = new Imagick($source);
    $img->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
    $img->setImageFormat('png');
    $png = $img->getImageBlob();
    $img->destroy();
} else {
    $src = imagecreatefromstring(file_get_contents($source));
    $scaled = imagecreatetruecolor($size, $size);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $bg = imagecolorallocate($scaled, 11, 31, 46); // #0B1F2E
    imagefill($scaled, 0, 0, $bg);
    imagealphablending($scaled, true);
    imagecopyresampled($scaled, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    imagedestroy($src);
    ob_start();
    imagepng($scaled);
    $png = ob_get_clean();
    imagedestroy($scaled);
}

file_put_contents($cache_file, $png);
echo $png;
