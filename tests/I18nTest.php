<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n — the cases a naive translator gets wrong
 *
 * "Look a key up and substitute a name" is passed by `str_replace`. What
 * separates an i18n library from that is the handful of places where the
 * obvious implementation is quietly incorrect:
 *
 *   - plural categories are a property of a *language*, and `$n === 1` is wrong
 *     for most of the world
 *   - an apostrophe is a quoting character to ICU, so "l'utente" loses letters
 *     if it goes through the wrong path
 *   - a percentage formatter rounds by default, so 7.5% becomes 8%
 *   - a missing key must be findable without taking the page down
 *
 * Everything here attacks those.
 *
 * Run: php src/Libs/Italix/I18n/tests/I18nTest.php
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

use Italix\Encode\Html;
use Italix\I18n\Catalog;
use Italix\I18n\Extractor;
use Italix\I18n\Formatter;
use Italix\I18n\I18nException;
use Italix\I18n\Locale;
use Italix\I18n\PhpDirectoryLoader;
use Italix\I18n\T;
use Italix\I18n\Translator;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix I18n');

if (!extension_loaded('intl')) {
    echo "  SKIPPED — ext-intl is absent, which this library requires.\n";
    exit(summary());
}

$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (I18nException $e) {
        return [true, $e->getMessage()];
    }
};

$work = sys_get_temp_dir() . '/ix-i18n-test-' . bin2hex(random_bytes(6));

