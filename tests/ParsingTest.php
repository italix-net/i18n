<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n — reading localised input back, and naming things
 *
 * Covers the additions of 1.1.0: parsing, display names, and the missing-parameter
 * report that `problems()` used to stay silent about.
 *
 * Everything here is measured against ICU on the running build. Two of the
 * behaviours under test are ICU's own traps rather than ours:
 *
 *   - `NumberFormatter::parse()` stops at the first character it cannot use and
 *     **reports success**, so "12,5x" becomes 12.5 with no error;
 *   - the parse position counts **characters, not bytes**, so a byte-length
 *     comparison rejects every correctly typed Swiss amount.
 *
 * Run: php src/Libs/Italix/I18n/tests/ParsingTest.php
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

use Italix\I18n\Catalog;
use Italix\I18n\Formatter;
use Italix\I18n\Locale;
use Italix\I18n\I18nException;
use Italix\I18n\Parsed;
use Italix\I18n\Translator;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix I18n — parsing and names');

$it = new Formatter('it-IT', 'Europe/Rome');
$en = new Formatter('en-US', 'UTC');
$ch = new Formatter('de-CH', 'Europe/Zurich');
$fr = new Formatter('fr-FR', 'Europe/Paris');

// -----------------------------------------------------------------------------
section('the reason this exists: PHP reads Italian numbers wrong');

test('(float) on an Italian amount loses three orders of magnitude', (float) '1.234,50' === 1.234,
    'one thousand two hundred becomes one and a bit — silently');

$parsed = $it->parse_number('1.234,50');

test('parse_number reads it correctly', $parsed->is_ok() && $parsed->value() === 1234.5);
test('a successful parse has no error code', $parsed->error_code() === null);
test('the input is kept for the error message that will not be needed', $parsed->input() === '1.234,50');

// -----------------------------------------------------------------------------
section('ICU declares success on input it only partly read');

// Left to ICU this is 12.5 with U_ZERO_ERROR. That is the whole point.
$garbage = $it->parse_number('12,5x');

test('a number followed by rubbish is refused', !$garbage->is_ok());
test('…and says the input continued past the value', $garbage->error_code() === Parsed::TRAILING);
test('…and yields no value at all', $garbage->value() === null);

test('rubbish in front is refused too', !$it->parse_number('x12,5')->is_ok());
test('a trailing space is still trailing', !$it->parse_number('12,5 ')->is_ok());

$nothing = $it->parse_number('abc');
test('input with no number is unreadable', $nothing->error_code() === Parsed::UNREADABLE);

test('empty input is named, not lumped in with garbage', $it->parse_number('')->error_code() === Parsed::EMPTY_INPUT);
test('whitespace-only input is empty', $it->parse_number("  \t ")->error_code() === Parsed::EMPTY_INPUT);

// -----------------------------------------------------------------------------
section('the position counts characters, not bytes');

// `1’234.50` is 8 characters and 10 bytes: the apostrophe is U+2019. Comparing
// the parse position against strlen() would reject this perfectly good input.
$swiss = "1\u{2019}234.50";

test('the Swiss separator really is multi-byte', strlen($swiss) === 10 && mb_strlen($swiss) === 8);
test('a Swiss amount parses', $ch->parse_number($swiss)->is_ok());
test('…to the right value', $ch->parse_number($swiss)->value() === 1234.5);

// French groups with a narrow no-break space, also multi-byte.
$french = $fr->number(1234.5);
test('a French amount round-trips', $fr->parse_number($french)->value() === 1234.5, "[{$french}]");

// -----------------------------------------------------------------------------
section('every locale round-trips its own output');

$failures = [];

foreach ([['it-IT', 'EUR'], ['en-US', 'USD'], ['de-CH', 'CHF'], ['fr-FR', 'EUR'], ['de-DE', 'EUR']] as [$locale_c, $currency_c]) {
    $f       = new Formatter($locale_c);
    $written = $f->number(9876.54);
    $read    = $f->parse_number($written);

    if (!$read->is_ok() || $read->value() !== 9876.54) {
        $failures[] = "{$locale_c} number [{$written}]";
    }

    $money      = $f->currency(9876.54, $currency_c);
    $read_money = $f->parse_currency($money);

    if (!$read_money->is_ok() || $read_money->value() !== 9876.54) {
        $failures[] = "{$locale_c} currency [{$money}]";
    }

    if ($read_money->currency_code() !== $currency_c) {
        $failures[] = "{$locale_c} currency code";
    }
}

test('numbers and amounts survive the round trip in five locales', $failures === [],
    implode('; ', $failures));

// -----------------------------------------------------------------------------
section('currency parsing reports the currency it recognised');

