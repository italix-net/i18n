<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Locale
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * A BCP-47 tag, and the chain to fall back along.
 *
 * Everything here is static because a locale is a string with rules, not an
 * object with state — and making it an object would mean every caller converting
 * back and forth at the boundary with ICU, which takes strings.
 */
final class Locale
{
    private function __construct()
    {
    }

    /**
     * `it_IT`, ` IT-it `, `it-it` all become `it-IT`.
     *
     * Underscores are accepted because that is what PHP's own locale functions
     * and half the world's configuration files produce, and refusing them would
     * be pedantry rather than safety.
     */
    public static function normalise(string $locale_c): string
    {
        $locale_c = str_replace('_', '-', trim($locale_c));

        if ($locale_c === '') {
            throw new I18nException('A locale cannot be empty.');
        }

        $parts = explode('-', $locale_c);

        if (preg_match('~^[A-Za-z]{2,3}$~', $parts[0]) !== 1) {
            throw new I18nException("\"{$locale_c}\" does not start with a language subtag.");
        }

        $out = [strtolower($parts[0])];

        // A single-character subtag is a BCP-47 *singleton*: `u` opens Unicode
        // extensions, `t` transforms, `x` private use. Everything after one is
        // extension content and must be left alone — read as ordinary subtags,
        // `ja-JP-u-ca-japanese` turns `ca` into a region and comes back as
        // `ja-JP-u-CA-japanese`, naming Canada in a request for the Japanese
        // calendar. Measured before this branch existed.
        $in_extension = false;

        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('~^[A-Za-z0-9]$~', $part) === 1) {
                $in_extension = true;
                $out[]        = strtolower($part);

                continue;
            }

            if ($in_extension) {
                if (preg_match('~^[A-Za-z0-9]{1,8}$~', $part) !== 1) {
                    throw new I18nException("\"{$part}\" is not a usable subtag in \"{$locale_c}\".");
                }

                $out[] = strtolower($part);

                continue;
            }

