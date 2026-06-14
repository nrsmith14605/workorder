<?php
/**
 * icon.php — generates the WCSC Work Orders PWA icon on the fly.
 * Draws a cyan gear on a navy background using GD.
 * Caches the result as a static PNG file after first generation.
 */
$size = in_array((int)($_GET['size'] ?? 192), [192, 512]) ? (int)$_GET['size'] : 192;
$cache_file = __DIR__ . "/icon-{$size}.png";

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');

if (file_exists($cache_file)) {
    readfile($cache_file);
    exit;
}

// ── Canvas ───────────────────────────────────────────────────
$s   = $size;
$img = imagecreatetruecolor($s, $s);
imagesavealpha($img, true);

$navy  = imagecolorallocate($img, 11, 31, 46);     // #0B1F2E
$cyan  = imagecolorallocate($img, 41, 182, 213);   // #29b6d5
$trans = imagecolorallocatealpha($img, 0, 0, 0, 127);

imagefill($img, 0, 0, $navy);

// ── Rounded background square ─────────────────────────────────
// Drawn as a filled rounded rect using arcs at corners
$r    = (int)($s * 0.18);   // corner radius
imagefilledrectangle($img, $r, 0, $s - $r, $s, $navy);
imagefilledrectangle($img, 0, $r, $s, $s - $r, $navy);
imagefilledarc($img, $r,      $r,      $r * 2, $r * 2, 180, 270, $navy, IMG_ARC_PIE);
imagefilledarc($img, $s - $r, $r,      $r * 2, $r * 2, 270, 360, $navy, IMG_ARC_PIE);
imagefilledarc($img, $r,      $s - $r, $r * 2, $r * 2,  90, 180, $navy, IMG_ARC_PIE);
imagefilledarc($img, $s - $r, $s - $r, $r * 2, $r * 2,   0,  90, $navy, IMG_ARC_PIE);

// ── Gear parameters ───────────────────────────────────────────
$cx       = $s / 2;
$cy       = $s / 2;
$n_teeth  = 8;
$r_outer  = $s * 0.39;   // tip of teeth
$r_inner  = $s * 0.28;   // base of teeth (gear body edge)
$r_body   = $s * 0.26;   // filled gear body radius
$r_hub    = $s * 0.10;   // center hole

// Draw gear body circle
imagefilledellipse($img, (int)$cx, (int)$cy, (int)($r_body * 2), (int)($r_body * 2), $cyan);

// Draw teeth as polygons
for ($i = 0; $i < $n_teeth; $i++) {
    $angle     = ($i / $n_teeth) * 2 * M_PI - M_PI / 2;
    $step      = M_PI / $n_teeth;
    $hw        = $step * 0.42;   // half angular width of tooth

    $pts = [
        (int)round($cx + $r_inner * cos($angle - $hw)),
        (int)round($cy + $r_inner * sin($angle - $hw)),
        (int)round($cx + $r_outer * cos($angle - $hw * 0.5)),
        (int)round($cy + $r_outer * sin($angle - $hw * 0.5)),
        (int)round($cx + $r_outer * cos($angle + $hw * 0.5)),
        (int)round($cy + $r_outer * sin($angle + $hw * 0.5)),
        (int)round($cx + $r_inner * cos($angle + $hw)),
        (int)round($cy + $r_inner * sin($angle + $hw)),
    ];
    imagefilledpolygon($img, $pts, 4, $cyan);
}

// Center hub cutout (navy circle)
imagefilledellipse($img, (int)$cx, (int)$cy, (int)($r_hub * 2), (int)($r_hub * 2), $navy);

// ── Output and cache ──────────────────────────────────────────
ob_start();
imagepng($img);
$png = ob_get_clean();
imagedestroy($img);

file_put_contents($cache_file, $png);
echo $png;