$money = $it->parse_currency($it->currency(1234.5, 'EUR'));

test('the amount comes back', $money->value() === 1234.5);
test('…and so does the ISO code', $money->currency_code() === 'EUR');
test('a plain number is not an amount', !$it->parse_currency('1.234,50')->is_ok());
test('empty input is empty here too', $it->parse_currency('  ')->error_code() === Parsed::EMPTY_INPUT);

// -----------------------------------------------------------------------------
section('integers refuse to truncate');

test('a whole number reads as int', $it->parse_integer('1.234')->value() === 1234);
test('…and is really an int, not a float', is_int($it->parse_integer('42')->value()));
test('a fractional value is refused rather than cut', !$it->parse_integer('2,7')->is_ok(),
    'deciding that 2,7 means 2 is the caller’s business, not the library’s');
test('a negative whole number is fine', $it->parse_integer('-5')->value() === -5);

// -----------------------------------------------------------------------------
section('dates: written and read back by the same locale');

// Built in the formatter's own zone on purpose: `new DateTimeImmutable('2026-08-12')`
// means midnight *where the server happens to be*, and rendering that in UTC
// moves it to the 11th. The shift is correct behaviour; asserting against the
// naive value is what would be wrong.
$noon_rome = new DateTimeImmutable('2026-08-12 12:00', new DateTimeZone('Europe/Rome'));
$noon_utc  = new DateTimeImmutable('2026-08-12 12:00', new DateTimeZone('UTC'));

$written = $it->date($noon_rome, Formatter::SHORT);
$read    = $it->parse_date($written);

test('an Italian short date round-trips', $read->is_ok(), "[{$written}]");
test('…to the same day', $read->value()->format('Y-m-d') === '2026-08-12');
test('the result carries the formatter’s timezone',
    $read->value()->getTimezone()->getName() === 'Europe/Rome');

$us_written = $en->date($noon_utc, Formatter::SHORT);
$us         = $en->parse_date($us_written);
test('an American short date round-trips too',
    $us->is_ok() && $us->value()->format('Y-m-d') === '2026-08-12', "[{$us_written}]");

// The conversion itself, stated rather than assumed: one minute past midnight in
// Rome is still the previous day in London.
test('a formatter renders in its own zone, not the caller’s',
    (new Formatter('en-GB', 'Europe/London'))->date(
        new DateTimeImmutable('2026-08-12 00:30', new DateTimeZone('Europe/Rome')),
        Formatter::SHORT
    ) === '11/08/2026');

// 12/08 and 08/12 are the same characters and different days.
$ambiguous = $it->parse_date('08/12/2026');
test('an Italian reading of 08/12 is the 8th of December',
    $ambiguous->is_ok() && $ambiguous->value()->format('Y-m-d') === '2026-12-08');

test('a date that does not exist is refused, not rolled over',
    !$it->parse_date('31/02/2026')->is_ok(),
    'lenient parsing would have answered the 3rd of March');

test('nonsense is unreadable', $it->parse_date('domani')->error_code() === Parsed::UNREADABLE);
test('a date with something after it is refused', !$it->parse_date('12/08/2026 ciao')->is_ok());
test('an unknown style is a programming error, not a parse failure', (static function () use ($it): bool {
    try {
        $it->parse_date('12/08/2026', 'enormous');

        return false;
    } catch (I18nException $e) {
        return true;
    }
})());

// -----------------------------------------------------------------------------
section('names come from ICU, so no table has to be maintained');

test('a bare region code is understood', $it->region_name('DE') === 'Germania');
test('…in the formatter’s own language', $en->region_name('DE') === 'Germany');
test('a full locale works as well', $it->region_name('de-DE') === 'Germania');
test('an unknown region degrades to the code rather than to an empty string',
    $it->region_name('QQ') === 'QQ',
    'ICU answers "" for a bare code — the padding to und-QQ is what makes this honest');

test('languages are named too', $it->language_name('de') === 'tedesco');
test('…and in English', $en->language_name('de') === 'German');

test('currencies have long names', $it->currency_name('EUR') === 'euro');
test('…that differ by locale', (new Formatter('de-DE'))->currency_name('EUR') === 'Euro');
test('…and are not just the symbol', $it->currency_name('CHF') === 'franco svizzero');
test('a malformed currency code is refused', (static function () use ($it): bool {
    try {
        $it->currency_name('euro');

        return false;
    } catch (I18nException $e) {
        return true;
    }
})());

// -----------------------------------------------------------------------------
section('problems() no longer stays quiet about a missing parameter');

