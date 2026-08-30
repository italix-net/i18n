<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n — the portable engine answers exactly what ICU answers
 *
 * A fallback that is *nearly* right is worse than no fallback at all. The same
 * catalogue would render one way on a developer's laptop and another on a
 * server, and nobody would think to look: the page is not broken, it is merely
 * wrong in a way that reads as normal.
 *
 * So the assertion here is not "close enough". It is **byte for byte**, over
 * every locale the tables carry, for numbers, money, dates, plural categories
 * and messages.
 *
 * ## Why this suite exists on a machine that *has* ext-intl
 *
 * Because otherwise the portable branch never runs. On every development
 * machine in this project ICU is installed, so without forcing the other engine
 * the entire fallback would be code that has never executed — which is the same
 * as code that does not work. `using_engine('portable')` is in the public API
 * for this reason, not as a curiosity.
 *
 * Run: php src/Libs/Italix/I18n/tests/EngineTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\I18n\Baker;
use Italix\I18n\Formatter;
use Italix\I18n\I18nException;
use Italix\I18n\IcuEngine;
use Italix\I18n\PortableEngine;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix I18n — ICU and the portable engine, side by side');

// -----------------------------------------------------------------------------
section('is there an ICU to compare against?');

if (!IcuEngine::is_available()) {
    // The portable engine is the one that has to work here, and it can still be
    // exercised — it just cannot be *compared*. Saying so beats a green run
    // that proved nothing.
    test('ext-intl is absent — the differential half cannot run here', true,
        'the portable engine is still checked for self-consistency below');

    $portable = new PortableEngine();

    test('the portable engine still answers', $portable->plural_category_code('it', 1) === 'one');
    test('…and still refuses what it will not approximate', !$portable->supports('collation'));

    exit(summary());
}

test('ext-intl is available, so both engines can be run', true);

$icu      = new IcuEngine();
$portable = new PortableEngine();
$locales  = array_merge(Baker::LOCALES, Baker::REGIONS);

test('the tables cover ' . count($locales) . ' locales', count($locales) >= 30);

// -----------------------------------------------------------------------------
section('plural categories — the one thing a catalogue cannot do without');

$wrong  = [];
$tried_n = 0;

foreach (Baker::LOCALES as $locale_c) {
    // 0–250 covers every rule's special cases and crosses the periodic boundary
    // the tables rely on; the decimals are where Danish and German part company.
    foreach (array_merge(range(0, 250), [0.5, 1.5, 2.5, 11.5, 1.1]) as $count) {
        $tried_n++;

        if ($icu->plural_category_code($locale_c, $count) !== $portable->plural_category_code($locale_c, $count)) {
            $wrong[] = "{$locale_c}/{$count}";
        }
    }
}

test("{$tried_n} plural categories agree", $wrong === [], implode(', ', array_slice($wrong, 0, 8)));

// -----------------------------------------------------------------------------
section('numbers, money and dates — byte for byte');

$when = new DateTimeImmutable('2026-08-13 14:35:07', new DateTimeZone('UTC'));

$numbers    = [0, 1, -1, 0.5, 1234.5, -42.75, 1000000, 999.999, 12345.6789, 0.001];
$money      = [['EUR', 1234.5], ['EUR', -42.75], ['EUR', 0.0], ['CHF', 1000.0], ['JPY', 1000.0],
               ['USD', 0.5], ['GBP', 999999.99], ['SEK', 12345.678], ['HUF', 250.0], ['CZK', 1.05]];
$styles     = ['date_short', 'date_medium', 'date_long', 'time_short', 'time_medium',
               'datetime_short', 'datetime_medium'];

$mismatch = [];
$count_n  = 0;

foreach ($locales as $locale_c) {
    foreach ($numbers as $value) {
        $count_n++;
        $a = $icu->format_number($locale_c, (float) $value);
        $b = $portable->format_number($locale_c, (float) $value);

        if ($a !== $b) {
            $mismatch[] = "number {$locale_c} {$value}: [{$a}] vs [{$b}]";
        }
    }

    foreach ($money as [$currency_c, $value]) {
        $count_n++;
        $a = $icu->format_currency($locale_c, (float) $value, $currency_c);
        $b = $portable->format_currency($locale_c, (float) $value, $currency_c);

        if ($a !== $b) {
            $mismatch[] = "money {$locale_c} {$currency_c} {$value}: [{$a}] vs [{$b}]";
        }
    }

    foreach ($styles as $style_c) {
        $count_n++;
        $a = $icu->format_date($locale_c, $when, $style_c, 'UTC');
        $b = $portable->format_date($locale_c, $when, $style_c, 'UTC');

        if ($a !== $b) {
            $mismatch[] = "date {$locale_c} {$style_c}: [{$a}] vs [{$b}]";
        }
    }
}

test("{$count_n} renderings are identical byte for byte",
    $mismatch === [], implode("\n      ", array_slice($mismatch, 0, 10)));

// -----------------------------------------------------------------------------
section('the three that caught real defects, pinned so they stay caught');

// Each of these was a divergence the differential run found, and none would
// have been found by reading either implementation.
test('de-AT groups its numbers with a space and its money with a full stop',
    $icu->format_number('de-AT', 1234567.89) === $portable->format_number('de-AT', 1234567.89)
    && $icu->format_currency('de-AT', 1234.5, 'EUR') === $portable->format_currency('de-AT', 1234.5, 'EUR'),
    'reading GROUPING_SEPARATOR_SYMBOL off a currency formatter gives the wrong one of the two');

