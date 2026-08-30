<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Baker
 *
 * @package Italix\I18n
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\I18n;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;

/**
 * Asks ICU what it does, and writes the answers down.
 *
 * This is the whole design of the portable engine in one sentence: **the
 * fallback is a cache of ICU's answers, not a reimplementation of its
 * opinions.** Nobody would guess that `de-AT` groups its numbers with a space
 * and its money with a full stop, or that Danish and German agree on integers
 * and part company on decimals. Written by hand, a table like this is wrong in
 * places nobody local ever looks.
 *
 * So it is generated on a machine that *has* ext-intl, committed, and shipped —
 * and `ix i18n:bake --check` fails when the committed file no longer matches
 * what ICU says, which is how a table stays true across an ICU upgrade.
 *
 * ## What is deliberately not baked
 *
 * Collation, spellout and display names. The first cannot be approximated —
 * Swedish sorts Å/Ä/Ö *after* Z, and the obvious shortcut of stripping accents
 * gives correct Italian and wrong Swedish. The other two are a data product
 * rather than a table. {@see PortableEngine} refuses all three with a reason
 * instead of answering badly.
 *
 * ## The one lossy step, stated plainly
 *
 * Plural categories are sampled, not derived: the CLDR rule itself is not
 * reachable through PHP's API. Integers 0–199 are probed, and the sampling is
 * only sound because the rules are periodic — `category(n) == category(100 + n
 * mod 100)` for every n ≥ 200. That is not assumed: {@see bake()} verifies it
 * for every locale it writes, over 4,800 values, and refuses a locale where it
 * does not hold.
 */
final class Baker
{
    /** Where the baked file belongs, relative to this package's root. */
    public const PATH = 'data/cldr.php';

