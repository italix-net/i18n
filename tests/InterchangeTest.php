<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n — merge, and the formats translators actually use
 *
 * The idea worth taking from thirty years of gettext is not the file format: it
 * is **msgmerge**. Without a merge, a translation file has two futures and both
 * are bad — regenerate and lose the work, or hand-patch and drift.
 *
 * The behaviour these assertions exist to protect is the third row of that
 * table: when the original is reworded, the translation is **kept and flagged**,
 * not dropped and not silently retained.
 *
 * Run: php src/Libs/Italix/I18n/tests/InterchangeTest.php
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
use Italix\I18n\Merge;
use Italix\I18n\Po;
use Italix\I18n\TranslationUnit;
use Italix\I18n\Xliff;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix I18n — merge and interchange');

$translated = static fn (string $k, string $s, string $t): TranslationUnit
    => new TranslationUnit($k, $s, $t, TranslationUnit::TRANSLATED);

// -----------------------------------------------------------------------------
section('the four cases a merge has to get right');

$source = ['a' => 'Salva', 'b' => 'Annulla', 'c' => 'Nuovo'];

$existing = [
    'a' => $translated('a', 'Salva', 'Speichern'),
    'b' => $translated('b', 'Annulla adesso', 'Jetzt abbrechen'),   // source has since changed
    'z' => $translated('z', 'Vecchio', 'Alt'),                      // source no longer has it
];

$merged = Merge::reconcile($source, $existing);

test('an unchanged message is left alone',
    $merged['a']->target() === 'Speichern' && $merged['a']->state_code() === TranslationUnit::TRANSLATED);

test('a new key arrives empty and untranslated',
    $merged['c']->target() === '' && $merged['c']->state_code() === TranslationUnit::INITIAL);

// The row this whole file exists for.
test('A REWORDED SOURCE KEEPS ITS TRANSLATION', $merged['b']->target() === 'Jetzt abbrechen',
    'regenerating would have thrown away work somebody was paid for');
test('…and asks for a look', $merged['b']->state_code() === TranslationUnit::NEEDS_REVIEW);
test('…carrying the new source', $merged['b']->source() === 'Annulla');
test('…and remembering the old one', in_array('was: Annulla adesso', $merged['b']->notes(), true),
    'a reviewer needs to see what moved');

test('a retired key is kept, not deleted', isset($merged['z']) && $merged['z']->is_obsolete());
test('…with its translation intact', $merged['z']->target() === 'Alt',
    'undoing the change next week should not cost a translation');

// -----------------------------------------------------------------------------
section('a key that comes back is alive again, translation and all');

$revived = Merge::reconcile(['z' => 'Vecchio'], $merged);

test('the obsolete flag is lifted', !$revived['z']->is_obsolete());
test('…and the old translation is there', $revived['z']->target() === 'Alt');
test('…without asking for a review, since the source is the same',
    $revived['z']->state_code() === TranslationUnit::TRANSLATED);

// -----------------------------------------------------------------------------
section('an untranslated message whose source moves stays untranslated, not "review"');

$never = Merge::reconcile(
    ['x' => 'Nuovo testo'],
    ['x' => new TranslationUnit('x', 'Vecchio testo', '', TranslationUnit::INITIAL)]
);

test('nothing to review when nothing was translated', $never['x']->state_code() === TranslationUnit::INITIAL,
    'flagging an empty translation for review would be noise in the only list that matters');

// -----------------------------------------------------------------------------
section('what reaches the runtime catalogue');

$catalog = Merge::to_catalog($merged);

test('a current translation ships', ($catalog['a'] ?? null) === 'Speichern');
test('AN OUTDATED ONE DOES NOT', !array_key_exists('b', $catalog),
    'the fallback chain answers, instead of a page showing a sentence that no longer matches its original');
test('an untranslated one does not', !array_key_exists('c', $catalog));
test('a retired one does not', !array_key_exists('z', $catalog));

$reviewed = Merge::to_catalog(['r' => new TranslationUnit('r', 's', 't', TranslationUnit::REVIEWED)]);
test('a reviewed translation ships', ($reviewed['r'] ?? null) === 't');

