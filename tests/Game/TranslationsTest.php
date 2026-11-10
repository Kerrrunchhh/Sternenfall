<?php

namespace App\Tests\Game;

use App\Game\GameMailer;
use App\I18n\JsMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The English catalog: every enum text has an entry, every message keeps its placeholders
 * and is valid ICU, so nothing shows up in German or breaks when formatted.
 */
final class TranslationsTest extends TestCase
{
    private const string CATALOG = __DIR__.'/../../translations/messages+intl-icu.en.yaml';

    /** Texts that are spelled the same in English. */
    private const array SAME_IN_ENGLISH = ['Bunker', 'Transport', 'Radar', 'Bastion', 'Fusionator', 'Inferno', 'Leviathan', 'Monarch'];

    /** @return array<string, string> */
    private static function catalog(): array
    {
        return Yaml::parseFile(self::CATALOG);
    }

    /** @return iterable<string, array{string}> */
    public static function enumTexts(): iterable
    {
        foreach (glob(__DIR__.'/../../src/Game/*.php') as $file) {
            $class = 'App\\Game\\'.basename($file, '.php');
            if (!enum_exists($class)) {
                continue;
            }
            foreach ($class::cases() as $case) {
                foreach (['label', 'effect', 'description', 'pluralLabel'] as $method) {
                    if (method_exists($case, $method) && \is_string($text = $case->$method()) && '' !== $text) {
                        yield $class.'::'.$case->name.'->'.$method => [$text];
                    }
                }
                if (method_exists($case, 'traits')) {
                    foreach ($case->traits() as $i => $trait) {
                        yield $class.'::'.$case->name.'->traits['.$i.']' => [$trait];
                    }
                }
            }
        }
        foreach (GameMailer::TOPICS as $topic => $label) {
            yield 'mail topic '.$topic => [$label];
        }
        foreach (JsMessages::all() as $i => $message) {
            yield 'js '.$i => [$message->getMessage()];
        }
    }

    #[DataProvider('enumTexts')]
    public function testEveryGameTextHasAnEnglishTranslation(string $german): void
    {
        $catalog = self::catalog();
        self::assertArrayHasKey($german, $catalog, 'missing in translations/messages+intl-icu.en.yaml');
        if (!\in_array($german, self::SAME_IN_ENGLISH, true)) {
            self::assertNotSame($german, $catalog[$german], 'still German in the English catalog');
        }
    }

    public function testTranslationsKeepPlaceholdersAndAreValidIcu(): void
    {
        foreach (self::catalog() as $german => $english) {
            preg_match_all('/\{(\w+)\}/', (string) $german, $a);
            preg_match_all('/\{(\w+)\}/', (string) $english, $b);
            $expected = array_values(array_unique($a[1]));
            $actual = array_values(array_unique($b[1]));
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, 'placeholders of: '.$german);

            $formatter = new \MessageFormatter('en', (string) $english);
            self::assertNotFalse($formatter->format(array_fill_keys($actual, 'x')), 'ICU format of: '.$english);
        }
    }
}
