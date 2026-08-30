<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Engine
 *
 * @package Italix\I18n
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\I18n;

use DateTimeInterface;

/**
 * The seam between "what this library promises" and "who does the work".
 *
 * Two implementations: {@see IcuEngine}, which asks ext-intl, and
 * {@see PortableEngine}, which reads tables baked from ICU on a machine that
 * had it. The choice is made once, at construction, and can be forced.
 *
 * ## Why a seam at all
 *
 * `ext-intl` is not in the official `php:8.x` images. Requiring it is the
 * friction everybody meets in the first five minutes of trying this library,
 * and it is the one place a comparable framework does better. But a fallback
 * that quietly answers *differently* would be worse than the requirement: the
 * same catalogue would render one way on the author's machine and another on
 * the server, and nobody would think to look.
 *
 * So the contract is narrow on purpose. Everything here is something the
 * portable engine can do **identically**, proved by a differential test that
 * runs both engines over the same corpus and compares byte for byte. What it
 * cannot do identically it refuses, through {@see supports()}, rather than
 * approximating.
 *
 * ## What is outside the seam, and why
 *
 * | | why |
 * |---|---|
 * | **collation** | Swedish sorts Å/Ä/Ö *after* Z. Stripping accents gives correct Italian and wrong Swedish — a confident wrong answer, which is what this library removes rather than adds |
 * | **spellout** | "milleduecentotrentaquattro" is a data product per language, not a table |
 * | **display names** | country and language names in 24 languages, likewise |
 * | **full MessageFormat** | nested plural/select, `#`, and above all the apostrophe rule: to ICU `'` is a quoting character. A parser that treats it even slightly differently produces **different output from the same catalogue** depending on whether the extension is installed. That is the worst failure available here |
 *
 * The portable engine therefore handles the plural and select forms that a
 * catalogue actually uses, and hands anything more complicated back as
 * unsupported.
 */
interface Engine
{
    /** `'icu'` or `'portable'` — what `Formatter::engine_code()` reports. */
    public function name_code(): string;

    /**
     * Can this engine answer that kind of question at all?
     *
     * Capabilities: `messages`, `numbers`, `dates`, `collation`, `spellout`,
     * `display_names`, `relative`, `parsing`, `traditional_calendars`.
     */
    public function supports(string $capability_c): bool;

    /** Is there a table for this locale, or only for its language? */
    public function knows_locale(string $locale_c): bool;

    /**
     * The CLDR plural category for a number: `one`, `few`, `other`…
     *
     * The property that makes a catalogue portable. Everything else in a
     * message can be substituted with `str_replace`; this cannot, because it is
     * a fact about a language.
     */
    public function plural_category_code(string $locale_c, $count): string;

    /**
     * Fill an ICU message. `$params` is name => value.
     *
     * @param array<string, mixed> $params
     * @throws I18nException when the message uses a construct this engine
     *                       cannot honour — never a silent approximation
     */
    public function format_message(string $locale_c, string $message, array $params): string;

    /**
     * @param array{decimals?: int|null, grouping?: bool} $options
     */
    public function format_number(string $locale_c, float $value, array $options = []): string;

    /**
     * @param array{decimals?: int|null} $options
     */
    public function format_currency(string $locale_c, float $value, string $currency_c, array $options = []): string;

    /** How many minor units the currency has: 2 for EUR, 0 for JPY. */
    public function currency_digits_n(string $currency_c): int;

    /**
     * A number as written in this locale, back to a float.
     *
     * Returns null when the input is not a clean number *in full* — trailing
     * rubbish is a failure, not something to ignore.
     */
    public function parse_number(string $locale_c, string $input): ?float;

    /**
     * @param string $style_c `date_short`, `date_medium`, `date_long`,
     *                        `time_short`, `time_medium`, `datetime_short`,
     *                        `datetime_medium`
     */
    public function format_date(string $locale_c, DateTimeInterface $when, string $style_c, string $timezone_c): string;
}
