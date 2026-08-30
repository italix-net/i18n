<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Formatter
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Numbers, money and dates as the locale writes them.
 *
 * `1234.5` is `1.234,5` in Italian, `1,234.5` in English and `1 234,5` in
 * French, and a currency lands on a different side of the amount depending on
 * both the currency and the locale. None of that is worth reimplementing: ICU
 * has the tables and keeps them current.
 *
 * Formatters are cached per shape because constructing a `NumberFormatter` is
 * measurably expensive and a table of a thousand rows constructs one per cell if
 * nobody thinks about it.
 */
final class Formatter
{
    public const SHORT  = 'short';
    public const MEDIUM = 'medium';
    public const LONG   = 'long';
    public const FULL   = 'full';
    public const NONE   = 'none';

    private const STYLES = [
        self::SHORT  => IntlDateFormatter::SHORT,
        self::MEDIUM => IntlDateFormatter::MEDIUM,
        self::LONG   => IntlDateFormatter::LONG,
        self::FULL   => IntlDateFormatter::FULL,
        self::NONE   => IntlDateFormatter::NONE,
    ];

    private string $locale_c;
    private string $timezone_c;

    /**
     * Who does the work: ICU when it is installed, the baked tables when it is
     * not. Chosen once, here, so nothing downstream has to ask.
     */
    private Engine $engine;

    /** @var array<string, NumberFormatter> */
    private array $numbers = [];

    /** Built on first use: constructing one is not free, and lists ask often. */
    private ?\Collator $collator = null;

    /**
     * @param string|null $engine_c `icu`, `portable`, or null to choose.
     *
     * Forcing it is not a curiosity: on any machine with ext-intl the portable
     * branch would otherwise never execute, and code that never executes is
     * code that is broken. The differential suite forces both.
     */
    public function __construct(string $locale_c, string $timezone_c = 'UTC', ?string $engine_c = null)
    {
        $this->locale_c   = Locale::normalise($locale_c);
        $this->timezone_c = $timezone_c;
        $this->engine     = self::pick_engine($engine_c, $this->locale_c);
    }

    private static function pick_engine(?string $engine_c, string $locale_c): Engine
    {
        if ($engine_c === 'icu') {
            if (!IcuEngine::is_available()) {
                throw new I18nException('The ICU engine was asked for, but ext-intl is not installed.');
            }

            return new IcuEngine();
        }

        if ($engine_c === 'portable') {
            return new PortableEngine();
        }

        if ($engine_c !== null) {
            throw new I18nException("Unknown engine \"{$engine_c}\". Use `icu` or `portable`.");
        }

        if (IcuEngine::is_available()) {
            return new IcuEngine();
        }

        // No ext-intl. The portable engine answers for the locales it carries
        // and refuses the rest — which is a better first five minutes than a
        // library that will not construct at all.
        $portable = new PortableEngine();

        if (!$portable->knows_locale($locale_c)) {
            throw new I18nException(
                "ext-intl is not installed and \"{$locale_c}\" is not among the locales baked into "
                . 'the portable engine. Install php-intl, or run `ix i18n:bake` on a machine that '
                . 'has it to add this locale.'
            );
        }

        return $portable;
    }

    /** `icu` or `portable` — which one is answering. */
    public function engine_code(): string
    {
        return $this->engine->name_code();
    }

    /** A copy of this formatter driven by the other engine. */
    public function using_engine(string $engine_c): self
    {
        return new self($this->locale_c, $this->timezone_c, $engine_c);
    }

    /**
     * Refuse, with the reason, rather than answer badly.
     *
     * Collation, spellout and display names are the three things the portable
     * engine will not approximate: Swedish sorts Å/Ä/Ö after Z, and stripping
     * accents gives correct Italian and wrong Swedish. A confident wrong answer
     * is what this library removes, not what it adds.
     */
    private function require_capability(string $capability_c, string $what_c): void
    {
        if ($this->engine->supports($capability_c)) {
            return;
        }

        throw new I18nException(
            "{$what_c} needs ext-intl: the portable engine will not approximate it, because an "
            . 'approximation that disagrees with ICU only on the odd input is worse than a refusal. '
            . 'Install php-intl, or avoid this call when engine_code() is "portable".'
        );
    }

