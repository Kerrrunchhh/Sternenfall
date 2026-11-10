<?php

namespace App\Tests\Game;

use App\Twig\CoordinatesExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CoordinatesLinkTest extends TestCase
{
    public function testCoordinatesBecomeLinksAndTheRestIsEscaped(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => "/galaxy?galaxy={$p['galaxy']}&system={$p['system']}");
        $filter = new CoordinatesExtension($urls, new \Symfony\Component\Translation\IdentityTranslator());

        $html = $filter->link('Kolonie auf [3:195:9] <b>gegründet</b>, Fusion [2:7:4-6].');

        self::assertStringContainsString('<a class="coords" href="/galaxy?galaxy=3&amp;system=195#slot-9" title="Im Universum anzeigen">[3:195:9]</a>', $html);
        self::assertStringContainsString('href="/galaxy?galaxy=2&amp;system=7#slot-4"', $html);
        self::assertStringContainsString('&lt;b&gt;gegründet&lt;/b&gt;', $html, 'player text stays escaped');
    }

    public function testSharedReportLinksBecomeClickableWithCopyButton(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => 'app_battle_report' === $route ? '/kampfbericht/'.$p['hash'] : '/galaxy');
        $filter = new CoordinatesExtension($urls, new \Symfony\Component\Translation\IdentityTranslator());

        $html = $filter->link("Schau: https://sternenfall.example/kampfbericht/382fbad51317459736883b82 und [3:1:2]\nnoch einer /kampfbericht/0123456789abcdef01234567");

        self::assertStringContainsString('<a class="report-link" href="/kampfbericht/382fbad51317459736883b82">Kampfbericht 382fbad5</a>', $html);
        self::assertStringContainsString('data-copy-link="/kampfbericht/0123456789abcdef01234567"', $html);
        self::assertStringContainsString('class="coords"', $html, 'coordinates are still linked');
        self::assertStringNotContainsString('report-link" href="/kampfbericht/zz', $filter->link('/kampfbericht/zz'), 'only real hashes');
    }
}