test('Bulgarian does not group its money, though it groups its numbers',
    $icu->format_currency('bg', 1234.5, 'EUR') === $portable->format_currency('bg', 1234.5, 'EUR')
    && strpos($portable->format_currency('bg', 1234.5, 'EUR'), '1234') !== false);

test('an alphabetic currency symbol gets the space CLDR inserts',
    $icu->format_currency('en', 1000.0, 'CHF') === $portable->format_currency('en', 1000.0, 'CHF')
    && $icu->format_currency('en', 1000.0, 'EUR') === $portable->format_currency('en', 1000.0, 'EUR'),
    'CHF ends in a letter and € does not, and the pattern says nothing about it');

test('Polish writes the month of a date in the genitive',
    $icu->format_date('pl', $when, 'date_long', 'UTC') === $portable->format_date('pl', $when, 'date_long', 'UTC'),
    'MMMM and LLLL are different words in Polish, Czech and Greek');

test('Greek writes μ.μ., not PM',
    $icu->format_date('el', $when, 'time_short', 'UTC') === $portable->format_date('el', $when, 'time_short', 'UTC'));

// -----------------------------------------------------------------------------
section('messages, including the plural and select forms a catalogue uses');

$messages = [
    ['Ciao {name}', ['name' => 'Anna']],
    ['{n, plural, one{# parte} other{# parti}}', ['n' => 1]],
    ['{n, plural, one{# parte} other{# parti}}', ['n' => 5]],
    ['{n, plural, one{# parte} other{# parti}}', ['n' => 1234]],
    ['{n, plural, =0{nessuna} one{# parte} other{# parti}}', ['n' => 0]],
    ['{g, select, male{Egregio} female{Gentile} other{Spett.le}} {name}', ['g' => 'female', 'name' => 'Rossi']],
    ['{g, select, male{Egregio} female{Gentile} other{Spett.le}} {name}', ['g' => 'zzz', 'name' => 'Rossi']],
];

$wrong   = [];
$count_n = 0;

foreach (['it', 'en', 'pl', 'cs', 'fr', 'ga', 'lv', 'sl', 'ro', 'lt', 'mt'] as $locale_c) {
    foreach ($messages as [$message_c, $params]) {
        $count_n++;
        $a = $icu->format_message($locale_c, $message_c, $params);
        $b = $portable->format_message($locale_c, $message_c, $params);

        if ($a !== $b) {
            $wrong[] = "{$locale_c} \"{$message_c}\": [{$a}] vs [{$b}]";
        }
    }
}

test("{$count_n} messages render identically", $wrong === [], implode('; ', array_slice($wrong, 0, 5)));

// -----------------------------------------------------------------------------
section('and it refuses, loudly, what it will not approximate');

$refuses = static function (callable $call): bool {
    try {
        $call();

        return false;
    } catch (I18nException $e) {
        return true;
    }
};

// The apostrophe is the sharpest of these. To ICU `'` is a quoting character,
// so a sub-parser that treated it even slightly differently would render the
// same catalogue two ways depending on which engine ran.
test('a message containing an apostrophe is refused, not guessed at',
    $refuses(static fn () => $portable->format_message('it', "{n, plural, one{l'utente} other{gli utenti}}", ['n' => 1])),
    'this is the worst failure available here: same catalogue, two outputs');

test('nested plural/select is refused',
    $refuses(static fn () => $portable->format_message(
        'it',
        '{a, plural, other{{b, select, x{X} other{Y}}}}',
        ['a' => 2, 'b' => 'x']
    )));

test('a locale the tables do not carry is refused, not approximated',
    $refuses(static fn () => $portable->format_number('th-TH', 1234.5)));

foreach (['collation', 'spellout', 'display_names', 'relative', 'traditional_calendars'] as $capability_c) {
    test("supports('{$capability_c}') is false, and says so in advance",
        !$portable->supports($capability_c) && $icu->supports($capability_c));
}

// -----------------------------------------------------------------------------
section('the seam is visible and forcible from the Formatter');

$formatter = new Formatter('it-IT', 'Europe/Rome');

test('engine_code() names who is answering', in_array($formatter->engine_code(), ['icu', 'portable'], true));
test('with ICU installed, ICU is chosen', $formatter->engine_code() === 'icu');
test('…and the other one can be forced', $formatter->using_engine('portable')->engine_code() === 'portable');
test('in() keeps the engine', $formatter->using_engine('portable')->in('de-DE')->engine_code() === 'portable');

test('the same Formatter call agrees across engines',
    $formatter->number(1234.5) === $formatter->using_engine('portable')->number(1234.5)
    && $formatter->currency(1234.5, 'EUR') === $formatter->using_engine('portable')->currency(1234.5, 'EUR'));

test('a capability the portable engine lacks throws from the Formatter too',
    $refuses(static fn () => (new Formatter('it-IT', 'UTC', 'portable'))->compare('a', 'b')));

test('asking for an engine that does not exist is an error, not a silent default',
    $refuses(static fn () => new Formatter('it-IT', 'UTC', 'nonsense')));

// -----------------------------------------------------------------------------
section('the baked tables still match this ICU');

// The tables are ICU's answers frozen at a moment. ICU is upgraded with the
// operating system, and CLDR changes: a currency gains a symbol, a locale
// changes its short date. When that happens the committed file becomes a
// *stale* answer, and a stale answer is the one failure mode this whole design
// has — silently formatting last year's way.
//
// `ix i18n:bake --check` is the same assertion for a build pipeline.
test('data/cldr.php is what this ICU would produce',
    Baker::is_current(__DIR__ . '/../' . Baker::PATH),
    'ICU has moved since the tables were baked — run: ix i18n:bake');

exit(summary());
