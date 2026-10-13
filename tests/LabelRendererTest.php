<?php

use Grav\Plugin\AiLabel\LabelRenderer;
use PHPUnit\Framework\TestCase;

class LabelRendererTest extends TestCase
{
    private function png(array $options): string
    {
        $image = LabelRenderer::render($options);
        $this->assertNotNull($image);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function alphaAt(GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    public function testDrawsALabelWithATransparentMargin(): void
    {
        $image = LabelRenderer::render(['text' => 'AI generated', 'margin' => 10, 'background_opacity' => 100, 'radius' => 0]);

        $this->assertInstanceOf(GdImage::class, $image);
        // The margin is fully transparent, the box behind the text is solid.
        $this->assertSame(127, $this->alphaAt($image, 0, 0));
        $this->assertSame(127, $this->alphaAt($image, imagesx($image) - 1, imagesy($image) - 1));
        $this->assertSame(0, $this->alphaAt($image, 11, 11));
        $this->assertGreaterThan(imagesy($image), imagesx($image));
    }

    public function testBackgroundOpacity(): void
    {
        $image = LabelRenderer::render(['text' => 'AI', 'margin' => 0, 'radius' => 0, 'background_opacity' => 50, 'icon' => 'none']);
        $alpha = $this->alphaAt($image, 1, 1);
        $this->assertGreaterThan(50, $alpha);
        $this->assertLessThan(75, $alpha);

        $none = LabelRenderer::render(['text' => 'AI', 'margin' => 0, 'background_opacity' => 0, 'icon' => 'none']);
        $this->assertSame(127, $this->alphaAt($none, 1, 1));
    }

    public function testNothingToDraw(): void
    {
        $this->assertNull(LabelRenderer::render(['text' => '', 'icon' => 'none']));
        $this->assertNotNull(LabelRenderer::render(['text' => '', 'icon' => 'sparkle']));
    }

    public function testSameOptionsGiveTheSameImageAndWordingChangesIt(): void
    {
        $a = $this->png(['text' => 'AI generated']);
        $this->assertSame($a, $this->png(['text' => 'AI generated']));
        $this->assertNotSame($a, $this->png(['text' => 'AI altered']));
        $this->assertNotSame($a, $this->png(['text' => 'AI generated', 'background_color' => '#336699']));
    }

    public function testTextSizeSetsTheLabelSize(): void
    {
        $small = LabelRenderer::render(['text' => 'AI generated', 'font_size' => 12, 'padding' => 0, 'margin' => 0]);
        $large = LabelRenderer::render(['text' => 'AI generated', 'font_size' => 48, 'padding' => 0, 'margin' => 0]);
        $this->assertGreaterThan(imagesx($small) * 2, imagesx($large));
        $this->assertGreaterThan(imagesy($small) * 2, imagesy($large));
    }

    public function testLabelsInDifferentLanguagesAreTheSameHeight(): void
    {
        $heights = array_map(
            static fn ($text) => imagesy(LabelRenderer::render(['text' => $text])),
            ['AI generated', 'KI-generiert', 'aaa', 'נוצר על ידי AI']
        );
        $this->assertCount(1, array_unique($heights));
    }

    public function testLongWordingWraps(): void
    {
        $font = LabelRenderer::fontOrDefault('');
        $long = 'Dieses Bild wurde mit Hilfe künstlicher Intelligenz bearbeitet';
        $lines = LabelRenderer::wrap($long, 18, $font, 14 * 24);
        $this->assertGreaterThan(1, count($lines));
        $this->assertSame($long, implode(' ', $lines));
        $this->assertSame(['AI generated'], LabelRenderer::wrap('AI generated', 18, $font, 14 * 24));

        $one = LabelRenderer::render(['text' => 'AI generated']);
        $wrapped = LabelRenderer::render(['text' => $long]);
        $this->assertGreaterThan(imagesy($one), imagesy($wrapped));
        $this->assertLessThan(imagesy($wrapped) * 6, imagesx($wrapped));
    }

    public function testRightToLeftText(): void
    {
        $this->assertTrue(LabelRenderer::isRtl('נוצר על ידי AI'));
        $this->assertTrue(LabelRenderer::isRtl('«تم» AI'));
        $this->assertFalse(LabelRenderer::isRtl('AI נוצר'));
        $this->assertFalse(LabelRenderer::isRtl('AI generated'));

        // Drawn left to right: "AI" first, then the Hebrew words reversed.
        $this->assertSame('AI ידי לע רצונ', LabelRenderer::visualOrder('נוצר על ידי AI'));
        // Left-to-right text keeps its order; only the Hebrew run is reversed.
        $this->assertSame('AI לע', LabelRenderer::visualOrder('AI על'));
        $this->assertSame('AI generated', LabelRenderer::visualOrder('AI generated'));
    }

    public function testColors(): void
    {
        $this->assertSame([255, 0, 136], LabelRenderer::rgb('#ff0088', [0, 0, 0]));
        $this->assertSame([255, 255, 255], LabelRenderer::rgb('fff', [0, 0, 0]));
        $this->assertSame([1, 2, 3], LabelRenderer::rgb('not a color', [1, 2, 3]));
    }

    public function testRenderToFile(): void
    {
        $file = sys_get_temp_dir() . '/ai-label-test-' . bin2hex(random_bytes(4)) . '/label.png';
        $this->assertTrue(LabelRenderer::renderToFile(['text' => 'AI generated'], $file));
        $this->assertSame('image/png', getimagesize($file)['mime']);
        $this->assertFalse(LabelRenderer::renderToFile(['text' => '', 'icon' => 'none'], $file . '2'));
        unlink($file);
        rmdir(dirname($file));
    }
}