// -----------------------------------------------------------------------------
section('the summary a console prints');

$s = Merge::summary($existing, $merged);

test('new keys are counted', $s['added'] === ['c']);
test('outdated ones are counted', $s['outdated'] === ['b']);
test('retired ones are counted', $s['obsolete'] === ['z']);
test('and the rest is just a number', $s['unchanged_n'] === 1);

// -----------------------------------------------------------------------------
section('round trips: nothing is lost in either format');

$units = [
    'plain'  => $translated('plain', 'Salva', 'Speichern'),
    'fuzzy'  => new TranslationUnit('fuzzy', 'Annulla', 'Abbrechen', TranslationUnit::NEEDS_REVIEW, ['was: Annulla adesso']),
    'empty'  => new TranslationUnit('empty', 'Nuovo', '', TranslationUnit::INITIAL, [], ['src/x.php:12']),
    'dead'   => new TranslationUnit('dead', 'Vecchio', 'Alt', TranslationUnit::TRANSLATED, [], [], true),
    'quotes' => $translated('quotes', 'Dice "ciao"', 'Sagt "hallo"'),
    'lines'  => $translated('lines', "Prima\nSeconda", "Erste\nZweite"),
    'icu'    => $translated('icu', '{n, plural, one{# parte} other{# parti}}', '{n, plural, one{# Teil} other{# Teile}}'),
];

foreach (['Xliff' => Xliff::class, 'Po' => Po::class] as $label => $class) {
    $back  = $class::read($class::write($units, 'it', 'de'));
    $wrong = [];

    foreach ($units as $key_c => $unit) {
        if (!isset($back[$key_c])) {
            $wrong[] = "{$key_c} missing";
            continue;
        }

        foreach (['source', 'target', 'state_code', 'is_obsolete'] as $method) {
            if ($unit->{$method}() !== $back[$key_c]->{$method}()) {
                $wrong[] = "{$key_c}.{$method}";
            }
        }
    }

    test("{$label} survives a round trip intact", $wrong === [], implode(', ', $wrong));
}

// The two properties that would quietly break a translation if lost.
$xliff_back = Xliff::read(Xliff::write($units, 'it', 'de'));
test('XLIFF keeps the needs-review flag', $xliff_back['fuzzy']->state_code() === TranslationUnit::NEEDS_REVIEW,
    'XLIFF 2.0 has no "fuzzy"; it rides in subState so a tool that ignores it does not erase it');
test('XLIFF keeps an ICU plural construct character for character',
    $xliff_back['icu']->target() === $units['icu']->target());
test('XLIFF keeps the source references', $xliff_back['empty']->references() === ['src/x.php:12']);

$po_back = Po::read(Po::write($units, 'it', 'de'));
test('PO keeps the fuzzy flag', $po_back['fuzzy']->state_code() === TranslationUnit::NEEDS_REVIEW);
test('PO keeps obsolete entries', $po_back['dead']->is_obsolete());
test('PO keeps embedded quotes', $po_back['quotes']->target() === 'Sagt "hallo"');
test('PO keeps newlines', $po_back['lines']->target() === "Erste\nZweite");

// -----------------------------------------------------------------------------
section('the PO adaptation: identity in msgctxt, not in msgid');

$po = Po::write(['a.b.c' => $translated('a.b.c', 'Salva', 'Speichern')], 'it', 'de');

test('the key travels as msgctxt', strpos($po, 'msgctxt "a.b.c"') !== false);
test('…and the source stays in msgid, where a translator reads it',
    strpos($po, 'msgid "Salva"') !== false,
    'mapping keys onto msgid would orphan every translation when a comma is fixed');

// -----------------------------------------------------------------------------
section('a malformed file is refused, not half-read');

test('broken XML raises', (static function (): bool {
    try {
        Xliff::read('<xliff><file><unit id="a"><segment>');

        return false;
    } catch (I18nException $e) {
        return true;
    }
})());

test('a PO file with no entries reads as empty rather than throwing', Po::read('') === []);

exit(summary());