try {

// -----------------------------------------------------------------------------
section('locale tags');

test('underscores are accepted', Locale::normalise('it_IT') === 'it-IT',
    'PHP and half the world\'s config files produce them');
test('case is normalised', Locale::normalise('IT-it') === 'it-IT');
test('a script subtag keeps its shape', Locale::normalise('zh-hans-cn') === 'zh-Hans-CN');
test('a numeric region is allowed', Locale::normalise('es-419') === 'es-419');
test('whitespace is trimmed', Locale::normalise('  fr-FR  ') === 'fr-FR');

foreach (['', '1t', 'toolongsubtag-XX', '-IT', 'it-'] as $bad_c) {
    [$threw] = $throws(static function () use ($bad_c): void { Locale::normalise($bad_c); });
    test('a malformed tag is refused: ' . var_export($bad_c, true), $threw);
}

test('the language comes out', Locale::language_of('zh-Hans-CN') === 'zh');
test('the region comes out', Locale::region_of('zh-Hans-CN') === 'CN');
test('…and is null when absent', Locale::region_of('it') === null);

test('the chain goes specific to general',
    Locale::chain('it-IT', 'en') === ['it-IT', 'it', 'en']);
test('…without repeating the fallback',
    Locale::chain('en-GB', 'en') === ['en-GB', 'en']);
test('a related language beats the default',
    Locale::chain('de-AT', 'en')[1] === 'de',
    'an Austrian visitor is better served by German than by English');

test('best_of prefers an exact match',
    Locale::best_of('it-IT', ['en', 'it-IT', 'it']) === 'it-IT');
test('…then the bare language', Locale::best_of('it-IT', ['en', 'it']) === 'it');
test('…then a regional variant of it', Locale::best_of('it', ['en', 'it-CH']) === 'it-CH',
    'it-CH is a better answer for it than nothing at all');
test('…and null when nothing relates', Locale::best_of('ja', ['en', 'it']) === null);

$header = Locale::from_header('fr-CH, fr;q=0.9, en;q=0.8, de;q=0.7, *;q=0.5');
test('Accept-Language is parsed by weight', $header === ['fr-CH', 'fr', 'en', 'de'], json_encode($header));
test('equal weights keep header order',
    Locale::from_header('en;q=0.5, it;q=0.5') === ['en', 'it']);
test('a wildcard is dropped', !in_array('*', Locale::from_header('*, it'), true));

// -----------------------------------------------------------------------------
section('catalogues');

$catalog = new Catalog('it', [
    'admin' => ['users' => ['title' => 'Utenti', 'none' => 'Nessun utente']],
    'flat.key' => 'già piatta',
    'empty_section' => [],
]);

test('nesting is flattened to dot paths', $catalog->get('admin.users.title') === 'Utenti');
test('an already flat key survives', $catalog->get('flat.key') === 'già piatta');
test('an empty section produces no key', !$catalog->has('empty_section'),
    'an unfinished section should read as missing, which is reportable');
test('has() agrees with get()', $catalog->has('admin.users.none') && !$catalog->has('nope'));
test('the locale is normalised', (new Catalog('it_IT'))->locale_code() === 'it-IT');

[$threw, $message] = $throws(static function (): void {
    new Catalog('it', ['a' => true]);
});
test('a non-scalar message is refused, not cast', $threw,
    'a message rendering as "1" because somebody wrote true reaches production looking translated');
test('…naming the offending path', strpos($message, '"a"') !== false, $message);

$base     = new Catalog('it', ['a' => 'uno', 'b' => 'due']);
$override = new Catalog('it', ['b' => 'DUE']);
$merged   = $base->merge($override);

test('merge lets the later one win', $merged->get('b') === 'DUE');
test('…and keeps the rest', $merged->get('a') === 'uno');
test('…without touching the original', $base->get('b') === 'due', 'catalogues are immutable');

// -----------------------------------------------------------------------------
section('plural categories belong to the language, not to the number');

$plural = '{n, plural, =0 {nothing} one {# thing} few {# things (few)} many {# things (many)} other {# things}}';

$categories = [];
foreach (['en', 'it', 'ru', 'pl', 'ja', 'ar'] as $locale_c) {
    $t = new Translator([new Catalog($locale_c, ['x' => $plural])], $locale_c);
    $categories[$locale_c] = [];

    foreach ([0, 1, 2, 5, 21, 22] as $n) {
        $categories[$locale_c][$n] = $t->choice('x', $n);
    }
}

test('English: 1 is one, 2 is other',
    strpos($categories['en'][1], '1 thing') === 0 && strpos($categories['en'][2], '2 things') === 0);
test('Russian: 21 falls in ONE, not other',
    strpos($categories['ru'][21], '21 thing') === 0,
    'the case a `$n === 1` implementation gets wrong: ' . $categories['ru'][21]);
test('Russian: 22 falls in FEW',
    strpos($categories['ru'][22], 'few') !== false, $categories['ru'][22]);
test('Polish: 5 falls in MANY',
    strpos($categories['pl'][5], 'many') !== false, $categories['pl'][5]);
test('Japanese has one category: 1 and 5 take the same branch',
    strpos($categories['ja'][1], 'things') !== false && strpos($categories['ja'][5], 'things') !== false,
    json_encode([$categories['ja'][1], $categories['ja'][5]]));
test('an explicit =0 wins over the category', $categories['it'][0] === 'nothing');

// -----------------------------------------------------------------------------
section('the apostrophe trap');

// ICU treats ' as a quoting character. A message with no braces has nothing for
// ICU to do, so it must not go through the parser at all — otherwise Italian
// and French lose letters in production and nobody can explain why.
$t = new Translator([new Catalog('it', [
    'plain'      => "L'utente non esiste",
    'with_param' => "L'utente {name} non esiste",
])], 'it');

test('an apostrophe survives a message with no placeholders',
    $t->get('plain') === "L'utente non esiste", $t->get('plain'));
test('…and the parameter still works when there is one',
    strpos($t->get('with_param', ['name' => 'Anna']), 'Anna') !== false,
    $t->get('with_param', ['name' => 'Anna']));

// -----------------------------------------------------------------------------
section('nothing breaks the page, everything is findable');

$t = new Translator([
    new Catalog('it', ['ok' => 'va bene', 'broken' => '{n, plural, one {uno}']),
    new Catalog('en', ['only_en' => 'English only', 'ok' => 'fine']),
], 'it-IT', 'en');

test('a present key is translated', $t->get('ok') === 'va bene');
test('the fallback answers what the locale cannot', $t->get('only_en') === 'English only');

test('a missing key returns the key itself', $t->get('no.such.key') === 'no.such.key',
    'a screen showing "admin.title" is obviously broken; one showing nothing is a mystery');
test('…and is recorded', array_key_exists('no.such.key', $t->problems()));
test('…saying which locale looked', strpos($t->problems()['no.such.key'], 'it-IT') !== false);

$out = $t->get('broken', ['n' => 1]);
test('an unparseable message returns the raw message', $out === '{n, plural, one {uno}');
test('…rather than throwing, which would 500 a working page', true);
test('…and is recorded as unparseable', strpos($t->problems()['broken'] ?? '', 'unparseable') !== false,
    json_encode($t->problems()));

$t->forget_problems();
test('problems can be cleared between requests', $t->problems() === []);

test('in() switches locale without reloading',
    $t->in('en')->get('ok') === 'fine' && $t->get('ok') === 'va bene');
test('has() does not record a problem', $t->has('nope') === false && $t->problems() === []);

test('an inline message can be formatted without a catalogue',
    (new Translator([], 'en'))->format_message('{n, plural, one {# cat} other {# cats}}', ['n' => 2]) === '2 cats');

// -----------------------------------------------------------------------------
section('formatting is the locale\'s business, not ours');

$it = new Formatter('it-IT', 'Europe/Rome');
$en = new Formatter('en-US', 'UTC');

test('Italian groups with dots', $it->number(1234567.891) === '1.234.567,891', $it->number(1234567.891));
test('English groups with commas', $en->number(1234567.891) === '1,234,567.891', $en->number(1234567.891));
test('the currency symbol lands where the locale puts it',
    $it->currency(1234.5, 'EUR') !== $en->currency(1234.5, 'EUR'),
    $it->currency(1234.5, 'EUR') . ' vs ' . $en->currency(1234.5, 'EUR'));
test('…with two decimals for euros', strpos($it->currency(1234.5, 'EUR'), '1.234,50') === 0,
    $it->currency(1234.5, 'EUR'));

[$threw] = $throws(static function () use ($it): void { $it->currency(1.0, 'euro'); });
test('a currency that is not ISO 4217 is refused', $threw);

// The measured default: ICU's percent formatter uses zero fraction digits.
test('a percentage is NOT rounded away by default', $it->percent(0.075) === '7,5%', $it->percent(0.075));
test('…and can still be narrowed on request', $it->percent(0.075, 0) === '8%');

test('ordinals are localised', $it->ordinal(3) === '3º' && $en->ordinal(3) === '3rd',
    $it->ordinal(3) . ' / ' . $en->ordinal(3));
test('numbers can be spelled out', strpos($it->spell(42), 'quarant') === 0, $it->spell(42));

test('bytes use IEC units and the locale number format',
    $it->bytes(1536000) === "1,5\u{00A0}MiB", $it->bytes(1536000));
test('…with no decimals below a kibibyte', $it->bytes(512) === "512\u{00A0}B", $it->bytes(512));
test('…and a sign for negatives', strpos($it->bytes(-2048), '-') === 0, $it->bytes(-2048));

$moment = 1786000000;
test('dates differ by locale', $it->date($moment) !== $en->date($moment),
    $it->date($moment) . ' / ' . $en->date($moment));
test('a timezone is honoured',
    $it->date_time($moment, 'short', 'short') !== $it->with_timezone('UTC')->date_time($moment, 'short', 'short'));
test('an ICU pattern can be used directly',
    $it->pattern($moment, 'd MMMM y') === '6 agosto 2026', $it->pattern($moment, 'd MMMM y'));

[$threw, $message] = $throws(static function () use ($it, $moment): void { $it->date($moment, 'enormous'); });
test('an unknown style is refused by name', $threw);
test('…listing the ones that exist', strpos($message, 'medium') !== false, $message);

// -----------------------------------------------------------------------------
section('relative time is computed, not worded');

$now = 1786000000;

foreach ([
    [$now - 30,        'second', 30, true],
    [$now - 3 * 86400, 'day',    3,  true],
    [$now + 2 * 86400, 'day',    2,  false],
    [$now - 400 * 86400, 'year', 1,  true],
] as [$when, $unit_c, $count_n, $is_past]) {
    $parts = $it->relative_parts($when, $now);

    test("{$count_n} {$unit_c} " . ($is_past ? 'ago' : 'ahead') . ' is computed correctly',
        $parts['unit_c'] === $unit_c && $parts['count_n'] === $count_n && $parts['is_past'] === $is_past,
        json_encode($parts));
}

test('the same moment is zero seconds', $it->relative_parts($now, $now)['count_n'] === 0);
test('a DateTime is accepted as well as a timestamp',
    $it->relative_parts(new DateTimeImmutable('@' . ($now - 3600)), $now)['unit_c'] === 'hour');

// Worded by the application's own catalogue, which is the whole point.
$rt = new Translator([new Catalog('it', [
    'time.past.day' => '{n, plural, one {# giorno fa} other {# giorni fa}}',
])], 'it');
$parts = $it->relative_parts($now - 3 * 86400, $now);
test('the parts feed an ordinary plural message',
    $rt->choice('time.past.day', $parts['count_n']) === '3 giorni fa');

// -----------------------------------------------------------------------------
section('loading from a directory');

@mkdir($work . '/lang/it', 0775, true);
@mkdir($work . '/lang/en', 0775, true);
@mkdir($work . '/lang/not-a-locale-at-all', 0775, true);

file_put_contents($work . '/lang/it/messages.php', "<?php return ['admin' => ['title' => 'Amministrazione']];");
file_put_contents($work . '/lang/it/errors.php', "<?php return ['required' => 'Campo obbligatorio'];");
file_put_contents($work . '/lang/en/messages.php', "<?php return ['admin' => ['title' => 'Administration']];");

$loaded = [];
foreach ((new PhpDirectoryLoader($work . '/lang'))->load() as $one) {
    $loaded[$one->locale_code()] = $one;
}

test('one catalogue per locale directory', array_keys($loaded) === ['en', 'it'], json_encode(array_keys($loaded)));
test('messages.php contributes no prefix', $loaded['it']->get('admin.title') === 'Amministrazione');
test('another file becomes a prefix', $loaded['it']->get('errors.required') === 'Campo obbligatorio',
    'so the common case never has to see the concept');
test('a directory that is not a locale is skipped', !isset($loaded['not-a-locale-at-all']));

[$threw] = $throws(static function () use ($work): void { new PhpDirectoryLoader($work . '/nope'); });
test('a missing directory is refused at construction', $threw);

file_put_contents($work . '/lang/en/bad.php', '<?php return "not an array";');
[$threw] = $throws(static function () use ($work): void { (new PhpDirectoryLoader($work . '/lang'))->load(); });
test('a language file that is not an array is refused', $threw);
unlink($work . '/lang/en/bad.php');

$only = (new PhpDirectoryLoader($work . '/lang', ['it']))->load();
test('loading can be narrowed to some locales', count($only) === 1 && $only[0]->locale_code() === 'it');

// -----------------------------------------------------------------------------
section('the extractor refuses to guess');

@mkdir($work . '/src', 0775, true);
file_put_contents($work . '/src/Page.php', <<<'CODE'
<?php
echo $t->get('admin.title');
echo $t->get("admin.subtitle");
echo $this->t->choice('files.count', 3);
echo $t->get('admin.' . $suffix);
echo $t->get($computed_key);
echo $view->get('main');
echo $form->get('email');
echo other_function('not a key at all, just a sentence');
CODE);

$report = (new Extractor())->scan([$work . '/src']);

test('a single-quoted key is found', isset($report['keys']['admin.title']));
test('a double-quoted key is found', isset($report['keys']['admin.subtitle']));
test('choice() names a key too', isset($report['keys']['files.count']));
test('$this->t is a translator too', isset($report['keys']['files.count']));
test('…and where it was seen is recorded',
    strpos($report['keys']['admin.title'][0], 'Page.php:2') !== false, json_encode($report['keys']['admin.title']));

test('a concatenated key is NOT guessed at', !isset($report['keys']['admin.']),
    'a guessed list gets ignored, which is worse than no list');
test('a variable key is NOT guessed at', count($report['keys']) === 3, json_encode(array_keys($report['keys'])));
test('…but both are reported as dynamic', count($report['dynamic']) === 2, json_encode($report['dynamic']));
test('a sentence is not mistaken for a key', !isset($report['keys']['not a key at all, just a sentence']));

// The precision problem, found by running against a real codebase: a bare
// `->get('…')` matches half the framework.
test('$view->get() is not a translation', !isset($report['keys']['main']),
    'a report that is mostly noise is a report nobody reads');
test('$form->get() is not a translation', !isset($report['keys']['email']));
test('the receiver list is visible', in_array('t', (new Extractor())->receivers(), true));

$loose = new Extractor(['php'], ['vendor'], ['t', 'view']);
test('…and configurable when a project names things differently',
    isset($loose->scan([$work . '/src'])['keys']['main']));

// A codebase that reaches the translator statically has no `$t` to match, so
// without this the report keeps saying "nothing missing" while checking a
// shrinking fraction of the keys — the failure being a key rendered on a page.
file_put_contents($work . '/src/Facade.php', <<<'CODE'
<?php
echo T::e('facade.encoded');
echo T::get('facade.plain');
echo T::choice_e('facade.counted', 2);
echo \Italix\I18n\T::e('facade.qualified');
echo T::e('facade.' . $suffix);
echo Other::e('not.a.translation');
CODE);

$facade_report = (new Extractor())->scan([$work . '/src']);

test('T::e() names a key', isset($facade_report['keys']['facade.encoded']),
    'the encoding variant is the same lookup with the result typed');
test('T::get() names a key', isset($facade_report['keys']['facade.plain']));
test('T::choice_e() names a key', isset($facade_report['keys']['facade.counted']));
test('a fully qualified facade is found too', isset($facade_report['keys']['facade.qualified']));
test('a concatenated facade key is still not guessed at', !isset($facade_report['keys']['facade.']));
test('another class\'s ::e() is not a translation', !isset($facade_report['keys']['not.a.translation']),
    'named for the same reason receivers are named');

@unlink($work . '/src/Facade.php');

$comparison = (new Extractor())->compare(
    [$work . '/src'],
    new Catalog('it', ['admin' => ['title' => 'x'], 'files' => ['count' => 'y'], 'never' => ['used' => 'z']])
);

test('a used key with no message is missing', array_key_exists('admin.subtitle', $comparison['missing']));
test('…and a translated one is not', !array_key_exists('admin.title', $comparison['missing']));
test('a message nobody asks for is unused', in_array('never.used', $comparison['unused'], true));

$source = new Catalog('en', ['a' => 'one', 'b' => 'two', 'c' => 'Italix']);
$target = new Catalog('it', ['a' => 'uno', 'c' => 'Italix']);

test('untranslated keys are the worklist', Extractor::untranslated($source, $target) === ['b']);
test('identical messages are surfaced without judgement', Extractor::identical($source, $target) === ['c'],
    'a real cognate or an untranslated copy — the tool cannot tell and does not pretend to');

} finally {
    if (is_dir($work)) {
        $it_files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it_files as $f) {
            $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f);
        }
        @rmdir($work);
    }
}