$t = new Translator([new Catalog('it', [
    'ok'    => '{n, plural, =0{nessuna parte} one{# parte} other{# parti}}',
    'wrong' => '{count, plural, one{# parte} other{# parti}}',
    'named' => 'Ciao {name}, hai {items} cose',
    'plain' => 'nessun segnaposto qui',
])], 'it');

test('a correct plural still works', $t->choice('ok', 3) === '3 parti');
test('…and reports nothing', $t->problems() === []);

$out = $t->choice('wrong', 3);

test('a message naming an argument nobody passed leaves the placeholder', $out === '{count}');
test('…and NOW it is recorded', array_key_exists('wrong', $t->problems()));

// `?? ''` so that a regression *fails* here instead of crashing the suite on a
// missing key: a red line is readable, a fatal is not.
test('…naming the parameter', strpos($t->problems()['wrong'] ?? '', 'count') !== false);

$t->forget_problems();

$t->get('named', ['name' => 'Anna']);

test('a partially filled message is reported', array_key_exists('named', $t->problems()));
test('…naming only what was missing', strpos($t->problems()['named'] ?? '', 'items') !== false);
test('…and not what was supplied', strpos($t->problems()['named'] ?? '', 'name,') === false);

$t->forget_problems();

$t->get('named', ['name' => 'Anna', 'items' => 3]);
$t->get('plain');
$t->choice('ok', 1);

test('nothing is reported when everything is supplied', $t->problems() === []);

// -----------------------------------------------------------------------------
section('sorting: PHP orders by byte, languages do not');

// Å U+00C5, Ä U+00C4, Ö U+00D6 — accented capitals, all beyond ASCII.
$names = ['Zani', json_decode('"\u00d6zdemir"'), json_decode('"\u00c5berg"'), 'Ortiz', json_decode('"\u00c4rling"')];

$by_byte = $names;
sort($by_byte);

test('PHP dumps every accented name after Z',
    $by_byte === ['Ortiz', 'Zani', json_decode('"\u00c4rling"'), json_decode('"\u00c5berg"'), json_decode('"\u00d6zdemir"')],
    implode(' ', $by_byte));

$italian = $it->sorted($names);
$swedish = (new Formatter('sv-SE'))->sorted($names);

test('Italian folds the accents into their base letters',
    $italian === [json_decode('"\u00c5berg"'), json_decode('"\u00c4rling"'), 'Ortiz', json_decode('"\u00d6zdemir"'), 'Zani'],
    implode(' ', $italian));

test('Swedish puts them AFTER Z, and is equally right',
    $swedish === ['Ortiz', 'Zani', json_decode('"\u00c5berg"'), json_decode('"\u00c4rling"'), json_decode('"\u00d6zdemir"')],
    implode(' ', $swedish));

test('the two languages genuinely disagree', $italian !== $swedish,
    'no amount of accent-stripping produces both answers');

test('sorted() returns a copy and leaves the argument alone', $names[0] === 'Zani');
test('…renumbered from zero', array_keys($it->sorted($names)) === [0, 1, 2, 3, 4]);

test('compare() follows the usort contract',
    $it->compare('a', 'b') < 0 && $it->compare('b', 'a') > 0 && $it->compare('a', 'a') === 0);

test('case is not what decides the order', $it->compare('anna', 'Bruno') < 0,
    'byte order would put every capital before every lower-case letter');

// Rows keyed by id: the caller still needs to find them afterwards.
$rows = [
    7  => ['surname' => json_decode('"\u00d6zdemir"')],
    3  => ['surname' => 'Ortiz'],
    11 => ['surname' => json_decode('"\u00c5berg"')],
];

$sorted_rows = $it->sorted_by($rows, 'surname');

test('rows sort by one of their fields', array_keys($sorted_rows) === [11, 3, 7]);
test('…keeping their keys', $sorted_rows[3]['surname'] === 'Ortiz');

$by_callable = $it->sorted_by($rows, static fn (array $row): string => $row['surname']);
test('a callable key works too', array_keys($by_callable) === [11, 3, 7]);

test('a missing field sorts as empty rather than throwing',
    count($it->sorted_by($rows + [99 => ['other' => 'x']], 'surname')) === 4);

// -----------------------------------------------------------------------------
section('direction: a property of the script, not of the language');

test('Italian is left to right', !Locale::is_rtl('it') && Locale::direction_code('it') === 'ltr');
test('Arabic, Hebrew, Persian, Urdu are right to left',
    Locale::is_rtl('ar') && Locale::is_rtl('he') && Locale::is_rtl('fa') && Locale::is_rtl('ur'));

// The case a language table alone gets wrong.
test('Azerbaijani in Arabic script is RTL', Locale::is_rtl('az-Arab'));
test('…and the SAME language in Latin script is not', !Locale::is_rtl('az-Latn'),
    'direction belongs to the script; a per-language answer would be wrong for both');

