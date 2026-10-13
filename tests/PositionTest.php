<?php

use Grav\Plugin\AiLabelPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PositionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Grav\Common\Plugin::class)) {
            $this->markTestSkipped('Needs a Grav install: set GRAV_ROOT.');
        }
        require_once dirname(__DIR__) . '/ai-label.php';
    }

    public static function positions(): array
    {
        return [
            ['bottom-right', 'bottom-right'],
            ['bottom right', 'bottom-right'],
            ['Right_Bottom', 'bottom-right'],
            ['top left', 'top-left'],
            ['top', 'top-center'],
            ['left', 'center-left'],
            ['center', 'center-center'],
            ['', 'bottom-right'],
        ];
    }

    #[DataProvider('positions')]
    public function testNormalizePosition(string $input, string $expected): void
    {
        $this->assertSame($expected, AiLabelPlugin::normalizePosition($input));
    }
}