test('the temporary directory was removed', !is_dir($work));

// -----------------------------------------------------------------------------
section('lists — "Rossi, Bianchi e Verdi", not implode()');

$italian = new Formatter('it-IT');
$english = new Formatter('en-GB');

test('two items', $italian->list_of(['Rossi', 'Bianchi']) === 'Rossi e Bianchi');
test('three items', $italian->list_of(['Rossi', 'Bianchi', 'Verdi']) === 'Rossi, Bianchi e Verdi');
test('four items', $italian->list_of(['A', 'B', 'C', 'D']) === 'A, B, C e D');

// The case that makes a hand-rolled join wrong: English changes its mind about
// the comma between two items and three.
test('ENGLISH DROPS THE COMMA FOR TWO', $english->list_of(['A', 'B']) === 'A and B');
test('…and puts one back for three', $english->list_of(['A', 'B', 'C']) === 'A, B, and C',
    'the Oxford comma is in CLDR, and no hand-written join has it');

test('a disjunction is a different word', $italian->list_of(['A', 'B'], 'or') === 'A o B');
test('…in every language', (new Formatter('de-DE'))->list_of(['A', 'B'], 'or') === 'A oder B');

test('one item is itself', $italian->list_of(['Solo']) === 'Solo');
test('none is nothing', $italian->list_of([]) === '');

