<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - IcuEngine
 *
 * @package Italix\I18n
 * @license MPL-2.0
 */

declare(strict_types=1);

namespace Italix\I18n;

use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;

/**
 * The engine that asks ext-intl — the reference, and the one that defines
 * what "right" means for the other.
 *
 * Nothing here is clever. It is deliberately the thinnest possible wrapper,
 * because its output is the yardstick the portable engine is measured against:
 * anything decided in *this* file rather than in ICU would be a difference the
 * differential test could not see.
 */
final class IcuEngine implements Engine
{
    private const STYLES = [
        'date_short'      => [IntlDateFormatter::SHORT,  IntlDateFormatter::NONE],
        'date_medium'     => [IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE],
        'date_long'       => [IntlDateFormatter::LONG,   IntlDateFormatter::NONE],
        'time_short'      => [IntlDateFormatter::NONE,   IntlDateFormatter::SHORT],
        'time_medium'     => [IntlDateFormatter::NONE,   IntlDateFormatter::MEDIUM],
        'datetime_short'  => [IntlDateFormatter::SHORT,  IntlDateFormatter::SHORT],
        'datetime_medium' => [IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT],
    ];

    public static function is_available(): bool
    {
        return class_exists(MessageFormatter::class) && class_exists(NumberFormatter::class);
    }

    public function name_code(): string
    {
        return 'icu';
    }

    public function supports(string $capability_c): bool
    {
        // ICU does all of it. The list is spelled out rather than answered
        // `true` so that a capability added later has to be considered here
        // instead of silently claimed.
        return in_array($capability_c, [
            'messages', 'numbers', 'dates', 'collation', 'spellout',
            'display_names', 'relative', 'parsing', 'traditional_calendars',
        ], true);
    }

    public function knows_locale(string $locale_c): bool
    {
        return true;
    }

    public function plural_category_code(string $locale_c, $count): string
    {
        $formatter = MessageFormatter::create(
            $locale_c,
            '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}'
        );

        if ($formatter === null) {
            throw new I18nException("ICU could not build a plural formatter for \"{$locale_c}\".");
        }

        $out = $formatter->format(['n' => $count]);

        if ($out === false) {
            throw new I18nException("ICU could not classify that count for \"{$locale_c}\".");
        }

        return (string) $out;
    }

    /** @param array<string, mixed> $params */
    public function format_message(string $locale_c, string $message, array $params): string
    {
        $formatter = MessageFormatter::create($locale_c, $message);

        if ($formatter === null) {
            throw new I18nException('unparseable: ' . (string) intl_get_error_message());
        }

        $out = $formatter->format($params);

        if ($out === false) {
            throw new I18nException('could not be filled in: ' . $formatter->getErrorMessage());
        }

        return (string) $out;
    }

    public function format_number(string $locale_c, float $value, array $options = []): string
    {
        $formatter = new NumberFormatter($locale_c, NumberFormatter::DECIMAL);

        if (array_key_exists('decimals', $options) && $options['decimals'] !== null) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, (int) $options['decimals']);
        }

        if (($options['grouping'] ?? true) === false) {
            $formatter->setAttribute(NumberFormatter::GROUPING_USED, 0);
        }

        return (string) $formatter->format($value);
    }

    public function format_currency(string $locale_c, float $value, string $currency_c, array $options = []): string
    {
        $formatter = new NumberFormatter($locale_c, NumberFormatter::CURRENCY);

        if (array_key_exists('decimals', $options) && $options['decimals'] !== null) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, (int) $options['decimals']);
        }

        return (string) $formatter->formatCurrency($value, strtoupper(trim($currency_c)));
    }

    public function currency_digits_n(string $currency_c): int
    {
        // The exponent must come from the currency being formatted, not from
        // the locale's own: an it-IT formatter asked about JPY must answer 0.
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, strtoupper(trim($currency_c)));

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    public function parse_number(string $locale_c, string $input): ?float
    {
        $formatter = new NumberFormatter($locale_c, NumberFormatter::DECIMAL);
        $position  = 0;

        $value = $formatter->parse($input, NumberFormatter::TYPE_DOUBLE, $position);

        if ($value === false) {
            return null;
        }

        // ICU's position counts **code points**, not bytes. Comparing against
        // strlen() rejects every correctly typed Swiss amount, whose grouping
        // separator is a multi-byte apostrophe.
        if ($position !== mb_strlen($input, 'UTF-8')) {
            return null;
        }

        return (float) $value;
    }

    public function format_date(string $locale_c, DateTimeInterface $when, string $style_c, string $timezone_c): string
    {
        if (!isset(self::STYLES[$style_c])) {
            throw new I18nException("Unknown date style \"{$style_c}\".");
        }

        [$date_n, $time_n] = self::STYLES[$style_c];

        $formatter = new IntlDateFormatter(
            $locale_c,
            $date_n,
            $time_n,
            new DateTimeZone($timezone_c),
            IntlDateFormatter::GREGORIAN
        );

        return (string) $formatter->format($when);
    }
}
