<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n — slugs in the language of the URL
 *
 * The language's own spelling of its letters (German ö is oe, Danish ø is oe),
 * then ICU's Latin-ASCII, then one hyphen for every run of anything else. And,
 * as for the engines, the portable branch is forced and compared with ICU over
 * the whole Latin script — a fallback that is nearly right is the bug nobody
 * looks for.
 *
 * Run: php src/Libs/Italix/I18n/tests/SlugTest.php
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

use Italix\I18n\I18nException;
use Italix\I18n\Slug;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix I18n — Slug');

$throws = static function (callable $work): bool {
    try {
        $work();
    } catch (I18nException $e) {
        return true;
    }

    return false;
};

foreach (Slug::has_intl() ? [true, false] : [false] as $use_intl) {
    $of = static fn (string $text, string $locale_c = '', int $max_n = 0): string => Slug::of($text, $locale_c, $max_n, $use_intl);

    // -------------------------------------------------------------------------
    section($use_intl ? 'with ICU' : 'portable (the baked table)');

    test('lower case, one hyphen for every run, none at the ends', $of('  Il seme   sotto la neve!! ') === 'il-seme-sotto-la-neve');
    test('an apostrophe separates, as the old site did: dell-onda', $of("Surf alla ricerca dell'onda perfetta", 'it') === 'surf-alla-ricerca-dell-onda-perfetta');
    test('...a typographic one too', $of('L’estrema sfida', 'it') === 'l-estrema-sfida');
    test('accents go: Città, perché, Ça va', $of('Città perché Ça va') === 'citta-perche-ca-va');
    test('digits stay', $of('Onda 14 — 2010') === 'onda-14-2010');

    test('German writes ö as oe', $of('Grönland', 'de') === 'groenland');
    test('...and ß as ss, Ü as ue', $of('Straße der Übersetzer', 'de-CH') === 'strasse-der-uebersetzer');
    test('in Italian ö is o', $of('Grönland', 'it') === 'gronland');
    test('Danish writes ø as oe, å as aa', $of('Ærø Århus', 'da') === 'aeroe-aarhus');
    test('elsewhere ø is o: Peter Høeg is peter-hoeg', $of('Peter Høeg', 'it') === 'peter-hoeg');
    test('no language: the general rule', $of('Grönland') === 'gronland');
    test('Polish, Czech, Romanian, Icelandic letters', $of('Łódź Dvořák Ştefan Þórr Œuvre') === 'lodz-dvorak-stefan-thorr-oeuvre');
    test('Vietnamese (Latin Extended Additional)', $of('Phở Hà Nội') === 'pho-ha-noi');

    test('& is the language\'s "and"', $of('Libri & Film', 'it') === 'libri-e-film' && $of('Books & Films', 'en') === 'books-and-films'
        && $of('Bücher & Filme', 'de') === 'buecher-und-filme');
    test('...or a hyphen in a language not listed', $of('A & B', 'ja') === 'a-b' && $of('A & B') === 'a-b');

    test('max_n cuts at a hyphen', $of('Il senso di Smilla per la neve', 'it', 20) === 'il-senso-di-smilla');
    test('...or hard, when the last hyphen is too far back', $of('Precipitevolissimevolmente', 'it', 10) === 'precipitev');
    test('...never ending on a hyphen', substr($of('ab cd ef gh', '', 6), -1) !== '-');
    test('nothing left: an empty slug', $of('¡¿ — !?') === '');
}

// -----------------------------------------------------------------------------
section('beyond the Latin script');

if (Slug::has_intl()) {
    test('with ICU: Cyrillic', Slug::of('Москва', 'en') === 'moskva');
    test('...Greek', Slug::of('Θεσσαλονίκη', 'en') === 'thessalonike');
    test('...Han', Slug::of('北京', 'en') === 'bei-jing');
}
test('portable: refused, naming the extension, never an empty slug', $throws(fn () => Slug::of('Москва', 'en', 0, false)));
test('portable: punctuation outside ASCII is not a letter, and goes', Slug::of('«Neve» — “Inverno”', 'it', 0, false) === 'neve-inverno');

// -----------------------------------------------------------------------------
section('the baked table');

if (Slug::has_intl()) {
    test('data/latin-ascii.php is what this ICU would produce', Slug::is_current(__DIR__ . '/../' . Slug::PATH), 'run: ix i18n:bake');

    $table    = require __DIR__ . '/../' . Slug::PATH;
    $mismatch = [];
    foreach ($table as $char => $ascii) {
        if (Slug::of('x' . $char . 'x', '', 0, false) !== Slug::of('x' . $char . 'x', '', 0, true)) {
            $mismatch[] = $char;
        }
    }
    test('every letter of the table: portable === ICU (' . count($table) . ' letters)', $mismatch === [], implode(' ', array_slice($mismatch, 0, 20)));
} else {
    echo "  SKIPPED — no ext-intl to compare the table with.\n";
}

exit(summary());