test('a type that is not and/or is refused', (static function () use ($italian): bool {
    try {
        $italian->list_of(['A', 'B'], 'nonsense');

        return false;
    } catch (I18nException $e) {
        return true;
    }
})());

// PHP exposes no ListFormatter, so this is table-driven under *both* engines —
// there is nothing to delegate to even when ext-intl is installed.
test('the portable engine joins lists identically',
    $english->list_of(['A', 'B', 'C']) === $english->using_engine('portable')->list_of(['A', 'B', 'C']));

// -----------------------------------------------------------------------------

section('the two halves of a message are not equally trustworthy');

// The template comes from a file in the repository. The parameter routinely
// comes from a column somebody filled in through a form. `get()` returns one
// string and cannot tell them apart, which left a caller choosing between
// destroying the markup and printing the attack.
$hostile = '<script>alert(1)</script> & "co"';

$view = new Translator([new Catalog('it', [
    'plain'  => 'Ciao {name}',
    'markup' => 'Order for <strong>{customer}</strong>',
    'rows'   => '{n, plural, =0 {nessuna riga} one {# riga} other {# righe}}',
])], 'it', 'it');

test('get() still hands back exactly what the catalogue and the caller said',
    $view->get('plain', ['name' => $hostile]) === 'Ciao ' . $hostile,
    'e() is the encoding one; changing get() would break every non-HTML caller');