    /**
     * The languages the portable engine carries.
     *
     * The 24 official EU languages. Not a limit of the design — anything ICU
     * knows can be baked — but a limit of what is *verified*, and a table
     * claiming more than it was checked against would be the same lie the rest
     * of this library exists to avoid.
     */
    public const LOCALES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr',
        'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
    ];

    /** Regional forms whose numbers or money differ from the base language. */
    public const REGIONS = [
        'de-AT', 'de-CH', 'en-IE', 'es-ES', 'fr-BE', 'fr-CH', 'it-CH', 'nl-BE', 'pt-PT',
    ];

    /** Currencies whose symbol and minor units are carried. */
    public const CURRENCIES = ['EUR', 'CHF', 'GBP', 'USD', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'JPY'];

    /**
     * One letter per category, so a rule is a 200-character string.
     *
     * Written out as an array of words, the twelve shapes came to 53 KB — half
     * the table, and almost all of it the word "other" repeated. The letters
     * carry the same information in 2.4 KB.
     */
    private const CATEGORY_LETTER = [
        'zero' => 'z', 'one' => 'o', 'two' => 't', 'few' => 'f', 'many' => 'm', 'other' => 'x',
    ];

    /** How far the periodicity of the plural rules is verified. */
    private const PERIODIC_TO = 5000;

    /** The decimal values probed on top of the integers 0–199. */
    private const DECIMALS = [0.0, 0.5, 1.0, 1.5, 2.0, 2.5, 1.1, 11.5, 100.5];

    /** Is ICU here to be asked? */
    public static function is_possible(): bool
    {
        return class_exists(MessageFormatter::class) && class_exists(NumberFormatter::class);
    }

    /**
     * @return array<string, mixed>
     * @throws I18nException when ICU is absent, or a locale breaks periodicity
     */
    public static function bake(): array
    {
        if (!self::is_possible()) {
            throw new I18nException(
                'Baking needs ext-intl: the tables are ICU\'s answers, and without ICU there is '
                . 'nothing to ask. Run this on a machine that has it and commit the result.'
            );
        }

        $locales = array_merge(self::LOCALES, self::REGIONS);

        return [
            'plural_shapes'  => self::plural_shapes(),
            'plural_locale'  => self::plural_locale_map(),
            'number_shapes'  => self::number_shapes($locales),
            'number_locale'  => self::number_locale_map($locales),
            'currencies'     => self::currencies(),
            'currency_affixes' => self::deduplicate(self::currency_affixes($locales)),
            'currency_group'   => self::currency_group_flags($locales),
            'dates'          => self::dates(),
        ];
    }

    // -------------------------------------------------------------------------
    // Plurals
    // -------------------------------------------------------------------------

    /** The category ICU picks, for one locale and one number. */
    private static function category(string $locale_c, $n): string
    {
        static $cache = [];

        $key_c = $locale_c . '|' . var_export($n, true);

        if (isset($cache[$key_c])) {
            return $cache[$key_c];
        }

        $formatter = MessageFormatter::create(
            $locale_c,
            '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}'
        );

        if ($formatter === null) {
            throw new I18nException("ICU could not build a plural formatter for \"{$locale_c}\".");
        }

        $out = $formatter->format(['n' => $n]);

        if ($out === false) {
            throw new I18nException("ICU refused to classify {$n} for \"{$locale_c}\".");
        }

        return $cache[$key_c] = (string) $out;
    }

    /**
     * One entry per *distinct rule*, not per locale.
     *
     * Twenty-four languages share twelve shapes; storing them once is the
     * difference between a table that is worth shipping and one that is not.
     *
     * @return array<string, array{integers: string[], decimals: array<string, string>}>
     */
    private static function plural_shapes(): array
    {
        $shapes = [];

        foreach (self::LOCALES as $locale_c) {
            $shapes[self::shape_key(self::plural_of($locale_c))] = self::plural_of($locale_c);
        }

        return $shapes;
    }

    /** @return array<string, string> locale => shape key */
    private static function plural_locale_map(): array
    {
        $map = [];

        foreach (self::LOCALES as $locale_c) {
            $map[$locale_c] = self::shape_key(self::plural_of($locale_c));
        }

        return $map;
    }

    /** @return array{integers: string, decimals: array<string, string>} */
    private static function plural_of(string $locale_c): array
    {
        static $cache = [];

        if (isset($cache[$locale_c])) {
            return $cache[$locale_c];
        }

        $integers = '';

        for ($n = 0; $n <= 199; $n++) {
            $integers .= self::CATEGORY_LETTER[self::category($locale_c, $n)];
        }

        // The sampling is only sound if the rules repeat. Verified, not assumed:
        // a locale that broke this would be silently mis-pluralised from 200 up.
        for ($n = 200; $n <= self::PERIODIC_TO; $n++) {
            if (self::CATEGORY_LETTER[self::category($locale_c, $n)] !== $integers[100 + ($n % 100)]) {
                throw new I18nException(
                    "Plural rules for \"{$locale_c}\" are not periodic at {$n}: the sampled table "
                    . 'would be wrong from there on. This locale cannot be baked by sampling.'
                );
            }
        }

        $decimals = [];

        foreach (self::DECIMALS as $value) {
            $decimals[self::decimal_key($value)] = self::CATEGORY_LETTER[self::category($locale_c, $value)];
        }

        return $cache[$locale_c] = ['integers' => $integers, 'decimals' => $decimals];
    }

    /** A stable name for a shape, so identical rules share one entry. */
    private static function shape_key(array $shape): string
    {
        return substr(sha1(json_encode($shape) ?: ''), 0, 8);
    }

    /** `1.5` as a key that survives a PHP array and a JSON round trip. */
    private static function decimal_key(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    // -------------------------------------------------------------------------
    // Numbers and money
    // -------------------------------------------------------------------------

    /**
     * @param  string[] $locales
     * @return array<string, array<string, mixed>>
     */
    private static function number_shapes(array $locales): array
    {
        $shapes = [];

        foreach ($locales as $locale_c) {
            $shapes[self::shape_key(self::number_of($locale_c))] = self::number_of($locale_c);
        }

        return $shapes;
    }

    /**
     * @param  string[] $locales
     * @return array<string, string>
     */
    private static function number_locale_map(array $locales): array
    {
        $map = [];

        foreach ($locales as $locale_c) {
            $map[$locale_c] = self::shape_key(self::number_of($locale_c));
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private static function number_of(string $locale_c): array
    {
        static $cache = [];

        if (isset($cache[$locale_c])) {
            return $cache[$locale_c];
        }

        $decimal  = new NumberFormatter($locale_c, NumberFormatter::DECIMAL);
        $currency = new NumberFormatter($locale_c, NumberFormatter::CURRENCY);
        $percent  = new NumberFormatter($locale_c, NumberFormatter::PERCENT);

        return $cache[$locale_c] = [
            'decimal_c'  => $decimal->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            'group_c'    => $decimal->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
            'minus_c'    => $decimal->getSymbol(NumberFormatter::MINUS_SIGN_SYMBOL),
            'percent_c'  => $percent->getSymbol(NumberFormatter::PERCENT_SYMBOL),
            'grouping_n' => $decimal->getAttribute(NumberFormatter::GROUPING_SIZE),

            // The currency *pattern* is what nobody would guess: whether the
            // symbol leads or trails, and what sits between it and the digits.
            // de-AT groups its numbers with a space and its money with a stop.
            'currency_pattern_c'  => $currency->getPattern(),

            // The MONETARY symbols, not the plain ones. de-AT groups its
            // numbers with a space and its money with a full stop, and reading
            // GROUPING_SEPARATOR_SYMBOL off a currency formatter gives the
            // former for both. The bake refused rather than writing it down
            // wrong, which is how this was found.
            'currency_decimal_c'  => $currency->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL),
            'currency_group_c'    => $currency->getSymbol(NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL),
            'percent_pattern_c'   => $percent->getPattern(),
        ];
    }

    /** @return array<string, array{digits_n: int}> */
    private static function currencies(): array
    {
        $out = [];

        foreach (self::CURRENCIES as $code_c) {
            $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $code_c);

            // The exponent comes from the currency, never from the locale: an
            // it-IT formatter asked about JPY must still answer 0.
            $out[$code_c] = ['digits_n' => $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS)];
        }

        return $out;
    }

    /**
     * What ICU puts **before and after** the digits, per locale and currency.
     *
     * The first version of this stored a symbol and reassembled it with the
     * currency pattern. It was wrong in two ways that only a differential run
     * finds:
     *
     *  - **Bulgarian does not group its money.** Its currency pattern is
     *    `0.00 ¤`, with no `#,##` — while its *decimal* pattern groups
     *    normally. Reading the grouping off the decimal formatter printed
     *    `1 234,50 €` where ICU prints `1234,50 €`.
     *  - **CLDR inserts a space between an alphabetic symbol and a digit.**
     *    `en` renders `CHF 1,000.00` from a pattern that has no space in it,
     *    because `CHF` ends in a letter and `€` does not.
     *
     * Both are rules with exceptions, and reimplementing rules with exceptions
     * is how a fallback starts disagreeing. So the affixes are **taken whole**:
     * ICU renders a known amount, our own digit assembly is located inside the
     * result, and whatever sits on either side is stored verbatim. Anything
     * that cannot be located that way is **refused at bake time** rather than
     * written down wrong.
     *
     * @param  string[] $locales
     * @return array<string, array<string, array<string, string>>>
     */
    private static function currency_affixes(array $locales): array
    {
        $out = [];

        foreach ($locales as $locale_c) {
            $shape      = self::number_of($locale_c);
            $group_flag = strpos($shape['currency_pattern_c'], ',') !== false;

            foreach (self::CURRENCIES as $code_c) {
                $formatter  = new NumberFormatter($locale_c, NumberFormatter::CURRENCY);
                $digits_n   = (int) (new NumberFormatter('en', NumberFormatter::CURRENCY))
                    ->getAttribute(NumberFormatter::FRACTION_DIGITS);

                $probe_formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
                $probe_formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $code_c);
                $digits_n = (int) $probe_formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

                $out[$locale_c][$code_c] = [
                    'positive' => self::affix_of($formatter, $code_c, 1234.5, $shape, $group_flag, $digits_n, $locale_c),
                    'negative' => self::affix_of($formatter, $code_c, -1234.5, $shape, $group_flag, $digits_n, $locale_c),
                ];
                // (deduplicated below: 396 pairs share 91 distinct affixes)
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $shape
     * @return array{pre: string, post: string}
     */
    private static function affix_of(
        NumberFormatter $formatter,
        string $code_c,
        float $probe,
        array $shape,
        bool $group_flag,
        int $digits_n,
        string $locale_c
    ): array {
        $rendered_c = (string) $formatter->formatCurrency($probe, $code_c);

        // Rounded the way the engine rounds before assembling, or the two
        // disagree on a boundary and the digits cannot be found. ICU rounds
        // half to even; `number_format` rounds half up. The first probe used
        // here was 1234.5, and JPY — which has no minor units — turned it into
        // 1234 for ICU and 1235 for us. The refusal below caught it.
        $digits_c = PortableEngine::assemble_digits(
            round(abs($probe), $digits_n, PHP_ROUND_HALF_EVEN),
            $digits_n,
            $shape['currency_decimal_c'],
            $group_flag ? $shape['currency_group_c'] : '',
            (int) $shape['grouping_n']
        );

        $at_n = strpos($rendered_c, $digits_c);

        if ($at_n === false) {
            throw new I18nException(
                "Could not locate the digits of {$code_c} inside ICU's own rendering for "
                . "\"{$locale_c}\" (\"{$rendered_c}\"). Refusing to bake a guess."
            );
        }

        return [
            'pre'  => substr($rendered_c, 0, $at_n),
            'post' => substr($rendered_c, $at_n + strlen($digits_c)),
        ];
    }

    /**
     * The same trick as the plural shapes: store each distinct value once.
     *
     * 396 (locale, currency) pairs share 91 distinct affix pairs — most of
     * Europe puts a non-breaking space and a euro sign after the number. Stored
     * flat it was 85 KB, half the table, and almost all of it repetition.
     *
     * @param  array<string, array<string, mixed>> $by_locale
     * @return array{shapes: array<string, mixed>, map: array<string, array<string, string>>}
     */
    private static function deduplicate(array $by_locale): array
    {
        $shapes = [];
        $map    = [];

        foreach ($by_locale as $locale_c => $by_currency) {
            foreach ($by_currency as $code_c => $affix) {
                $key_c = self::shape_key($affix);

                $shapes[$key_c]           = $affix;
                $map[$locale_c][$code_c]  = $key_c;
            }
        }

        return ['shapes' => $shapes, 'map' => $map];
    }

    /**
     * Whether a locale groups its *money*, which is not the same question as
     * whether it groups its numbers: Bulgarian groups one and not the other.
     *
     * @param  string[] $locales
     * @return array<string, bool>
     */
    private static function currency_group_flags(array $locales): array
    {
        $out = [];

        foreach ($locales as $locale_c) {
            $out[$locale_c] = strpos(self::number_of($locale_c)['currency_pattern_c'], ',') !== false;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Dates
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function dates(): array
    {
        $out  = [];
        $utc  = new DateTimeZone('UTC');

        foreach (array_merge(self::LOCALES, self::REGIONS) as $locale_c) {
            // Two forms, because Slavic and Greek have two.
            //
            // `LLLL` is the standalone name — what a month is *called*, as on a
            // calendar heading: Polish "sierpień". `MMMM` is the form used
            // inside a date, which in those languages is genitive: "13 sierpnia
            // 2026". Baking only the standalone one and using it everywhere
            // prints a date no Pole would write, and the same for Czech and
            // Greek. Found by the differential suite, not by reading CLDR.
            $months          = [];
            $months_short    = [];
            $months_alone    = [];
            $months_alone_sh = [];

            for ($m = 1; $m <= 12; $m++) {
                $when = new DateTimeImmutable(sprintf('2026-%02d-15 12:00:00', $m), $utc);

                $months[]          = self::pattern_format($locale_c, $when, 'MMMM');
                $months_short[]    = self::pattern_format($locale_c, $when, 'MMM');
                $months_alone[]    = self::pattern_format($locale_c, $when, 'LLLL');
                $months_alone_sh[] = self::pattern_format($locale_c, $when, 'LLL');
            }

            $days       = [];
            $days_short = [];

            // 2026-06-01 is a Monday; ICU's own day-of-week numbering starts at
            // Sunday, so the array is built in ISO order and that is stated
            // rather than left for the reader to infer.
            for ($d = 0; $d < 7; $d++) {
                $when = new DateTimeImmutable('2026-06-01 12:00:00', $utc);
                $when = $when->modify("+{$d} day");

                $days[]       = self::pattern_format($locale_c, $when, 'EEEE');
                $days_short[] = self::pattern_format($locale_c, $when, 'EEE');
            }

            $noon     = new DateTimeImmutable('2026-06-01 09:00:00', $utc);
            $evening  = new DateTimeImmutable('2026-06-01 21:00:00', $utc);

            $out[$locale_c] = [
                'months'          => $months,
                'months_short'    => $months_short,
                'months_alone'    => $months_alone,
                'months_alone_sh' => $months_alone_sh,
                'days'            => $days,
                'days_short'      => $days_short,

                // Greek writes μ.μ., not PM. Another thing that is data.
                'am_c'            => self::pattern_format($locale_c, $noon, 'a'),
                'pm_c'            => self::pattern_format($locale_c, $evening, 'a'),
                'patterns'        => self::date_patterns($locale_c),
            ];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function date_patterns(string $locale_c): array
    {
        $out   = [];
        $utc   = new DateTimeZone('UTC');
        $when  = new DateTimeImmutable('2026-08-13 14:35:07', $utc);

        foreach ([
            'date_short'  => [IntlDateFormatter::SHORT, IntlDateFormatter::NONE],
            'date_medium' => [IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE],
            'date_long'   => [IntlDateFormatter::LONG, IntlDateFormatter::NONE],
            'time_short'  => [IntlDateFormatter::NONE, IntlDateFormatter::SHORT],
            'time_medium' => [IntlDateFormatter::NONE, IntlDateFormatter::MEDIUM],
            'datetime_short'  => [IntlDateFormatter::SHORT, IntlDateFormatter::SHORT],
            'datetime_medium' => [IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT],
        ] as $name_c => [$date_n, $time_n]) {
            $formatter = new IntlDateFormatter($locale_c, $date_n, $time_n, $utc, IntlDateFormatter::GREGORIAN);

            $out[$name_c] = (string) $formatter->getPattern();
        }

        return $out;
    }

    private static function pattern_format(string $locale_c, DateTimeImmutable $when, string $pattern_c): string
    {
        $formatter = new IntlDateFormatter(
            $locale_c,
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
            new DateTimeZone('UTC'),
            IntlDateFormatter::GREGORIAN,
            $pattern_c
        );

        return (string) $formatter->format($when);
    }

    // -------------------------------------------------------------------------
    // Writing it out
    // -------------------------------------------------------------------------

    /** The file, exactly as it should be on disk. */
    public static function render(): string
    {
        $tables = self::bake();

        $header = <<<'PHP'
<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

// GENERATED by `ix i18n:bake` — do not edit.
//
// These are ICU's own answers, written down: the plural category it picks for a
// number, the separators and currency pattern it uses for a locale, the month
// and day names it prints. `PortableEngine` reads them when ext-intl is absent.
//
// Generated rather than hand-written because nobody would guess that de-AT
// groups numbers with a space and money with a full stop, or that Danish and
// German agree on integers and differ on decimals. A hand-written table is
// wrong in the places nobody local ever looks.
//
// `ix i18n:bake --check` fails when this file no longer matches what ICU says —
// which is how it stays true across an ICU upgrade rather than quietly ageing.

declare(strict_types=1);

return
PHP;

        return $header . ' ' . var_export($tables, true) . ";\n";
    }

    /** Is what is on disk what {@see render()} would write? */
    public static function is_current(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        return trim((string) file_get_contents($path)) === trim(self::render());
    }
}
