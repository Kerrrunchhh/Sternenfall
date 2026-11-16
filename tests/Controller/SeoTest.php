<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** robots.txt must not block what the sitemap offers (checked with Google's rule: the longest matching rule wins). */
class SeoTest extends WebTestCase
{
    public function testEverySitemapPageMayBeCrawledTheGameMayNot(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');
        $rules = self::rulesFor('*', (string) $client->getResponse()->getContent());
        $client->request('GET', '/sitemap.xml');
        preg_match_all('~<loc>http://localhost([^<]*)</loc>~', (string) $client->getResponse()->getContent(), $m);

        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $path) {
            self::assertTrue(self::allowed($rules, html_entity_decode($path)), $path.' is in the sitemap but blocked');
        }
        self::assertTrue(self::allowed($rules, '/?lang=en'));
        self::assertFalse(self::allowed($rules, '/overview'), 'the game needs a login');
        self::assertFalse(self::allowed($rules, '/kampfbericht/0123456789abcdef01234567'), 'shared reports expire');
    }

    public function testAdSenseMayLookEverywhere(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertTrue(self::allowed(self::rulesFor('Mediapartners-Google', (string) $client->getResponse()->getContent()), '/overview'));
    }

    /** @return list<array{bool, string}> allow?, pattern – of the group for that user agent */
    private static function rulesFor(string $agent, string $robots): array
    {
        $rules = [];
        $inGroup = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                $inGroup = 0 === strcasecmp(trim($m[1]), $agent);
            } elseif ($inGroup && preg_match('/^(Allow|Disallow):\s*(.*)$/i', $line, $m)) {
                $rules[] = [0 === strcasecmp($m[1], 'Allow'), trim($m[2])];
            }
        }

        return $rules;
    }

    /** @param list<array{bool, string}> $rules */
    private static function allowed(array $rules, string $path): bool
    {
        $best = null;
        foreach ($rules as [$allow, $pattern]) {
            $regex = '~^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '~')).'~';
            if ('' !== $pattern && preg_match($regex, $path) && (null === $best || \strlen($pattern) > \strlen($best[1]) || (\strlen($pattern) === \strlen($best[1]) && $allow))) {
                $best = [$allow, $pattern];
            }
        }

        return $best[0] ?? true;
    }
}
