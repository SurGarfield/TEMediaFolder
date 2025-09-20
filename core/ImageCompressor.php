<?php

namespace TypechoPlugin\TEMediaFolder\Core;

/**
 * 简化的图片压缩处理器 - 只保留WebP压缩功能
 * 兰空图床特别处理为JPG压缩
 */
class ImageCompressor
{
    /**
     * 计算并尽力保证足够内存以处理指定尺寸的图片。
     * 返回是否认为内存充足（可能通过提升 memory_limit 实现）。
     */
    private static function ensureMemoryForImage(int $width, int $height, float $safetyFactor = 2.0): bool
    {
        // 粗略估算：每像素按4~6字节计（GD内部RGBA+开销），再留出余量
        $bytesPerPixel = 6.0;
        $estimated = (int)ceil($width * $height * $bytesPerPixel * $safetyFactor);

        $usage = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        $limit = self::getPhpMemoryLimitBytes();
        if ($limit > 0 && ($usage + $estimated) < $limit) {
            return true;
        }

        // 尝试临时提升 memory_limit（到 512M 或 1024M）
        $targets = ['1024M', '768M', '512M'];
        foreach ($targets as $t) {
            @ini_set('memory_limit', $t);
            $limit = self::getPhpMemoryLimitBytes();
            if ($limit > 0 && ($usage + $estimated) < $limit) {
                return true;
            }
        }
        return false;
    }