    public function locale_code(): string
    {
        return $this->locale_c;
    }

    public function in(string $locale_c): self
    {
        return new self($locale_c, $this->timezone_c, $this->engine->name_code());
    }

    public function with_timezone(string $timezone_c): self
    {
        return new self($this->locale_c, $timezone_c);
    }

    /**
     * `rtl` or `ltr`, for the `dir` attribute on the page.
     *
     *     <html lang="<?= H::e($f->locale_code()) ?>" dir="<?= H::e($f->direction_code()) ?>">
     */
    public function direction_code(): string
    {
        return Locale::direction_code($this->locale_c);
    }

    public function is_rtl(): bool
    {
        return Locale::is_rtl($this->locale_c);
    }

    // There is deliberately no list formatter here — "Rossi, Bianchi **e**
    // Verdi". PHP's ext-intl exposes none (`IntlListFormatter` does not exist on
    // the target build, checked), so the conjunction would have to be shipped by
    // us for every language this library claims to serve. That is shipping
    // translations, which relative_parts() already refuses for the same reason.
    //
    // The application has a catalogue and can say it in three lines:
    //
    //     $last = array_pop($names);
    //     echo $names === [] ? $last
    //        : implode(', ', $names) . ' ' . $t->get('list.and') . ' ' . $last;

    // -------------------------------------------------------------------------
    // Numbers
    // -------------------------------------------------------------------------

    /**
     * @param int|null $decimals_n null lets the locale decide
     */
    public function number(float $value, ?int $decimals_n = null): string
    {
        return $this->engine->format_number($this->locale_c, $value, ['decimals' => $decimals_n]);
    }

    public function integer(int $value): string
    {
        return (string) $this->number_formatter(NumberFormatter::DECIMAL)->format($value);
    }

    /**
     * @param string $currency_c ISO 4217: EUR, USD, CHF
     */
    public function currency(float $amount, string $currency_c): string
    {
        if (preg_match('~^[A-Z]{3}$~', $currency_c) !== 1) {
            throw new I18nException("\"{$currency_c}\" is not an ISO 4217 currency code.");
        }

        return $this->engine->format_currency($this->locale_c, $amount, $currency_c);
    }

    /**
     * An amount held as **integer minor units** — cents, pence, øre.
     *
     * The reason to have it: money in a float is a decision, and this makes the
     * other decision possible. `23 × 0.10` is `2.30000000000000071054` in IEEE
     * 754 — measured — and once such a value has been added a few times the
     * error is in the total, not in the display.
     *
     * The exponent comes from ICU, so it is right where it is not two: JPY has
     * no minor unit, TND has three.
     *
     *     $f->currency_minor(123450, 'EUR');   // 1.234,50 €
     *     $f->currency_minor(1000, 'JPY');     // ¥1.000  — not ¥10,00
     *
     * @param int $minor_n amount in the currency's smallest unit
     */
    public function currency_minor(int $minor_n, string $currency_c): string
    {
        // The exponent must come from the currency being formatted, not from the
        // locale's own. Reading FRACTION_DIGITS off a plain it-IT formatter gives
        // 2 — EUR's — so `currency_minor(1000, 'JPY')` printed "10 JPY" instead
        // of a thousand. Naming the currency first is what makes it answer for
        // that currency.
        $formatter = new NumberFormatter($this->locale_c, NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, strtoupper(trim($currency_c)));

        $digits_n = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        if ($digits_n === false || $digits_n < 0) {
            $digits_n = 2;
        }

        // Divide as an integer plus a remainder rather than by 10**n on a float:
        // the point of taking integer cents is not to hand them to a float on
        // the way out.
        $scale     = (int) (10 ** $digits_n);
        $units_n   = intdiv($minor_n, $scale);
        $remainder = abs($minor_n % $scale);

        $decimal = $digits_n === 0
            ? (string) $units_n
            : sprintf('%d.%0' . $digits_n . 'd', $units_n, $remainder);

        // A negative amount between -1 and 0 loses its sign to intdiv().
        if ($minor_n < 0 && $units_n === 0) {
            $decimal = '-' . $decimal;
        }

        return $this->currency((float) $decimal, $currency_c);
    }

