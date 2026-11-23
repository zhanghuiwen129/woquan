<?php
/**
 * Hublog 图片水印处理类
 *
 * 支持「文字水印」与「图片水印」两种模式，配置存放于 settings 表（key 前缀 watermark_）。
 * 调用时机：Upload::compressAndSave() 中 GD 缩放完成、输出落盘之前。
 *
 * 设计要点：
 * 1. 透明度统一用 imagecopymerge 的 pct 参数实现（先把水印绘制到透明图层，再按 pct 合并），
 *    避免 imagettftext 直接写半透明文字在不同 GD 版本下的表现差异。
 * 2. 文字水印自动探测系统中文字体（Windows / Linux / macOS 常见路径），
 *    找不到字体且文案含非 ASCII 字符时跳过并记日志，避免画出乱码方块。
 * 3. GIF 一律跳过：GD 只能取第一帧，加水印会把动图压成静态图。
 * 4. 任何异常都不能中断上传流程 —— 水印是锦上添花，失败只记日志。
 */

namespace Hublog\Core;

class Watermark
{
    /** 支持的水印位置（tile = 平铺） */
    public const POSITIONS = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
        'tile',
    ];

    /** 配置默认值（同时被 Migration V43 用于写入数据库） */
    public static function defaults(): array
    {
        return [
            'watermark_enabled'       => '0',
            'watermark_type'          => 'text',   // text | image
            'watermark_text'          => '',
            'watermark_font_size'     => '16',
            'watermark_color'         => '#FFFFFF',
            'watermark_opacity'       => '60',     // 0-100
            'watermark_position'      => 'bottom-right',
            'watermark_margin'        => '16',
            'watermark_rotate'        => '0',      // 顺时针角度
            'watermark_shadow'        => '1',      // 文字描边，提升浅色背景可读性
            'watermark_image'         => '',       // 图片水印 URL（相对路径）
            'watermark_image_scale'   => '20',     // 占原图宽度的百分比
            'watermark_min_size'      => '200',    // 短边小于此值不加水印
            'watermark_apply_avatar'  => '0',      // 是否对头像加水印
            'watermark_font_path'     => '',       // 自定义字体绝对路径（留空自动探测）
        ];
    }

    /**
     * 读取当前水印配置（默认值兜底）
     *
     * 只在请求内做静态缓存：settings 表数据量很小，换请求即重新读取，
     * 免去 Cache 失效导致「后台改了不生效」的问题。
     */
    /** 请求内配置缓存，后台保存设置后置空即可即时生效 */
    private static ?array $configCache = null;

    public static function config(bool $refresh = false): array
    {
        if (self::$configCache !== null && !$refresh) {
            return self::$configCache;
        }

        $cfg = self::defaults();
        try {
            $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
            foreach ($rows as $row) {
                $key = (string)($row['key'] ?? '');
                if (str_starts_with($key, 'watermark_')) {
                    $cfg[$key] = (string)($row['value'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Watermark] 读取配置失败: ' . $e->getMessage()); // @allow-error-log
        }

        self::$configCache = $cfg;
        return $cfg;
    }

    /** 清除请求内缓存（后台保存设置后调用） */
    public static function clearConfig(): void
    {
        self::$configCache = null;
    }

    /** 临时挂起标志：上传水印图片本身时不能给自己打水印 */
    private static bool $suspended = false;

    /**
     * 临时挂起 / 恢复水印（用于上传水印素材图，避免「给水印图再打水印」）
     */
    public static function suspend(bool $on = true): void
    {
        self::$suspended = $on;
    }

    /** 水印功能是否启用 */
    public static function enabled(): bool
    {
        if (self::$suspended) {
            return false;
        }
        return (self::config()['watermark_enabled'] ?? '0') === '1';
    }

    /**
     * 给 GD 图像资源打水印（使用数据库中的配置）
     *
     * @param \GdImage $image    目标图像资源
     * @param string   $mime     原图 MIME（用于跳过 GIF）
     * @param bool     $isAvatar 是否为头像（受 watermark_apply_avatar 控制）
     * @return bool 是否实际绘制了水印
     */
    public static function apply(\GdImage $image, string $mime = '', bool $isAvatar = false): bool
    {
        return self::applyWithConfig($image, self::config(), $mime, $isAvatar);
    }

    /**
     * 使用指定配置打水印（后台预览接口复用，不落库）
     */
    public static function applyWithConfig(\GdImage $image, array $cfg, string $mime = '', bool $isAvatar = false): bool
    {
        try {
            if (($cfg['watermark_enabled'] ?? '0') !== '1') {
                return false;
            }
            // GIF：GD 只取第一帧，加水印会破坏动图
            if ($mime === 'image/gif') {
                return false;
            }
            if ($isAvatar && ($cfg['watermark_apply_avatar'] ?? '0') !== '1') {
                return false;
            }

            $dstW = imagesx($image);
            $dstH = imagesy($image);
            if ($dstW <= 0 || $dstH <= 0) {
                return false;
            }

            // 小图不值得加水印（也避免水印盖住整张图）
            $minSize = max(0, (int)($cfg['watermark_min_size'] ?? 0));
            if ($minSize > 0 && min($dstW, $dstH) < $minSize) {
                return false;
            }

            $type = ($cfg['watermark_type'] ?? 'text') === 'image' ? 'image' : 'text';
            $stamp = $type === 'image'
                ? self::buildImageStamp($cfg, $dstW)
                : self::buildTextStamp($cfg);

            if (!$stamp) {
                return false;
            }

            $opacity = (int)($cfg['watermark_opacity'] ?? 60);
            $opacity = max(0, min(100, $opacity));

            $ok = self::stamp($image, $stamp, $cfg, $opacity);
            imagedestroy($stamp);
            return $ok;
        } catch (\Throwable $e) {
            // 水印失败绝不能阻断上传
            error_log('[Hublog Watermark] 添加水印失败: ' . $e->getMessage()); // @allow-error-log
            return false;
        }
    }

    /**
     * 把水印图层按配置合并到目标图
     */
    private static function stamp(\GdImage $dst, \GdImage $stamp, array $cfg, int $opacity): bool
    {
        $dstW = imagesx($dst);
        $dstH = imagesy($dst);
        $sw = imagesx($stamp);
        $sh = imagesy($stamp);
        if ($sw <= 0 || $sh <= 0) {
            return false;
        }

        $margin = max(0, (int)($cfg['watermark_margin'] ?? 16));
        $position = (string)($cfg['watermark_position'] ?? 'bottom-right');

        if ($position === 'tile') {
            $gapX = max(8, (int)($sw * 0.8)) + $margin;
            $gapY = max(8, (int)($sh * 0.8)) + $margin;
            $stepX = max(4, $sw + $gapX);
            $stepY = max(4, $sh + $gapY);

            $count = 0;
            for ($y = -$sh; $y < $dstH + $sh && $count < 2000; $y += $stepY) {
                for ($x = -$sw; $x < $dstW + $sw && $count < 2000; $x += $stepX) {
                    self::mergeAlpha($dst, $stamp, (int)$x, (int)$y, $opacity);
                    $count++;
                }
            }
            return $count > 0;
        }

        [$dx, $dy] = self::positionOffset($position, $dstW, $dstH, $sw, $sh, $margin);
        return self::mergeAlpha($dst, $stamp, $dx, $dy, $opacity);
    }

    /**
     * 自定义 alpha 合成，替代 imagecopymerge。
     *
     * 为什么不用 imagecopymerge：GD 在 truecolor 图层上对 alpha=127 的「完全透明」像素
     * 仍会复制其 RGB 信息，导致水印包围盒留下矩形色块。本函数按 src 像素 alpha
     * 逐点混合，跳过 alpha=127 的像素，杜绝矩形残留。
     */
    private static function mergeAlpha(\GdImage $dst, \GdImage $src, int $dx, int $dy, int $opacity): bool
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $dstW = imagesx($dst);
        $dstH = imagesy($dst);

        $sx0 = max(0, -$dx);
        $sy0 = max(0, -$dy);
        $sx1 = min($sw, $dstW - $dx);
        $sy1 = min($sh, $dstH - $dy);
        if ($sx1 <= $sx0 || $sy1 <= $sy0) {
            return false;
        }

        $opacity = max(0, min(100, $opacity)) / 100.0;
        if ($opacity <= 0) {
            return false;
        }

        for ($sy = $sy0; $sy < $sy1; $sy++) {
            for ($sx = $sx0; $sx < $sx1; $sx++) {
                $srcAlpha = (imagecolorsforindex($src, imagecolorat($src, $sx, $sy)))['alpha'];
                if ($srcAlpha >= 127) {
                    continue;
                }
                // GD alpha: 0=不透明, 127=透明
                $srcA = (127 - $srcAlpha) / 127.0;
                $w = $srcA * $opacity;
                if ($w <= 0.0001) {
                    continue;
                }

                $srcPx = imagecolorsforindex($src, imagecolorat($src, $sx, $sy));
                $dstPx = imagecolorsforindex($dst, imagecolorat($dst, $dx + $sx, $dy + $sy));

                $r = (int)($srcPx['red']   * $w + $dstPx['red']   * (1 - $w));
                $g = (int)($srcPx['green'] * $w + $dstPx['green'] * (1 - $w));
                $b = (int)($srcPx['blue']  * $w + $dstPx['blue']  * (1 - $w));

                imagesetpixel($dst, $dx + $sx, $dy + $sy, ($r << 16) | ($g << 8) | $b);
            }
        }
        return true;
    }

    /**
     * 计算九宫格位置坐标（并做边界收敛，防止水印比原图还大时被裁切）
     * @return array{0:int,1:int}
     */
    private static function positionOffset(string $position, int $dstW, int $dstH, int $sw, int $sh, int $margin): array
    {
        $centerX = (int)(($dstW - $sw) / 2);
        $centerY = (int)(($dstH - $sh) / 2);
        $right = $dstW - $sw - $margin;
        $bottom = $dstH - $sh - $margin;

        switch ($position) {
            case 'top-left':
                $x = $margin;
                $y = $margin;
                break;
            case 'top-center':
                $x = $centerX;
                $y = $margin;
                break;
            case 'top-right':
                $x = $right;
                $y = $margin;
                break;
            case 'middle-left':
                $x = $margin;
                $y = $centerY;
                break;
            case 'center':
                $x = $centerX;
                $y = $centerY;
                break;
            case 'middle-right':
                $x = $right;
                $y = $centerY;
                break;
            case 'bottom-left':
                $x = $margin;
                $y = $bottom;
                break;
            case 'bottom-center':
                $x = $centerX;
                $y = $bottom;
                break;
            default:
                $x = $right;
                $y = $bottom;
                break;
        }

        $x = max(0, min($x, max(0, $dstW - $sw)));
        $y = max(0, min($y, max(0, $dstH - $sh)));
        return [(int)$x, (int)$y];
    }

    /**
     * 生成文字水印图层（透明底 + 不透明文字，旋转后返回）
     */
    private static function buildTextStamp(array $cfg): ?\GdImage
    {
        $text = trim((string)($cfg['watermark_text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $size = (int)($cfg['watermark_font_size'] ?? 16);
        $size = max(6, min(300, $size));
        $font = self::resolveFont($cfg);

        if ($font === '') {
            // 无可用 TTF 字体：仅纯 ASCII 文案用内置点阵字体降级，中文直接跳过避免乱码
            if (preg_match('/[\x80-\xFF]/', $text)) {
                error_log('[Hublog Watermark] 未找到可用的中文字体，已跳过水印。可在后台「字体路径」指定 TTF/TTC 文件。'); // @allow-error-log
                return null;
            }
            return self::buildTextStampBuiltin($text, $cfg);
        }

        $bbox = @imagettfbbox($size, 0, $font, $text);
        if ($bbox === false) {
            return null;
        }

        $minX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $maxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $minY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $maxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $w = (int)($maxX - $minX) + 4;
        $h = (int)($maxY - $minY) + 4;
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        $layer = imagecreatetruecolor($w, $h);
        imagesavealpha($layer, true);
        imagealphablending($layer, false);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagealphablending($layer, true);

        [$r, $g, $b] = self::hexToRgb((string)($cfg['watermark_color'] ?? '#FFFFFF'));
        $color = imagecolorallocate($layer, $r, $g, $b);

        $baseX = 2 - $minX;
        $baseY = 2 - $minY;

        // 描边：浅色图片上白色文字会看不见，先画一圈半透明黑边
        if (($cfg['watermark_shadow'] ?? '1') === '1') {
            $outline = imagecolorallocatealpha($layer, 0, 0, 0, 96);
            for ($ox = -1; $ox <= 1; $ox++) {
                for ($oy = -1; $oy <= 1; $oy++) {
                    if ($ox === 0 && $oy === 0) {
                        continue;
                    }
                    imagettftext($layer, $size, 0, $baseX + $ox, $baseY + $oy, $outline, $font, $text);
                }
            }
        }
        imagettftext($layer, $size, 0, $baseX, $baseY, $color, $font, $text);

        imagealphablending($layer, false);
        imagesavealpha($layer, true);

        return self::rotate($layer, (int)($cfg['watermark_rotate'] ?? 0));
    }

    /**
     * 无 TTF 字体时的降级：内置点阵字体（仅支持 ASCII）
     */
    private static function buildTextStampBuiltin(string $text, array $cfg): ?\GdImage
    {
        $font = 5;
        $w = imagefontwidth($font) * strlen($text) + 4;
        $h = imagefontheight($font) + 4;

        $layer = imagecreatetruecolor($w, $h);
        imagesavealpha($layer, true);
        imagealphablending($layer, false);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));

        [$r, $g, $b] = self::hexToRgb((string)($cfg['watermark_color'] ?? '#FFFFFF'));
        $color = imagecolorallocate($layer, $r, $g, $b);
        imagestring($layer, $font, 2, 2, $text, $color);

        imagesavealpha($layer, true);
        return self::rotate($layer, (int)($cfg['watermark_rotate'] ?? 0));
    }

    /**
     * 生成图片水印图层（按原图宽度百分比缩放，旋转后返回）
     */
    private static function buildImageStamp(array $cfg, int $dstW): ?\GdImage
    {
        $url = trim((string)($cfg['watermark_image'] ?? ''));
        if ($url === '') {
            return null;
        }

        $file = self::resolveImagePath($url);
        if ($file === '') {
            error_log('[Hublog Watermark] 水印图片无效或不在 uploads 目录内: ' . $url); // @allow-error-log
            return null;
        }

        $info = @getimagesize($file);
        if ($info === false) {
            return null;
        }

        $src = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png'  => @imagecreatefrompng($file),
            'image/gif'  => @imagecreatefromgif($file),
            'image/webp' => @imagecreatefromwebp($file),
            default      => null,
        };
        if (!$src) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0) {
            imagedestroy($src);
            return null;
        }

        // 按占目标图宽度的百分比缩放；不放大，只缩小
        $scale = (int)($cfg['watermark_image_scale'] ?? 20);
        $scale = max(1, min(100, $scale));
        $targetW = max(1, (int)($dstW * $scale / 100));

        if ($targetW >= $sw) {
            $stamp = $src;
        } else {
            $targetH = max(1, (int)($sh * $targetW / $sw));
            $stamp = imagecreatetruecolor($targetW, $targetH);
            imagesavealpha($stamp, true);
            imagealphablending($stamp, false);
            imagefill($stamp, 0, 0, imagecolorallocatealpha($stamp, 0, 0, 0, 127));
            imagecopyresampled($stamp, $src, 0, 0, 0, 0, $targetW, $targetH, $sw, $sh);
            imagedestroy($src);
        }

        return self::rotate($stamp, (int)($cfg['watermark_rotate'] ?? 0));
    }

    /**
     * 顺时针旋转图层（imagerotate 是逆时针，故取 360 - angle）
     */
    private static function rotate(\GdImage $image, int $angle): \GdImage
    {
        $angle = ((int)$angle) % 360;
        if ($angle === 0) {
            return $image;
        }
        $dir = $angle < 0 ? 360 + $angle : $angle;
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, 360 - $dir, $transparent);
        if ($rotated === false) {
            return $image;
        }
        imagesavealpha($rotated, true);
        imagealphablending($rotated, false);
        imagedestroy($image);
        return $rotated;
    }

    /**
     * 探测系统可用的 TTF/TTC 字体（优先后台自定义路径）
     */
    private static function resolveFont(array $cfg): string
    {
        $custom = trim((string)($cfg['watermark_font_path'] ?? ''));
        if ($custom !== '' && is_file($custom)) {
            return $custom;
        }

        $candidates = [
            // Windows
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/msyhbd.ttc',
            'C:/Windows/Fonts/simhei.ttf',
            'C:/Windows/Fonts/simsun.ttc',
            'C:/Windows/Fonts/arial.ttf',
            // Linux 常见中文字体
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/wqy-zenhei/wqy-zenhei.ttc',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            // macOS
            '/System/Library/Fonts/PingFang.ttc',
            '/Library/Fonts/Arial Unicode.ttf',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return '';
    }

    /**
     * 水印图 URL → 物理路径（限定在 uploads 目录内，防路径穿越）
     */
    private static function resolveImagePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = str_replace('\\', '/', $path);

        $marker = 'uploads/';
        $pos = strpos($path, $marker);
        if ($pos === false) {
            return '';
        }

        $relative = substr($path, $pos + strlen($marker));
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        $uploadsDir = PathResolver::basePath() . '/uploads';
        $full = $uploadsDir . '/' . ltrim($relative, '/');

        $realFile = realpath($full);
        $realBase = realpath($uploadsDir);
        if ($realFile === false || $realBase === false || !is_file($realFile)) {
            return '';
        }
        if (!str_starts_with(str_replace('\\', '/', $realFile), str_replace('\\', '/', $realBase))) {
            return '';
        }
        return $realFile;
    }

    /**
     * #RRGGBB / #RGB → [r, g, b]
     * @return array{0:int,1:int,2:int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = 'FFFFFF';
        }
        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }
}