test('e() encodes the parameter',
    strpos((string) $view->e('plain', ['name' => $hostile]), '<script>') === false,
    (string) $view->e('plain', ['name' => $hostile]));

test('…AND LEAVES THE TEMPLATE ALONE, WHICH IS THE WHOLE POINT',
    (string) $view->e('markup', ['customer' => $hostile])
        === 'Order for <strong>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;co&quot;</strong>',
    'a message allowed to carry markup, with the value inside it still inert');

test('the result is already encoded, so H::e() around it is a no-op',
    (string) Html::e($view->e('markup', ['customer' => $hostile]))
        === (string) $view->e('markup', ['customer' => $hostile]),
    'double encoding cannot happen by composition');

test('H::j() refuses it, because a JS literal is not an HTML context',
    (static function () use ($view): bool {
        try {
            Html::j($view->e('plain', ['name' => 'x']));

            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    })(),
    'the one combination that would be wrong fails loudly instead of shipping &amp; to a user');

// The count goes through the encoder like everything else, which turns 3 into
// '3'. Measured, ICU coerces it back and selects the same form — so these are
// here to keep that true, not to document a special case.
test('encoding the count does not break plural selection: 0',
    (string) $view->choice_e('rows', 0) === 'nessuna riga');
test('…1', (string) $view->choice_e('rows', 1) === '1 riga');
test('…5', (string) $view->choice_e('rows', 5) === '5 righe');

// -----------------------------------------------------------------------------

section('the facade: reachable from a template, and never left dirty');

$two = new Translator([
    new Catalog('it', ['hi' => 'Ciao']),
    new Catalog('en', ['hi' => 'Hello']),
], 'it', 'en');

T::unbind();
test('nothing is bound until somebody binds it', !T::is_bound(),
    'binding at boot is the bug this refuses to make convenient');

test('and calling it unbound throws rather than guessing a language',
    (static function (): bool {
        try {
            T::e('hi');

            return false;
        } catch (I18nException $e) {
            return true;
        }
    })(),
    'a page in the wrong language is reported as a translation bug months later');

T::bind($two);
test('bound, it answers', (string) T::e('hi') === 'Ciao');

// One request, two languages: the page in the operator's, the e-mail in the
// recipient's. The bit that matters is the third assertion, not the second.
$mailed = T::for_locale('en', static function (): string {
    return (string) T::e('hi');
});

test('for_locale() answers in the other language', $mailed === 'Hello');
test('…AND PUTS THE FACADE BACK', (string) T::e('hi') === 'Ciao',
    'forgetting this renders the rest of the request in the recipient\'s language');

// The case nobody writes a test for, which is why the restore is in a finally.
try {
    T::for_locale('en', static function (): void {
        throw new RuntimeException('the mailer was down');
    });
} catch (RuntimeException $e) {
    // expected
}

test('…even when the work throws on the way out', (string) T::e('hi') === 'Ciao',
    'an exception is exactly the path that would otherwise leave it dirty');

$nested = T::for_locale('en', static function (): array {
    $inner = T::for_locale('it', static function (): string {
        return (string) T::e('hi');
    });

    return [$inner, (string) T::e('hi')];
});

test('nesting restores one level, not all of them', $nested === ['Ciao', 'Hello'], json_encode($nested));
test('…and the outermost level is restored too', (string) T::e('hi') === 'Ciao');

test('the bound translator itself was never mutated', $two->locale_code() === 'it',
    'in() returns a copy, which is why it is on the contract');

T::unbind();

exit(summary());