    /**
     * A ratio as a percentage: `0.075` becomes `7,5%`.
     *
     * **The default matters.** ICU's own percent formatter uses zero fraction
     * digits, so `0.075` comes out as `8%` — measured. A rate quietly rounded
     * from 7.5 to 8 is the kind of wrong that reaches an invoice, so this keeps
     * up to three digits unless told otherwise.
     */
    public function percent(float $ratio, int $decimals_n = 3): string
    {
        $formatter = $this->number_formatter(NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals_n);

        return (string) $formatter->format($ratio);
    }

    /** `3` becomes `3º` in Italian, `3rd` in English. */
    public function ordinal(int $value): string
    {
        return (string) $this->number_formatter(NumberFormatter::ORDINAL)->format($value);
    }

    /** `42` becomes `quarantadue`. */
    public function spell(int $value): string
    {
        $this->require_capability('spellout', 'Spelling a number out in words');

        return (string) $this->number_formatter(NumberFormatter::SPELLOUT)->format($value);
    }

    /**
     * Bytes in units a person reads, using the locale's number format.
     *
     * Powers of 1024 with the IEC names, because a file manager reporting `1.0
     * KiB` for 1024 bytes and `1.0 kB` for 1000 is the ambiguity these names
     * exist to remove.
     */
    public function bytes(int $bytes_n, int $decimals_n = 1): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $value = (float) abs($bytes_n);
        $unit  = 0;

        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        $sign = $bytes_n < 0 ? '-' : '';

