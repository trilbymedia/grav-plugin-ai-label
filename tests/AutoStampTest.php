<?php

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Medium\ImageMedium;
use Grav\Plugin\AiLabelPlugin;
use PHPUnit\Framework\TestCase;
use RocketTheme\Toolbox\Event\Event;

/**
 * The guard between auto_stamp and the ai_label filter: an image is labelled
 * once, whichever of the two gets to it, and again after every reset.
 */
class AutoStampTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (class_exists(\Grav\Common\Plugin::class)) {
            require_once dirname(__DIR__) . '/ai-label.php';
            // Grav sets its error handlers the first time, so do it outside the tests.
            Grav::instance();
        }
    }

    protected function setUp(): void
    {
        if (!class_exists(\Grav\Common\Plugin::class)) {
            $this->markTestSkipped('Needs a Grav install: set GRAV_ROOT.');
        }
    }

    private function plugin(bool $autoStamp): AiLabelPlugin
    {
        $config = new Config([
            'plugins' => ['ai-label' => ['auto_stamp' => $autoStamp, 'position' => 'bottom-right', 'size' => 30]],
            'system' => ['images' => ['watermark' => ['watermark_all' => false]]],
        ]);

        // The label file itself is LabelRenderer's job, tested elsewhere.
        return new class('ai-label', Grav::instance(), $config) extends AiLabelPlugin {
            public function labelFile(string $status): ?string
            {
                return 'cache://images/ai-label/' . $status . '.png';
            }
        };
    }

    /**
     * An image medium that records the labels queued on it.
     */
    private function medium(?string $status, ?array &$calls): ImageMedium
    {
        $calls = [];
        $medium = $this->getMockBuilder(ImageMedium::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'watermark'])
            ->getMock();
        $medium->method('get')->willReturnCallback(
            static fn ($key) => $key === AiLabelPlugin::META_KEY ? $status : null
        );
        $medium->method('watermark')->willReturnCallback(
            static function (...$args) use (&$calls, $medium) {
                $calls[] = $args;

                return $medium;
            }
        );

        return $medium;
    }

    private function reset(AiLabelPlugin $plugin, ImageMedium $medium): void
    {
        $plugin->onImageMediumReset(new Event(['medium' => $medium]));
    }

    public function testAutoStampLabelsAFlaggedImageOnReset(): void
    {
        $plugin = $this->plugin(true);
        $medium = $this->medium('generated', $calls);

        $this->reset($plugin, $medium);

        $this->assertSame([['cache://images/ai-label/generated.png', 'bottom-right', 30]], $calls);
    }

    public function testTheFilterDoesNotLabelAnAutoStampedImageAgain(): void
    {
        $plugin = $this->plugin(true);
        $medium = $this->medium('altered', $calls);

        $this->reset($plugin, $medium);
        $this->assertSame($medium, $plugin->stamp($medium, 'top-left', 20));
        $plugin->stamp($medium);

        $this->assertCount(1, $calls);
        $this->assertSame('bottom-right', $calls[0][1]);
    }

    public function testEveryResetLabelsTheImageOnceMore(): void
    {
        // A render resets the image, which drops the label, so the next
        // reset queues it again and the filter still stays out of the way.
        $plugin = $this->plugin(true);
        $medium = $this->medium('generated', $calls);

        $this->reset($plugin, $medium);
        $plugin->stamp($medium);
        $this->reset($plugin, $medium);
        $plugin->stamp($medium);

        $this->assertCount(2, $calls);
    }

    public function testUnflaggedImagesAreNeverLabelled(): void
    {
        $plugin = $this->plugin(true);
        foreach ([null, '', 'none', 'NONE', 'something else'] as $status) {
            $medium = $this->medium($status, $calls);
            $this->reset($plugin, $medium);
            $plugin->stamp($medium);
            $this->assertSame([], $calls, var_export($status, true));
        }
    }

    public function testTheFilterStillLabelsImagesAutoStampHasNotSeen(): void
    {
        // With auto_stamp on but no reset event (a core older than 2.2.8),
        // the filter does the labelling as before.
        $plugin = $this->plugin(true);
        $medium = $this->medium('generated', $calls);

        $plugin->stamp($medium, 'top-left', 20);

        $this->assertSame([['cache://images/ai-label/generated.png', 'top-left', 20]], $calls);
    }

    public function testWithoutAutoStampTheFilterLabelsAfterEveryReset(): void
    {
        // auto_stamp off: nothing listens to resets, so nothing may stop the
        // filter from labelling the same image on each render.
        $plugin = $this->plugin(false);
        $medium = $this->medium('generated', $calls);

        $plugin->stamp($medium);
        $plugin->stamp($medium);

        $this->assertCount(2, $calls);
    }

    public function testOtherImagesAreNotAffectedByTheGuard(): void
    {
        $plugin = $this->plugin(true);
        $auto = $this->medium('generated', $autoCalls);
        $other = $this->medium('generated', $otherCalls);

        $this->reset($plugin, $auto);
        $plugin->stamp($other, 'top-left');

        $this->assertCount(1, $autoCalls);
        $this->assertCount(1, $otherCalls);
    }
}