test('a stated script beats the language list', !Locale::is_rtl('ar-Latn'));
test('the formatter answers for its own locale',
    (new Formatter('he-IL'))->direction_code() === 'rtl' && $it->direction_code() === 'ltr');

// -----------------------------------------------------------------------------
section('BCP-47 extensions survive normalisation');

// `ja-JP-u-ca-japanese` used to come back as `ja-JP-u-CA-japanese`: `ca` read as
// a region, naming Canada in a request for the Japanese calendar.
test('a Unicode extension is left alone', Locale::normalise('ja-JP-u-ca-japanese') === 'ja-JP-u-ca-japanese');
test('…and so is a private-use one', Locale::normalise('en-us-x-priv') === 'en-US-x-priv');
test('an ordinary region is still upper-cased', Locale::normalise('it-it') === 'it-IT');
test('an ordinary script is still title-cased', Locale::normalise('az-arab') === 'az-Arab');

// -----------------------------------------------------------------------------
section('money as integer minor units');

test('cents become an amount', $it->currency_minor(123450, 'EUR') === $it->currency(1234.5, 'EUR'));
test('a small amount keeps its leading zero', $it->currency_minor(5, 'EUR') === $it->currency(0.05, 'EUR'));
test('zero is zero', $it->currency_minor(0, 'EUR') === $it->currency(0.0, 'EUR'));
test('a negative under one unit keeps its sign',
    strpos($it->currency_minor(-50, 'EUR'), '-') !== false, $it->currency_minor(-50, 'EUR'));

// The exponent must come from the currency, not from the locale. Reading it off
// a plain it-IT formatter gives EUR's 2, and 1000 yen printed as "10 JPY".
test('a currency with no minor unit is not divided',
    $it->currency_minor(1000, 'JPY') === $it->currency(1000.0, 'JPY'),
    $it->currency_minor(1000, 'JPY'));
test('a currency with three of them is divided by a thousand',
    $it->currency_minor(1234, 'TND') === $it->currency(1.234, 'TND'),
    $it->currency_minor(1234, 'TND'));

// -----------------------------------------------------------------------------
section('non-Gregorian calendars, and the argument that switches them on');

$noon = new DateTimeImmutable('2026-08-12 12:00', new DateTimeZone('UTC'));

test('Thailand counts Buddhist years',
    strpos((new Formatter('th-TH', 'UTC'))->date_traditional($noon), '2569') !== false);
test('Iran counts Persian ones',
    strpos((new Formatter('fa-IR', 'UTC'))->date_traditional($noon), '۱۴۰۵') !== false);

// Stated because the opposite is easy to assume: CLDR records Gregorian as
// Japan's own calendar, and the imperial era has to be asked for.
test('Japan is Gregorian unless the era is requested',
    strpos((new Formatter('ja-JP', 'UTC'))->date_traditional($noon), '2026') !== false);
test('…and the era arrives when it is',
    strpos((new Formatter('ja-JP-u-ca-japanese', 'UTC'))->date_traditional($noon), '令和8') !== false);

test('a locale with no other calendar is unchanged',
    (new Formatter('it-IT', 'UTC'))->date_traditional($noon) === (new Formatter('it-IT', 'UTC'))->date($noon, Formatter::LONG));

// -----------------------------------------------------------------------------
section('pseudo-locale: defacing our own text to find what was never translated');

$catalog = new Catalog('it', [
    'save'  => 'Salva le modifiche',
    'hi'    => 'Ciao {name}!',
    'count' => '{n, plural, one{# parte} other{# parti}}',
]);

$fake = $catalog->pseudo();

test('it is a different locale', $fake->locale_code() === 'en-XA' && $catalog->locale_code() === 'it');
test('the original is untouched', $catalog->get('save') === 'Salva le modifiche');

$saved = $fake->get('save');

test('every message is fenced', strpos($saved, '⟦') === 0 && substr($saved, -3) === '⟧');
test('letters are accented, so untranslated text stands out', strpos($saved, 'Šàľvà') !== false, $saved);
test('and it is padded, to expose layouts that assume English lengths',
    mb_strlen($saved) > mb_strlen('Salva le modifiche') * 1.25, $saved);

// The part that makes it usable: a defaced placeholder would break the message
// it is meant to test.
test('a placeholder is left intact', strpos($fake->get('hi'), '{name}') !== false, $fake->get('hi'));
test('an ICU plural construct is left intact',
    strpos($fake->get('count'), '{n, plural, one{# parte} other{# parti}}') !== false, $fake->get('count'));

exit(summary());