        return $sign . $this->number($value, $unit === 0 ? 0 : $decimals_n) . "\u{00A0}" . $units[$unit];
    }

    // -------------------------------------------------------------------------
    // Names
    // -------------------------------------------------------------------------

    /**
     * `DE` becomes `Germania` in Italian, `Germany` in English.
     *
     * The `und-` prefix is not decoration: ICU's `getDisplayRegion` expects a
     * *locale*, and handed a bare `DE` it returns an **empty string** rather
     * than an error — measured. Building `und-DE` ("undetermined language,
     * region DE") is what makes the lookup work.
     *
     * @param string $region_c ISO 3166-1 alpha-2, or a full locale
     */
    /**
     * "Rossi, Bianchi **e** Verdi" — a list, joined the way a language joins one.
     *
     * Not something to do with `implode()`, and the differences are not
     * cosmetic. English puts a comma before the final *and* for three items and
     * none for two. Most of Europe does not use the comma at all. A hand-rolled
     * join is right in exactly one language and quietly foreign in the rest —
     * and a page listing several co-authors or recipients concatenates names constantly.
     *
     * `$type_c` is `and` or `or`.
     *
     *     $f->list_of(['Rossi', 'Bianchi', 'Verdi']);        // Rossi, Bianchi e Verdi
     *     $f->list_of(['Rossi', 'Bianchi'], 'or');           // Rossi o Bianchi
     *
     * Works under both engines: PHP exposes no `ListFormatter`, so even with
     * ext-intl installed there is nothing to delegate to. The patterns come from
     * CLDR — see CREDITS.md.
     *
     * @param string[] $items
     */
    public function list_of(array $items, string $type_c = 'and'): string
    {
        $items = array_values(array_map('strval', $items));

        if ($type_c !== 'and' && $type_c !== 'or') {
            throw new I18nException("A list is joined with `and` or `or`, not \"{$type_c}\".");
        }

        // Nothing, one, and two are their own cases in CLDR, and collapsing
        // them into the general pattern gets two wrong in half of Europe.
        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $pattern = self::list_patterns($this->locale_c, $type_c);

        if (count($items) === 2) {
            return str_replace(['{0}', '{1}'], [$items[0], $items[1]], $pattern['2']);
        }

        // Built from the end backwards, which is how the three patterns compose:
        // start joins the first pair, middle every pair after it, end the last.
        $out = str_replace(
            ['{0}', '{1}'],
            [$items[count($items) - 2], $items[count($items) - 1]],
            $pattern['end']
        );

        for ($i = count($items) - 3; $i >= 1; $i--) {
            $out = str_replace(['{0}', '{1}'], [$items[$i], $out], $pattern['middle']);
        }

        return str_replace(['{0}', '{1}'], [$items[0], $out], $pattern['start']);
    }

    /**
     * @return array{start: string, middle: string, end: string, 2: string}
     */
    private static function list_patterns(string $locale_c, string $type_c): array
    {
        static $tables;

        if ($tables === null) {
            $path = __DIR__ . '/data/lists.php';

            if (!is_file($path)) {
                throw new I18nException('The list patterns are missing: ' . $path);
            }

            $tables = (array) require $path;
        }

        $normalised_c = str_replace('_', '-', $locale_c);
        $language_c   = strtolower((string) (preg_split('/[-_]/', $normalised_c)[0] ?? $normalised_c));

        $found = $tables[$normalised_c] ?? $tables[$language_c] ?? null;

        if ($found === null) {
            throw new I18nException(
                "No list patterns for \"{$locale_c}\". Joining with a comma and the English "
                . '"and" would read as a translation nobody finished.'
            );
        }

        return $found[$type_c];
    }

    public function region_name(string $region_c): string
    {
        $this->require_capability('display_names', 'Region names');

        $region_c = trim($region_c);

        if (preg_match('~^[A-Za-z]{2}$|^[0-9]{3}$~', $region_c) === 1) {
            $region_c = 'und-' . strtoupper($region_c);
        }

        $name = \Locale::getDisplayRegion($region_c, $this->locale_c);

        return $name === '' ? strtoupper(trim($region_c)) : $name;
    }

    /**
     * `de` becomes `tedesco` in Italian, `German` in English.
     *
     * @param string $language_c ISO 639, or a full locale (the region is ignored)
     */
    public function language_name(string $language_c): string
    {
        $this->require_capability('display_names', 'Language names');

        $name = \Locale::getDisplayLanguage(trim($language_c), $this->locale_c);

        return $name === '' ? strtolower(trim($language_c)) : $name;
    }

    /**
     * `EUR` becomes `euro` in Italian, `Euro` in German, `franco svizzero` for CHF.
     *
     * ICU has the table but PHP does not expose `ucurr_getName`, so the name is
     * obtained the only way available: the `¤¤¤` pattern renders a currency by
     * its long name, and the amount is then removed. One is used as the amount
     * because `¤¤¤` is plural-aware — with zero, English answers `euros`.
     */
    public function currency_name(string $currency_c): string
    {
        $this->require_capability('display_names', 'Currency names');

        $currency_c = strtoupper(trim($currency_c));

        if (preg_match('~^[A-Z]{3}$~', $currency_c) !== 1) {
            throw new I18nException("\"{$currency_c}\" is not an ISO 4217 currency code.");
        }

        $formatter = new NumberFormatter($this->locale_c, NumberFormatter::CURRENCY);
        $formatter->setPattern('¤¤¤');

        $rendered = (string) $formatter->formatCurrency(1, $currency_c);
        $one      = (string) $this->number_formatter(NumberFormatter::DECIMAL)->format(1);

        // The separator ICU puts between name and amount is a *non-breaking*
        // space, which trim() does not touch — so trimming ASCII whitespace
        // leaves "euro\u{00A0}" and every comparison against "euro" fails.
        $name = preg_replace('~^[\s\x{00A0}\x{202F}\x{2009}]+|[\s\x{00A0}\x{202F}\x{2009}]+$~u', '',
            str_replace($one, '', $rendered)) ?? '';

        // ICU falls back to the code itself for currencies it does not know.
        return $name === '' ? $currency_c : $name;
    }

    // -------------------------------------------------------------------------
    // Sorting
    // -------------------------------------------------------------------------

    /**
     * Compare two strings the way this language orders them.
     *
     * PHP's own comparison orders by byte, which puts every accented letter
     * after `Z` and is wrong in every language that has any. Measured on five
     * surnames:
     *
     *     sort() PHP     Ortiz, Zani, Ärling, Åberg, Özdemir
     *     Italian        Åberg, Ärling, Ortiz, Özdemir, Zani
     *     Swedish        Ortiz, Zani, Åberg, Ärling, Özdemir
     *
     * Italian and Swedish disagree, and **both are right**: in Swedish, Å, Ä
     * and Ö are letters in their own right and come after Z. No amount of
     * accent-stripping produces both answers, which is why this defers to ICU
     * rather than approximating.
     *
     * @return int negative, zero or positive — the usort() contract
     */
    public function compare(string $a, string $b): int
    {
        $this->require_capability('collation', 'Locale-aware comparison');

        $result = $this->collator()->compare($a, $b);

        return $result === false ? 0 : $result;
    }

    /**
     * A copy of the list, ordered for this locale.
     *
     * Returns rather than sorts in place: a function that quietly rearranges
     * its argument is a surprise, and the caller who wants that can assign.
     *
     * @param string[] $values
     * @return string[] renumbered from zero
     */
    public function sorted(array $values): array
    {
        $sorted = array_values($values);

        $this->collator()->sort($sorted);

        return $sorted;
    }

    /**
     * The same, for records: sort rows by one of their fields.
     *
     * Keys are preserved, so a row can still be found by the id it came with.
     *
     * @param array<int|string, mixed>        $rows
     * @param string|callable(mixed): string  $key field name, or a function returning the text
     * @return array<int|string, mixed>
     */
    public function sorted_by(array $rows, $key): array
    {
        $read = is_callable($key)
            ? $key
            : static function ($row) use ($key): string {
                if (is_array($row)) {
                    return (string) ($row[$key] ?? '');
                }

                if ($row instanceof \ArrayAccess) {
                    return (string) ($row[$key] ?? '');
                }

                return is_object($row) && isset($row->{$key}) ? (string) $row->{$key} : '';
            };

        // The keys are compared, not the rows, and each key is read once —
        // uasort() would otherwise call $read twice per comparison.
        $keys = [];

        foreach ($rows as $index => $row) {
            $keys[$index] = (string) $read($row);
        }

        uasort($keys, function (string $a, string $b): int {
            return $this->compare($a, $b);
        });

        $out = [];

        foreach (array_keys($keys) as $index) {
            $out[$index] = $rows[$index];
        }

        return $out;
    }

    private function collator(): \Collator
    {
        if ($this->collator === null) {
            $collator = \Collator::create($this->locale_c);

            if ($collator === null) {
                throw new I18nException("No collation is available for \"{$this->locale_c}\".");
            }

            $this->collator = $collator;
        }

        return $this->collator;
    }

    // -------------------------------------------------------------------------
    // Parsing — the direction that is usually missing
    // -------------------------------------------------------------------------

    /**
     * Read a number the way this locale writes one.
     *
     * The reason this exists at all: `(float) "1.234,50"` is **1.234** in PHP.
     * An Italian typing one thousand two hundred euros into a form gets one
     * euro and twenty-three cents, silently.
     *
     * And the reason it is not a one-line wrapper: **ICU stops at the first
     * character it cannot use and reports success**. Measured — `"12,5x"`
     * parses to `12.5` with `U_ZERO_ERROR`. So the parse position has to be
     * checked against the end of the input, or a typo becomes a number.
     *
     * That check compares **code points, not bytes**: ICU's position counts
     * characters, and Swiss and French groupings use multi-byte separators
     * (`1’234.50` is 10 bytes and 8 characters). Comparing `strlen()` would
     * reject every correctly-typed Swiss amount.
     */
    public function parse_number(string $input): Parsed
    {
        return $this->parse_with($input, NumberFormatter::DECIMAL, NumberFormatter::TYPE_DOUBLE);
    }

    /**
     * The same, forced to a whole number.
     *
     * Fails rather than truncating: `2,7` is not `2`, and deciding it is belongs
     * to the caller.
     */
    public function parse_integer(string $input): Parsed
    {
        $parsed = $this->parse_number($input);

        if (!$parsed->is_ok()) {
            return $parsed;
        }

        $value = $parsed->value();

        if ((float) (int) $value !== (float) $value) {
            return Parsed::fail(Parsed::TRAILING, $input);
        }

        return Parsed::ok((int) $value, $input);
    }

    /**
     * Read an amount with its currency: `1.234,50 €` gives 1234.5 and `EUR`.
     *
     * Note that the separator before the symbol is a **non-breaking space** in
     * Italian output. A string retyped with an ordinary space will not parse —
     * which is correct behaviour, not a bug to paper over: it is the caller's
     * decision whether to normalise whitespace before asking.
     */
    public function parse_currency(string $input): Parsed
    {
        if (trim($input) === '') {
            return Parsed::fail(Parsed::EMPTY_INPUT, $input);
        }

        $formatter  = new NumberFormatter($this->locale_c, NumberFormatter::CURRENCY);
        $currency_c = null;
        $position   = 0;

        $value = $formatter->parseCurrency($input, $currency_c, $position);

        if ($value === false) {
            return Parsed::fail(Parsed::UNREADABLE, $input);
        }

        if ($position !== mb_strlen($input, 'UTF-8')) {
            return Parsed::fail(Parsed::TRAILING, $input);
        }

        return Parsed::ok((float) $value, $input, is_string($currency_c) ? $currency_c : null);
    }

    /**
     * Read a date written the way this locale writes one.
     *
     * Returns a `DateTimeImmutable` in this formatter's timezone.
     *
     * @param string $style_c one of the class constants, matching how it was written
     */
    public function parse_date(string $input, string $style_c = self::SHORT): Parsed
    {
        return $this->parse_date_time($input, $style_c, self::NONE);
    }

    /**
     * @param string $date_style_c how the date part was written
     * @param string $time_style_c how the time part was written, or NONE
     */
    public function parse_date_time(
        string $input,
        string $date_style_c = self::SHORT,
        string $time_style_c = self::SHORT
    ): Parsed {
        if (trim($input) === '') {
            return Parsed::fail(Parsed::EMPTY_INPUT, $input);
        }

        foreach ([$date_style_c, $time_style_c] as $style_c) {
            if (!isset(self::STYLES[$style_c])) {
                throw new I18nException(
                    "\"{$style_c}\" is not a style. Use: " . implode(', ', array_keys(self::STYLES)) . '.'
                );
            }
        }

        $formatter = new IntlDateFormatter(
            $this->locale_c,
            self::STYLES[$date_style_c],
            self::STYLES[$time_style_c],
            $this->timezone_c
        );

        // Lenient parsing accepts "31/13/2026" by rolling over into the next
        // year. A date nobody wrote is worse than a rejected field.
        $formatter->setLenient(false);

        $position = 0;
        $stamp    = $formatter->parse($input, $position);

        if ($stamp === false) {
            return Parsed::fail(Parsed::UNREADABLE, $input);
        }

        if ($position !== mb_strlen($input, 'UTF-8')) {
            return Parsed::fail(Parsed::TRAILING, $input);
        }

        $when = (new DateTimeImmutable('@' . $stamp))
            ->setTimezone(new DateTimeZone($this->timezone_c));

        return Parsed::ok($when, $input);
    }

    private function parse_with(string $input, int $style_n, int $type_n): Parsed
    {
        if (trim($input) === '') {
            return Parsed::fail(Parsed::EMPTY_INPUT, $input);
        }

        $formatter = new NumberFormatter($this->locale_c, $style_n);
        $position  = 0;

        $value = $formatter->parse($input, $type_n, $position);

        if ($value === false) {
            return Parsed::fail(Parsed::UNREADABLE, $input);
        }

        if ($position !== mb_strlen($input, 'UTF-8')) {
            return Parsed::fail(Parsed::TRAILING, $input);
        }

        return Parsed::ok($value, $input);
    }

    // -------------------------------------------------------------------------
    // Dates
    // -------------------------------------------------------------------------

    /**
     * @param DateTimeInterface|int $when a datetime or a unix timestamp
     */
    public function date($when, string $style_c = self::MEDIUM): string
    {
        return $this->date_time($when, $style_c, self::NONE);
    }

    /**
     * @param DateTimeInterface|int $when
     */
    public function time($when, string $style_c = self::SHORT): string
    {
        return $this->date_time($when, self::NONE, $style_c);
    }

    /**
     * @param DateTimeInterface|int $when
     */
    public function date_time($when, string $date_style_c = self::MEDIUM, string $time_style_c = self::SHORT): string
    {
        foreach ([$date_style_c, $time_style_c] as $style_c) {
            if (!isset(self::STYLES[$style_c])) {
                throw new I18nException(
                    "\"{$style_c}\" is not a style. Use: " . implode(', ', array_keys(self::STYLES)) . '.'
                );
            }
        }

        // The engine names its styles by what they produce rather than by two
        // constants, because the portable side has to look one up in a table.
        $style_key_c = self::engine_style($date_style_c, $time_style_c);

        if ($style_key_c === null) {
            $this->require_capability('nothing', "The {$date_style_c}/{$time_style_c} date style");
        }

        return $this->engine->format_date(
            $this->locale_c,
            (new DateTimeImmutable('@' . $this->to_timestamp($when)))
                ->setTimezone(new DateTimeZone($this->timezone_c)),
            (string) $style_key_c,
            $this->timezone_c
        );
    }

    /** The style pair as the engine names it, or null when it is not baked. */
    private static function engine_style(string $date_style_c, string $time_style_c): ?string
    {
        $map = [
            self::SHORT  . '|' . self::NONE  => 'date_short',
            self::MEDIUM . '|' . self::NONE  => 'date_medium',
            self::LONG   . '|' . self::NONE  => 'date_long',
            self::NONE   . '|' . self::SHORT => 'time_short',
            self::NONE   . '|' . self::MEDIUM => 'time_medium',
            self::SHORT  . '|' . self::SHORT => 'datetime_short',
            self::MEDIUM . '|' . self::SHORT => 'datetime_medium',
        ];

        return $map[$date_style_c . '|' . $time_style_c] ?? null;
    }

    /**
     * A date in the calendar this locale traditionally uses.
     *
     *     (new Formatter('th-TH'))->date_traditional($d)   // 12 สิงหาคม 2569
     *     (new Formatter('fa-IR'))->date_traditional($d)   // ۲۱ مرداد ۱۴۰۵
     *     (new Formatter('it-IT'))->date_traditional($d)   // 12 ago 2026
     *
     * ## The trap this exists to close
     *
     * Without `IntlDateFormatter::TRADITIONAL` as the fifth constructor
     * argument, ICU formats in the **Gregorian** calendar and reports no error —
     * even when the locale explicitly names another one. Measured: the same
     * `th-TH` locale gives `2026` without it and `2569` with it.
     *
     * A wrong year that looks like a right year is the kind of failure nobody
     * notices until a Thai user does, which is why this is a method rather than
     * a note in a README.
     *
     * ## What "traditional" means, exactly
     *
     * The calendar **CLDR records as the locale's own**, which is not always the
     * historical one: for `ja-JP` it is Gregorian, and the imperial era needs
     * asking for by name —
     *
     *     (new Formatter('ja-JP'))->date_traditional($d)                   // 2026年8月12日
     *     (new Formatter('ja-JP-u-ca-japanese'))->date_traditional($d)     // 令和8年8月12日
     *
     * Measured, and stated here because the opposite is easy to assume.
     *
     * @param DateTimeInterface|int $when
     */
    public function date_traditional($when, string $style_c = self::LONG): string
    {
        $this->require_capability('traditional_calendars', 'Non-Gregorian calendars');

        if (!isset(self::STYLES[$style_c])) {
            throw new I18nException(
                "\"{$style_c}\" is not a style. Use: " . implode(', ', array_keys(self::STYLES)) . '.'
            );
        }

        $formatter = new IntlDateFormatter(
            $this->locale_c,
            self::STYLES[$style_c],
            IntlDateFormatter::NONE,
            $this->timezone_c,
            IntlDateFormatter::TRADITIONAL
        );

        $out = $formatter->format($this->to_timestamp($when));

        if ($out === false) {
            throw new I18nException('That date could not be formatted: ' . $formatter->getErrorMessage());
        }

        return $out;
    }

    /**
     * An ICU skeleton, when a style is not specific enough: `d MMMM y`.
     *
     * @param DateTimeInterface|int $when
     */
    public function pattern($when, string $pattern_c): string
    {
        $formatter = new IntlDateFormatter(
            $this->locale_c,
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
            $this->timezone_c,
            null,
            $pattern_c
        );

        $out = $formatter->format($this->to_timestamp($when));

        if ($out === false) {
            throw new I18nException('That pattern could not be applied: ' . $formatter->getErrorMessage());
        }

        return $out;
    }

    /**
     * How far apart two moments are, **as parts rather than as words**.
     *
     * PHP's `ext-intl` does not expose ICU's `RelativeDateTimeFormatter` — checked
     * on the target build, `IntlRelativeDateTimeFormatter` does not exist — so
     * the wording would have to be invented here. Inventing it would mean
     * shipping translations for every language this library claims to support,
     * which is a promise it cannot keep.
     *
     * So this computes and the application words it, with its own catalogue:
     *
     *     $parts = $formatter->relative_parts($then, $now);
     *     $t->choice('time.' . ($parts['is_past'] ? 'past' : 'future') . '.' . $parts['unit_c'],
     *                $parts['count_n']);
     *
     *     // 'time.past.day' => '{n, plural, one {# giorno fa} other {# giorni fa}}'
     *
     * @param DateTimeInterface|int      $when
     * @param DateTimeInterface|int|null $now
     *
     * @return array{unit_c: string, count_n: int, is_past: bool, seconds_n: int}
     */
    public function relative_parts($when, $now = null): array
    {
        $this->require_capability('relative', 'Relative time');

        $then_t  = $this->to_timestamp($when);
        $now_t   = $now === null ? time() : $this->to_timestamp($now);
        $delta_n = $then_t - $now_t;
        $abs_n   = abs($delta_n);

        $ladder = [
            ['second', 60],
            ['minute', 3600],
            ['hour',   86400],
            ['day',    604800],
            ['week',   2629746],     // the mean Gregorian month, in seconds
            ['month',  31556952],    // the mean Gregorian year
        ];

        $unit_c = 'year';
        $size_n = 31556952;

        $previous = 1;

        foreach ($ladder as [$name, $limit]) {
            if ($abs_n < $limit) {
                $unit_c = $name;
                $size_n = $previous;

                break;
            }

            $previous = $limit;
        }

        // Mean month and year lengths, not calendar ones: "2 months ago" is a
        // human approximation and computing it against real month boundaries
        // would make it change depending on which months were crossed.
        $sizes = [
            'second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400,
            'week' => 604800, 'month' => 2629746, 'year' => 31556952,
        ];

        $size_n = $sizes[$unit_c];

        return [
            'unit_c'    => $unit_c,
            'count_n'   => (int) floor($abs_n / $size_n),
            'is_past'   => $delta_n < 0,
            'seconds_n' => $delta_n,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function number_formatter(int $style): NumberFormatter
    {
        $key = (string) $style;

        if (!isset($this->numbers[$key])) {
            $formatter = NumberFormatter::create($this->locale_c, $style);

            if ($formatter === null) {
                throw new I18nException("No number formatter for \"{$this->locale_c}\".");
            }

            $this->numbers[$key] = $formatter;
        }

        return $this->numbers[$key];
    }

    /**
     * @param DateTimeInterface|int $when
     */
    private function to_timestamp($when): int
    {
        if ($when instanceof DateTimeInterface) {
            return $when->getTimestamp();
        }

        if (is_int($when)) {
            return $when;
        }

        throw new I18nException('A moment must be a DateTimeInterface or a unix timestamp.');
    }
}
