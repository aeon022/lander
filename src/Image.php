<?php
declare(strict_types=1);

/**
 * Image variants (resize / crop / re-encode) with GD, cached on disk. Sizes, ratios and formats are snapped to
 * small fixed sets so the number of cached variants stays bounded even on the public endpoint.
 * ?w=800  ?ar=16:9  ?fmt=webp|jpg|png|avif   (never upscales; crops around the focal point)
 */
final class Image
{
    private const WIDTHS = [160, 320, 480, 640, 800, 1024, 1280, 1600, 1920, 2400];
    private const RATIOS = ['1:1' => 1.0, '4:3' => 4 / 3, '3:2' => 1.5, '16:9' => 16 / 9, '21:9' => 21 / 9, '3:4' => 0.75, '2:3' => 2 / 3, '9:16' => 9 / 16];
    private const FORMATS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif'];
    private const MAX_PIXELS = 40_000_000;

    public static function wanted(): bool { return isset($_GET['w']) || isset($_GET['ar']) || isset($_GET['fmt']); }

    /** Serve a variant; returns false when it cannot (no GD, not an image, bad input), so the caller falls back to the original. */
    public static function serve(array $media, string $cacheDir): bool
    {
        if (!extension_loaded('gd')) return false;
        $path = (string)($media['path'] ?? '');
        if ($path === '' || !is_file($path) || !in_array($media['mime_type'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return false;
        if (filesize($path) > 30 * 1048576) return false;
        $info = @getimagesize($path);
        if (!$info || $info[0] * $info[1] > self::MAX_PIXELS) return false;

        $w = (int)($_GET['w'] ?? 0);
        $target = null;
        foreach (self::WIDTHS as $s) if ($s >= $w) { $target = $s; break; }
        $target = $w > 0 ? ($target ?? 2400) : null;
        $ar = (string)($_GET['ar'] ?? ''); $ratio = self::RATIOS[$ar] ?? null;
        $fmt = (string)($_GET['fmt'] ?? '');
        if (!isset(self::FORMATS[$fmt])) $fmt = match ($media['mime_type']) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
        if ($fmt === 'avif' && !function_exists('imageavif')) $fmt = 'webp';
        if ($fmt === 'webp' && !function_exists('imagewebp')) $fmt = 'jpg';
        $fx = max(0.0, min(1.0, (float)($media['focal_x'] ?? 0.5))); $fy = max(0.0, min(1.0, (float)($media['focal_y'] ?? 0.5)));

        $key = sha1(implode('|', [$media['id'], filemtime($path), $target, $ar, $fmt, round($fx, 2), round($fy, 2)])) . '.' . $fmt;
        $file = rtrim($cacheDir, '/') . '/' . $key;
        if (!is_file($file)) {
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true)) return false;
            $src = @imagecreatefromstring((string)file_get_contents($path));
            if (!$src) return false;
            [$sw, $sh] = [imagesx($src), imagesy($src)];
            [$cw, $ch] = [$sw, $sh];
            if ($ratio) { if ($sw / $sh > $ratio) $cw = (int)round($sh * $ratio); else $ch = (int)round($sw / $ratio); }
            $cx = (int)max(0, min($sw - $cw, round($fx * $sw - $cw / 2))); $cy = (int)max(0, min($sh - $ch, round($fy * $sh - $ch / 2)));
            $ow = $target ? min($target, $cw) : $cw; $oh = (int)round($ch * $ow / $cw);
            $dst = imagecreatetruecolor($ow, $oh);
            if ($fmt !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); } else imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $ow, $oh, $cw, $ch);
            $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
            $ok = match ($fmt) { 'png' => imagepng($dst, $tmp, 6), 'webp' => imagewebp($dst, $tmp, 82), 'avif' => imageavif($dst, $tmp, 60), default => imagejpeg($dst, $tmp, 82) };
            if (!$ok) { @unlink($tmp); return false; }
            rename($tmp, $file);
        }
        header('Content-Type: ' . self::FORMATS[$fmt]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);
        return true;
    }
}
