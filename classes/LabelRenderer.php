<?php

namespace Grav\Plugin\AiLabel;

use GdImage;

/**
 * Draws the AI label as a transparent PNG with GD.
 *
 * The label is drawn once per wording and design and then stamped onto images
 * with Grav's own `watermark()` image action, so this class knows nothing about
 * Grav, media or caching. Everything is drawn at four times the final size and
 * scaled down, which smooths the rounded corners and the built-in icon.
 */
class LabelRenderer
{
    private const SUPERSAMPLE = 4;

    /** Longest line, in multiples of the text size, before the wording wraps. */
    private const MAX_LINE_EM = 14;

    /** Hebrew, Arabic, Syriac, Thaana, NKo and the Hebrew/Arabic presentation forms. */
    private const RTL_CHARS = '\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}';

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'text' => '',
        'font' => '',
        'font_size' => 24,
        'text_color' => '#ffffff',
        'background_color' => '#000000',
        'background_opacity' => 70,
        'padding' => 10,
        'margin' => 12,
        'radius' => 6,
        'icon' => 'sparkle',
    ];

    /**
     * Renders the label to a PNG file. Returns false when there is nothing to
     * draw (no text and no icon) or GD cannot draw it.
     *
     * @param array<string, mixed> $options See DEFAULTS
     */
    public static function renderToFile(array $options, string $file): bool
    {
        $image = static::render($options);
        if (!$image) {
            return false;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        // Written to a temporary name first, so a request reading the label
        // never sees a half-written file.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = imagepng($image, $tmp, 9) && @rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $options See DEFAULTS
     */
    public static function render(array $options): ?GdImage
    {
        $o = $options + static::DEFAULTS;
        $k = static::SUPERSAMPLE;

        $text = trim((string) $o['text']);
        $font = static::fontOrDefault((string) $o['font']);

        $rtl = static::isRtl($text);

        $fontPx = max(4, (int) $o['font_size']) * $k;
        // GD sizes text in points at 96 dpi.
        $fontPt = $fontPx * 0.75;
        $padding = max(0, (int) $o['padding']) * $k;
        $margin = max(0, (int) $o['margin']) * $k;

        // The line box comes from a fixed sample, so every wording sits on the
        // same baseline and labels in different languages are the same height.
        $sample = imagettfbbox($fontPt, 0, $font, 'HÁgjy');
        $ascent = -$sample[7];
        $descent = $sample[1];
        $lineHeight = $ascent + $descent;

        // Long wording wraps, so the label stays readable when it is scaled
        // down to fit the image. Each line is put in drawing order after wrapping.
        $lines = [];
        foreach (static::wrap($text, $fontPt, $font, static::MAX_LINE_EM * $fontPx) as $line) {
            $line = static::visualOrder($line);
            $box = imagettfbbox($fontPt, 0, $font, $line);
            $left = min($box[0], $box[6]);
            $lines[] = ['text' => $line, 'left' => $left, 'width' => max($box[2], $box[4]) - $left];
        }
        $textWidth = $lines ? max(array_column($lines, 'width')) : 0;
        $linePitch = $lineHeight + (int) round($fontPx * 0.2);

        $icon = static::loadIcon((string) $o['icon'], $lineHeight);
        $iconWidth = $icon['width'] ?? 0;
        if ($textWidth === 0 && $iconWidth === 0) {
            return null;
        }
        $gap = ($textWidth && $iconWidth) ? (int) round($fontPx * 0.35) : 0;

        $boxWidth = $padding * 2 + $iconWidth + $gap + $textWidth;
        $boxHeight = $padding * 2 + $lineHeight + max(0, count($lines) - 1) * $linePitch;
        $width = $boxWidth + $margin * 2;
        $height = $boxHeight + $margin * 2;

        $canvas = static::transparentCanvas($width, $height);

        // Background. Blending is off so the rounded corners, drawn as
        // overlapping rectangles and circles, do not stack their transparency.
        [$r, $g, $b] = static::rgb((string) $o['background_color'], [0, 0, 0]);
        $opacity = max(0, min(100, (int) $o['background_opacity']));
        if ($opacity > 0) {
            $bg = imagecolorallocatealpha($canvas, $r, $g, $b, 127 - (int) round($opacity * 127 / 100));
            $radius = min(max(0, (int) $o['radius']) * $k, intdiv($boxHeight, 2), intdiv($boxWidth, 2));
            static::roundedRectangle($canvas, $margin, $margin, $margin + $boxWidth - 1, $margin + $boxHeight - 1, $radius, $bg);
        }

        imagealphablending($canvas, true);
        [$r, $g, $b] = static::rgb((string) $o['text_color'], [255, 255, 255]);
        $color = imagecolorallocatealpha($canvas, $r, $g, $b, 0);

        // The icon goes on the side the text starts from.
        $x = $margin + $padding;
        $top = $margin + $padding;
        $iconX = $rtl ? $x + $textWidth + $gap : $x;
        $textX = $rtl ? $x : $x + $iconWidth + $gap;

        if ($icon) {
            $iconY = $top + intdiv($lineHeight - $icon['height'], 2);
            if ($icon['image'] === null) {
                static::sparkle($canvas, $iconX, $iconY, $icon['width'], $color);
            } else {
                imagecopyresampled($canvas, $icon['image'], $iconX, $iconY, 0, 0, $icon['width'], $icon['height'], imagesx($icon['image']), imagesy($icon['image']));
            }
        }

        foreach ($lines as $i => $line) {
            // Lines line up on the side the text starts from.
            $lineX = $rtl ? $textX + $textWidth - $line['width'] : $textX;
            imagettftext($canvas, $fontPt, 0, $lineX - $line['left'], $top + $ascent + $i * $linePitch, $color, $font, $line['text']);
        }

        $out = static::transparentCanvas((int) round($width / $k), (int) round($height / $k));
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, imagesx($out), imagesy($out), $width, $height);

        return $out;
    }

    /**
     * Splits text into lines no wider than $maxWidth, at spaces. A single word
     * wider than that gets a line of its own.
     *
     * @return string[]
     */
    public static function wrap(string $text, float $fontPt, string $font, int $maxWidth): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $box = imagettfbbox($fontPt, 0, $font, $candidate);
            if ($line !== '' && max($box[2], $box[4]) - min($box[0], $box[6]) > $maxWidth) {
                $lines[] = $line;
                $candidate = $word;
            }
            $line = $candidate;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Whether the text reads right to left: its first letter is Hebrew,
     * Arabic or another right-to-left script.
     */
    public static function isRtl(string $text): bool
    {
        return (bool) preg_match('/^[^\p{L}]*[' . static::RTL_CHARS . ']/u', $text);
    }

    /**
     * Puts right-to-left text in the order GD has to draw it, since GD draws
     * characters left to right in the order they are stored. Runs of
     * right-to-left letters are reversed and, in right-to-left text, so is the
     * order of the runs, which keeps an embedded "AI" or a number readable.
     * Arabic letters are not joined, as GD cannot do that.
     */
    public static function visualOrder(string $text): string
    {
        if (!preg_match('/[' . static::RTL_CHARS . ']/u', $text)) {
            return $text;
        }

        $runs = preg_split(
            '/([' . static::RTL_CHARS . '](?:[' . static::RTL_CHARS . '\s\p{P}]*[' . static::RTL_CHARS . '])?)/u',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [$text];

        $rtl = static::isRtl($text);
        foreach ($runs as $i => $run) {
            if (preg_match('/[' . static::RTL_CHARS . ']/u', $run)) {
                $runs[$i] = implode('', array_reverse(mb_str_split($run)));
            } elseif ($rtl && preg_match('/^(\s*)(.*?)(\s*)$/su', $run, $m)) {
                // The spaces around a left-to-right word swap sides with it.
                $runs[$i] = $m[3] . $m[2] . $m[1];
            }
        }

        if ($rtl) {
            $runs = array_reverse($runs);
        }

        return implode('', $runs);
    }

    /**
     * Parses `#rgb` or `#rrggbb`.
     *
     * @param int[] $fallback
     * @return int[]
     */
    public static function rgb(string $hex, array $fallback): array
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return $fallback;
        }

        return array_map('hexdec', str_split($hex, 2));
    }

    public static function fontOrDefault(string $font): string
    {
        return is_file($font) ? $font : dirname(__DIR__) . '/fonts/DejaVuSans-Bold.ttf';
    }

    /**
     * The icon at the height of a line of text: the built-in sparkle, an image
     * file, or nothing.
     *
     * @return array{image: GdImage|null, width: int, height: int}|null
     */
    private static function loadIcon(string $icon, int $lineHeight): ?array
    {
        if ($icon === '' || $icon === 'none') {
            return null;
        }

        if ($icon === 'sparkle') {
            return ['image' => null, 'width' => $lineHeight, 'height' => $lineHeight];
        }

        $data = is_file($icon) ? @file_get_contents($icon) : false;
        $image = $data ? @imagecreatefromstring($data) : false;
        if (!$image) {
            return null;
        }

        $height = $lineHeight;
        $width = max(1, (int) round(imagesx($image) * $height / max(1, imagesy($image))));

        return ['image' => $image, 'width' => $width, 'height' => $height];
    }

    private static function transparentCanvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    private static function roundedRectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        if ($radius < 1) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
            return;
        }

        $d = $radius * 2;
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $d, $d, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $d, $d, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $d, $d, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $d, $d, $color);
    }

    /**
     * A four-pointed star with a small one above it, the sparkle commonly
     * used to mark AI features.
     */
    private static function sparkle(GdImage $image, int $x, int $y, int $size, int $color): void
    {
        $star = static function (float $cx, float $cy, float $r) use ($image, $color): void {
            $inner = $r * 0.28;
            $points = [];
            for ($i = 0; $i < 8; $i++) {
                $angle = M_PI / 4 * $i - M_PI / 2;
                $radius = $i % 2 === 0 ? $r : $inner;
                $points[] = (int) round($cx + cos($angle) * $radius);
                $points[] = (int) round($cy + sin($angle) * $radius);
            }
            imagefilledpolygon($image, $points, $color);
        };

        $star($x + $size * 0.42, $y + $size * 0.58, $size * 0.42);
        $star($x + $size * 0.82, $y + $size * 0.2, $size * 0.18);
    }
}