            if (preg_match('~^[A-Za-z]{4}$~', $part) === 1) {
                $out[] = ucfirst(strtolower($part));           // script: Latn
            } elseif (preg_match('~^([A-Za-z]{2}|[0-9]{3})$~', $part) === 1) {
                $out[] = strtoupper($part);                    // region: IT, 419
            } elseif (preg_match('~^[A-Za-z0-9]{1,8}$~', $part) === 1) {
                $out[] = strtolower($part);                    // variant
            } else {
                throw new I18nException("\"{$part}\" is not a usable subtag in \"{$locale_c}\".");
            }
        }

        return implode('-', $out);
    }

    /**
     * Scripts written right to left, by ISO 15924 code.
     *
     * Direction is a property of the **script**, not of the language: Azerbaijani
     * is left-to-right in Latin and right-to-left in Arabic, and the same holds
     * for Kurdish and Uzbek. A locale that names its script therefore answers
     * exactly, and only one that does not falls back to the language table below.
     */
    private const RTL_SCRIPTS = ['Arab', 'Hebr', 'Thaa', 'Syrc', 'Nkoo', 'Adlm', 'Rohg', 'Mand', 'Samr'];

    /**
     * Languages whose customary script is right to left, when none is stated.
     *
     * A closed, slow-moving list rather than a guess: these are the living
     * languages with an established RTL orthography.
     */
    private const RTL_LANGUAGES = [
        'ar',  // Arabic
        'arc', // Aramaic
        'ckb', // Central Kurdish
        'dv',  // Divehi
        'fa',  // Persian
        'ha',  // Hausa (Ajami)
        'he',  // Hebrew
        'khw', // Khowar
        'ks',  // Kashmiri
        'ps',  // Pashto
        'sd',  // Sindhi
        'ug',  // Uyghur
        'ur',  // Urdu
        'yi',  // Yiddish
    ];

    /**
     * Whether this locale is written right to left.
     *
     * For `dir="rtl"` on the page, and for anything that lays out rather than
     * merely prints. PHP's `ext-intl` exposes no direction of its own — checked
     * on the target build — so the answer comes from the script when the locale
     * names one, and from a language list when it does not.
     *
     *     Locale::is_rtl('ar')        // true
     *     Locale::is_rtl('az-Arab')   // true
     *     Locale::is_rtl('az-Latn')   // false — same language, other script
     */
    public static function is_rtl(string $locale_c): bool
    {
        $normalised = self::normalise($locale_c);

        foreach (array_slice(explode('-', $normalised), 1) as $part) {
            if (preg_match('~^[A-Z][a-z]{3}$~', $part) === 1) {
                return in_array($part, self::RTL_SCRIPTS, true);
            }
        }

        return in_array(self::language_of($normalised), self::RTL_LANGUAGES, true);
    }

    /** `rtl` or `ltr`, ready for a `dir` attribute. */
    public static function direction_code(string $locale_c): string
    {
        return self::is_rtl($locale_c) ? 'rtl' : 'ltr';
    }

    public static function is_valid(string $locale_c): bool
    {
        try {
            self::normalise($locale_c);

            return true;
        } catch (I18nException $e) {
            return false;
        }
    }

    /** `it-IT` -> `it` */
    public static function language_of(string $locale_c): string
    {
        return explode('-', self::normalise($locale_c))[0];
    }

    /** `it-IT` -> `IT`, `it` -> null */
    public static function region_of(string $locale_c): ?string
    {
        foreach (array_slice(explode('-', self::normalise($locale_c)), 1) as $part) {
            if (preg_match('~^([A-Z]{2}|[0-9]{3})$~', $part) === 1) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Most specific first, ending at the fallback: `it-IT` -> `it` -> `en`.
     *
     * A missing translation should degrade to a *related* language before it
     * degrades to the default one — an Austrian visitor is better served by
     * German than by English.
     *
     * @return string[]
     */
    public static function chain(string $locale_c, string $fallback_c = ''): array
    {
        $locale_c = self::normalise($locale_c);
        $parts    = explode('-', $locale_c);
        $chain    = [];

        while ($parts !== []) {
            $chain[] = implode('-', $parts);
            array_pop($parts);
        }

        if ($fallback_c !== '') {
            foreach (self::chain($fallback_c) as $step) {
                if (!in_array($step, $chain, true)) {
                    $chain[] = $step;
                }
            }
        }

        return $chain;
    }

    /**
     * The best of `$available` for `$wanted`, or null.
     *
     * @param string[] $available
     */
    public static function best_of(string $wanted_c, array $available): ?string
    {
        $normalised = [];

        foreach ($available as $one) {
            if (self::is_valid($one)) {
                $normalised[strtolower(self::normalise($one))] = self::normalise($one);
            }
        }

        foreach (self::chain($wanted_c) as $step) {
            if (isset($normalised[strtolower($step)])) {
                return $normalised[strtolower($step)];
            }
        }

        // Nothing matched exactly; accept a regional variant of the same
        // language — `it-CH` is a better answer for `it` than nothing at all.
        $language = self::language_of($wanted_c);

        foreach ($normalised as $one) {
            if (self::language_of($one) === $language) {
                return $one;
            }
        }

        return null;
    }

    /**
     * Parse an `Accept-Language` header, best first.
     *
     * @return string[]
     */
    public static function from_header(string $header): array
    {
        $weighted = [];

        foreach (explode(',', $header) as $i => $part) {
            $bits    = explode(';', trim($part));
            $tag     = trim($bits[0]);
            $quality = 1.0;

            foreach (array_slice($bits, 1) as $bit) {
                if (strncasecmp(trim($bit), 'q=', 2) === 0) {
                    $quality = (float) substr(trim($bit), 2);
                }
            }

            if ($tag === '' || $tag === '*' || !self::is_valid($tag)) {
                continue;
            }

            // The index keeps the header's own order among equal weights, which
            // is what the specification asks for and what sort() would destroy.
            $weighted[] = ['tag' => self::normalise($tag), 'q' => $quality, 'i' => $i];
        }

        usort($weighted, static function (array $a, array $b): int {
            return $b['q'] <=> $a['q'] ?: $a['i'] <=> $b['i'];
        });

        return array_column($weighted, 'tag');
    }
}
