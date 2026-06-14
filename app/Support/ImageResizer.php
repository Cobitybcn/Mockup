<?php
declare(strict_types=1);

class ImageResizer
{
    /**
     * Resizes a mockup image proportionally so that its shortest side becomes exactly 2200 px.
     * Keeps the original image with a '.original' suffix and overwrites the original file path.
     *
     * @param string $filePath Absolute path to the generated image file.
     * @return bool True if successful, false otherwise.
     */
    public static function resize(string $filePath): bool
    {
        if (!is_file($filePath)) {
            Logger::log("ImageResizer: File not found at " . $filePath, 'error');
            return false;
        }

        $info = @getimagesize($filePath);
        if (!$info) {
            Logger::log("ImageResizer: Could not get image size for " . $filePath, 'error');
            return false;
        }

        $w = $info[0];
        $h = $info[1];
        $mime = $info['mime'] ?? '';

        // Skip vector SVG files since GD cannot read/write them as raster images.
        if (str_contains($mime, 'svg') || pathinfo($filePath, PATHINFO_EXTENSION) === 'svg') {
            Logger::log("ImageResizer: Skipping SVG vector file: " . basename($filePath), 'image');
            return true;
        }

        if ($w <= 0 || $h <= 0) {
            Logger::log("ImageResizer: Invalid image dimensions {$w}x{$h} for " . $filePath, 'error');
            return false;
        }

        // Determine target dimensions where shortest side is exactly 2200 px.
        if ($w < $h) {
            $newW = 2200;
            $newH = (int)round($h * (2200 / $w));
        } else {
            $newH = 2200;
            $newW = (int)round($w * (2200 / $h));
        }

        // Avoid unnecessary scale-up or scale-down if it is already exactly 2200 on shortest side
        if (min($w, $h) === 2200) {
            Logger::log("ImageResizer: Shortest side is already 2200 px for " . basename($filePath) . ". Creating copy and applying subtle sharpen.", 'image');
        }

        // Load image from path
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $srcImage = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $srcImage = @imagecreatefrompng($filePath);
                break;
            case 'image/webp':
                $srcImage = @imagecreatefromwebp($filePath);
                break;
            case 'image/gif':
                $srcImage = @imagecreatefromgif($filePath);
                break;
            default:
                $srcImage = @imagecreatefromstring(file_get_contents($filePath));
                break;
        }

        if (!$srcImage) {
            Logger::log("ImageResizer: Failed to create GD image resource from " . $filePath, 'error');
            return false;
        }

        // Backup the original generated image separately in the same folder.
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $dir = pathinfo($filePath, PATHINFO_DIRNAME);
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        $backupPath = $dir . DIRECTORY_SEPARATOR . $filename . '.original.' . $ext;

        if (!@copy($filePath, $backupPath)) {
            Logger::log("ImageResizer: Failed to create original backup at " . $backupPath, 'error');
            imagedestroy($srcImage);
            return false;
        }

        // Perform proportional scaling
        $destImage = null;
        if (min($w, $h) === 2200) {
            // Already at the target size, just clone the resource to apply sharpening
            $destImage = imagecreatetruecolor($w, $h);
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($destImage, false);
                imagesavealpha($destImage, true);
            }
            imagecopy($destImage, $srcImage, 0, 0, 0, 0, $w, $h);
        } else {
            // Use Sinc interpolation (Lanczos) if supported by GD, otherwise fallback
            $mode = defined('IMG_SINC') ? IMG_SINC : (defined('IMG_BICUBIC') ? IMG_BICUBIC : IMG_BILINEAR_FIXED);
            Logger::log("ImageResizer: Scaling " . basename($filePath) . " from {$w}x{$h} to {$newW}x{$newH} using mode: " . $mode, 'image');
            
            $destImage = @imagescale($srcImage, $newW, $newH, $mode);
        }

        if (!$destImage) {
            Logger::log("ImageResizer: imagescale failed. Falling back to imagecopyresampled.", 'warning');
            $destImage = imagecreatetruecolor($newW, $newH);
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($destImage, false);
                imagesavealpha($destImage, true);
            }
            if (!@imagecopyresampled($destImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $w, $h)) {
                Logger::log("ImageResizer: imagecopyresampled failed.", 'error');
                @unlink($backupPath);
                imagedestroy($srcImage);
                imagedestroy($destImage);
                return false;
            }
        }

        // Apply a very subtle sharpening filter post-resize
        $sharpenMatrix = [
            [0, -0.05, 0],
            [-0.05, 1.2, -0.05],
            [0, -0.05, 0]
        ];
        $divisor = 1.0;
        $offset = 0.0;
        @imageconvolution($destImage, $sharpenMatrix, $divisor, $offset);

        // Save the resized image as the final publishable image (overwriting the original file path)
        $saved = false;
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $saved = @imagejpeg($destImage, $filePath, 95); // High quality JPEG
                break;
            case 'image/png':
                $saved = @imagepng($destImage, $filePath, 6); // Standard PNG compression
                break;
            case 'image/webp':
                $saved = @imagewebp($destImage, $filePath, 90); // WebP quality
                break;
            case 'image/gif':
                $saved = @imagegif($destImage, $filePath);
                break;
            default:
                if ($ext === 'png') {
                    $saved = @imagepng($destImage, $filePath);
                } else {
                    $saved = @imagejpeg($destImage, $filePath, 95);
                }
                break;
        }

        // Clean up resources
        imagedestroy($srcImage);
        imagedestroy($destImage);

        if (!$saved) {
            Logger::log("ImageResizer: Failed to save resized image to " . $filePath, 'error');
            // Restore backup to original file path
            @copy($backupPath, $filePath);
            @unlink($backupPath);
            return false;
        }

        Logger::log("ImageResizer: Successfully resized " . basename($filePath) . " and backed up the original.", 'image');
        return true;
    }
}