    private static function getPhpMemoryLimitBytes(): int
    {
        $val = @ini_get('memory_limit');
        if ($val === false || $val === '' || $val === '-1') {
            return -1; // unlimited
        }
        $last = strtolower(substr($val, -1));
        $num = (int)$val;
        switch ($last) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default: return (int)$val;
        }
    }
    /**
     * 检测并处理图片压缩
     * 
     * @param string $filePath 原始文件路径
     * @param string $fileName 文件名
     * @return array 处理结果 ['path' => 处理后的文件路径, 'compressed' => 是否被压缩]
     */
    public static function processImage($filePath, $fileName, $targetStorage = null)
    {
        // 快速检查
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['path' => $filePath, 'compressed' => false, 'error' => 'File not readable'];
        }

        // 图片类型检查
        if (!self::isImageFile($fileName)) {
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'Not an image file'];
        }

        // 文件大小预检查，太小的文件不值得压缩
        $fileSize = filesize($filePath);
        if ($fileSize < 50 * 1024) { // 小于50KB
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'File too small to compress'];
        }

        try {
            // 检查外部压缩插件（优先级最高）
            $externalPlugins = [
                'ToWebp' => 'processWithToWebpPlugin',
                'ImageCompress' => 'processWithImageCompressPlugin'
            ];
            
            foreach ($externalPlugins as $pluginName => $method) {
                if (self::isPluginActive($pluginName)) {
                    $result = self::$method($filePath, $fileName);
                    if ($result['compressed']) {
                        return $result;
                    }
                }
            }

            // 使用内置WebP压缩（使用布尔配置）
            $config = ConfigManager::getInstance();
            $webpEnabled = (bool)$config->get('enableWebpCompression', true);
            if ($webpEnabled) {
                return self::processWithWebP($filePath, $fileName, $targetStorage);
            }

            return ['path' => $filePath, 'compressed' => false, 'reason' => 'WebP compression disabled'];

        } catch (\Exception $e) {
            return ['path' => $filePath, 'compressed' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 简化的WebP压缩处理
     */
    private static function processWithWebP($filePath, $fileName, $targetStorage = null)
    {
        // 检查GD扩展和WebP支持
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            // WebP不可用时，降级到质量压缩
            return self::processQualityCompression($filePath, $fileName);
        }

        $config = ConfigManager::getInstance();
        $currentStorage = $targetStorage ?: $config->getStorage();
        
        // 兰空图床特殊处理：转换为JPG
        if ($currentStorage === 'lsky') {
            return self::processLskyCompression($filePath, $fileName);
        }
        
        // 其他存储方式：转换为WebP
        $result = self::processWebPCompression($filePath, $fileName);
        if (!$result['compressed']) {
            // WebP失败时，降级到质量压缩
            return self::processQualityCompression($filePath, $fileName);
        }
        return $result;
    }

    /**
     * 兰空图床压缩处理（转换为JPG）
     */
    private static function processLskyCompression($filePath, $fileName)
    {
        $config = ConfigManager::getInstance();
        $quality = max(1, min(100, (int)$config->get('webpQuality', '80')));
        
        // 获取图片信息
        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'Cannot get image info'];
        }
        
        $sourceImage = null;
        
        // 先进行内存评估/提升
        $width = imagesx(@imagecreatetruecolor(1,1)); // 防御性调用，确保GD初始化
        $imgW = $imageInfo[0];
        $imgH = $imageInfo[1];
        $hasMemory = self::ensureMemoryForImage($imgW, $imgH, 2.0);

        // 根据图片类型创建资源
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($filePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($filePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = @imagecreatefromgif($filePath);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = @imagecreatefromwebp($filePath);
                break;
            default:
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'Unsupported image type'];
        }
        
        if (!$sourceImage) {
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'Cannot create image resource'];
        }
        
        // 计算目标尺寸：先限制最长边1080，再考虑内存缩放
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $maxSide = 1080;
        $longer = max($width, $height);
        $capScale = ($longer > $maxSide) ? ($maxSide / $longer) : 1.0;

        $memScale = 1.0;
        if (!$hasMemory) {
            // 以memory_limit估算最大可承载像素，向下缩放
            $limit = self::getPhpMemoryLimitBytes();
            $usage = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            $available = ($limit > 0) ? max(0, $limit - $usage) : (512 * 1024 * 1024);
            $bytesPerPixel = 6.0 * 2.0; // 安全系数
            $maxPixels = (int)floor($available / $bytesPerPixel);
            if ($maxPixels > 0) {
                $pixels = $width * $height;
                if ($pixels > $maxPixels) {
                    $memScale = sqrt($maxPixels / $pixels);
                }
            }
        }
        $finalScale = max(0.01, min($capScale, $memScale));
        $jpgW = max(1, (int)floor($width * $finalScale));
        $jpgH = max(1, (int)floor($height * $finalScale));

        $jpgImage = imagecreatetruecolor($jpgW, $jpgH);
        
        // 白色背景（适合JPG）
        $white = imagecolorallocate($jpgImage, 255, 255, 255);
        imagefill($jpgImage, 0, 0, $white);
        
        // 复制/缩放图片
        if ($jpgW !== $width || $jpgH !== $height) {
            imagecopyresampled($jpgImage, $sourceImage, 0, 0, 0, 0, $jpgW, $jpgH, $width, $height);
        } else {
            imagecopy($jpgImage, $sourceImage, 0, 0, 0, 0, $width, $height);
        }
        
        // 生成新的文件名（.jpg扩展名）- 使用原始文件名
        $originalPathInfo = pathinfo($fileName);
        $newFileName = $originalPathInfo['filename'] . '.jpg';
        $tempDir = dirname($filePath);
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . $newFileName;
        
        // 保存为JPG
        $success = imagejpeg($jpgImage, $outputPath, $quality);
        
        // 清理资源
        imagedestroy($sourceImage);
        imagedestroy($jpgImage);
        
        if ($success) {
            // 仅当新文件更小才采用，否则回退原文件
            $originalSize = @filesize($filePath) ?: PHP_INT_MAX;
            $newSize = @filesize($outputPath) ?: PHP_INT_MAX;
            if ($newSize < $originalSize * 0.98) { // 至少节省约2%
                return ['path' => $outputPath, 'compressed' => true, 'format' => 'jpg'];
            }
            @unlink($outputPath);
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'jpg not smaller'];
        }
        
        return ['path' => $filePath, 'compressed' => false, 'reason' => 'Failed to save JPG'];
    }

    /**
     * WebP压缩处理
     */
    private static function processWebPCompression($filePath, $fileName)
    {
        $config = ConfigManager::getInstance();
        $quality = max(1, min(100, (int)$config->get('webpQuality', '80')));
        
        // 获取图片信息
        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'Cannot get image info'];
        }
        
        $sourceImage = null;
        
        // 先进行内存评估/提升
        $imgW = $imageInfo[0];
        $imgH = $imageInfo[1];
        $hasMemory = self::ensureMemoryForImage($imgW, $imgH, 2.0);

        // 根据图片类型创建资源
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($filePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($filePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = @imagecreatefromgif($filePath);
                break;
            case IMAGETYPE_WEBP:
                // 已经是WebP格式，直接返回
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'Already WebP format'];
            default:
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'Unsupported image type'];
        }
        
        if (!$sourceImage) {
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'Cannot create image resource'];
        }
        
        // 生成新的文件名（.webp扩展名）- 使用原始文件名
        $originalPathInfo = pathinfo($fileName);
        $newFileName = $originalPathInfo['filename'] . '.webp';
        $tempDir = dirname($filePath);
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . $newFileName;
        
        // 处理PNG透明度
        if ($imageInfo[2] === IMAGETYPE_PNG) {
            imagesavealpha($sourceImage, true);
        }
        
        // 统一按1080上限与内存情况缩放
        $srcW = imagesx($sourceImage);
        $srcH = imagesy($sourceImage);
        $maxSide = 1080;
        $longer = max($srcW, $srcH);
        $capScale = ($longer > $maxSide) ? ($maxSide / $longer) : 1.0;

        $memScale = 1.0;
        if (!$hasMemory) {
            $limit = self::getPhpMemoryLimitBytes();
            $usage = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            $available = ($limit > 0) ? max(0, $limit - $usage) : (512 * 1024 * 1024);
            $bytesPerPixel = 6.0 * 2.0;
            $maxPixels = (int)floor($available / $bytesPerPixel);
            if ($maxPixels > 0 && ($srcW * $srcH) > $maxPixels) {
                $memScale = sqrt($maxPixels / ($srcW * $srcH));
            }
        }
        $finalScale = max(0.01, min($capScale, $memScale));
        if ($finalScale < 0.999) {
            $dstW = max(1, (int)floor($srcW * $finalScale));
            $dstH = max(1, (int)floor($srcH * $finalScale));
            $tmp = imagecreatetruecolor($dstW, $dstH);
            if ($tmp) {
                // 尝试保留透明度（针对PNG/GIF）
                if ($imageInfo[2] === IMAGETYPE_PNG || $imageInfo[2] === IMAGETYPE_GIF) {
                    imagealphablending($tmp, false);
                    imagesavealpha($tmp, true);
                    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
                    imagefill($tmp, 0, 0, $transparent);
                }
                imagecopyresampled($tmp, $sourceImage, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
                imagedestroy($sourceImage);
                $sourceImage = $tmp;
            }
        }

        // 保存为WebP
        $success = imagewebp($sourceImage, $outputPath, $quality);
        
        // 清理资源
        imagedestroy($sourceImage);
        
        if ($success) {
            // 仅当新文件更小才采用，否则回退原文件
            $originalSize = @filesize($filePath) ?: PHP_INT_MAX;
            $newSize = @filesize($outputPath) ?: PHP_INT_MAX;
            if ($newSize < $originalSize * 0.98) {
                return ['path' => $outputPath, 'compressed' => true, 'format' => 'webp'];
            }
            @unlink($outputPath);
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'webp not smaller'];
        }
        
        // 失败时降级到质量压缩
        return self::processQualityCompression($filePath, $fileName);
    }

    /**
     * 质量压缩（降级方案：在WebP不可用或失败时使用）
     */
    private static function processQualityCompression($filePath, $fileName)
    {
            if (!extension_loaded('gd')) {
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'GD extension not available'];
            }

        $imageInfo = @getimagesize($filePath);
            if (!$imageInfo) {
            return ['path' => $filePath, 'compressed' => false, 'reason' => 'Cannot get image info'];
        }

        $config = ConfigManager::getInstance();
        $quality = max(1, min(100, (int)$config->get('webpQuality', '80')));

            $sourceImage = null;
        $extension = null;
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($filePath);
                $extension = 'jpg';
                    break;
                case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($filePath);
                $extension = 'png';
                    break;
            case IMAGETYPE_GIF:
                // GIF不做质量压缩，直接返回
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'Skip GIF quality compression'];
                default:
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'Unsupported image type for quality compression'];
            }

            if (!$sourceImage) {
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'Cannot create image resource'];
            }

        // 输出到临时文件（保持原扩展名）
        $originalPathInfo = pathinfo($fileName);
        $tempDir = dirname($filePath);
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . $originalPathInfo['filename'] . '.' . $extension;

        $ok = false;
        if ($extension === 'jpg') {
            $ok = imagejpeg($sourceImage, $outputPath, $quality);
        } else if ($extension === 'png') {
            imagesavealpha($sourceImage, true);
            // 将质量(1-100)映射到PNG压缩级别(0-9，数值越小越高质量)
            $pngLevel = max(0, min(9, 9 - (int)round($quality / 100 * 9)));
            $ok = imagepng($sourceImage, $outputPath, $pngLevel);
            }

            imagedestroy($sourceImage);

        if ($ok) {
            return ['path' => $outputPath, 'compressed' => true, 'format' => $extension];
        }

        return ['path' => $filePath, 'compressed' => false, 'reason' => 'Quality compression failed'];
    }

    /**
     * 检查插件是否已激活
     */
    private static function isPluginActive($pluginName)
    {
        static $activePlugins = null;
        if ($activePlugins === null) {
            $activePlugins = [];
            $plugins = \Typecho\Plugin::export();
            if (isset($plugins['activated'])) {
                $activePlugins = array_keys($plugins['activated']);
            }
        }
        return in_array($pluginName, $activePlugins);
    }

    /**
     * 检查是否为图片文件
     */
    private static function isImageFile($fileName)
    {
        static $imageExtensions = null;
        if ($imageExtensions === null) {
            $imageExtensions = [
                'jpg' => true, 'jpeg' => true, 'png' => true, 
                'gif' => true, 'webp' => true, 'bmp' => true,
                'tiff' => true, 'tif' => true, 'ico' => true,
                'svg' => true
            ];
        }
        
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return isset($imageExtensions[$ext]);
    }

    /**
     * 处理ToWebp插件
     */
    private static function processWithToWebpPlugin($filePath, $fileName)
    {
        try {
            if (!class_exists('\TypechoPlugin\ToWebp\Plugin')) {
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'ToWebp plugin class not found'];
            }
            
            // 检查ToWebp插件的处理方法
            if (method_exists('\TypechoPlugin\ToWebp\Plugin', 'convertToWebp')) {
                $result = \TypechoPlugin\ToWebp\Plugin::convertToWebp($filePath);
                if ($result && $result !== $filePath) {
                    return ['path' => $result, 'compressed' => true, 'plugin' => 'ToWebp'];
                }
            }
            
        } catch (\Exception $e) {
            // 插件处理失败，继续使用内置处理
        }
        
        return ['path' => $filePath, 'compressed' => false, 'reason' => 'ToWebp plugin failed'];
    }

    /**
     * 处理ImageCompress插件
     */
    private static function processWithImageCompressPlugin($filePath, $fileName)
    {
        try {
            if (!class_exists('\TypechoPlugin\ImageCompress\Plugin')) {
                return ['path' => $filePath, 'compressed' => false, 'reason' => 'ImageCompress plugin class not found'];
            }
            
            // 检查ImageCompress插件的处理方法
            if (method_exists('\TypechoPlugin\ImageCompress\Plugin', 'compressImage')) {
                $result = \TypechoPlugin\ImageCompress\Plugin::compressImage($filePath);
                if ($result && $result !== $filePath) {
                    return ['path' => $result, 'compressed' => true, 'plugin' => 'ImageCompress'];
                }
            }
            
        } catch (\Exception $e) {
            // 插件处理失败，继续使用内置处理
        }
        
        return ['path' => $filePath, 'compressed' => false, 'reason' => 'ImageCompress plugin failed'];
    }

    /**
     * 清理临时文件
     */
    public static function cleanupTempFile($filePath)
    {
        // 如果是临时文件且不是原始文件，则删除
        if ($filePath && file_exists($filePath) && strpos($filePath, sys_get_temp_dir()) !== false) {
            @unlink($filePath);
        }
    }
}
