<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Slug
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * Text into a URL slug, in the language the URL is in.
 *
 *     Slug::of('Grönland', 'de')            // 'groenland'  — German writes ö as oe
 *     Slug::of('Grönland', 'it')            // 'gronland'
 *     Slug::of('Peter Høeg', 'it')          // 'peter-hoeg'
 *     Slug::of('Ærø', 'da')                 // 'aeroe'      — Danish writes ø as oe
 *     Slug::of("Dell'onda & altro", 'it')   // 'dell-onda-e-altro'
 *     Slug::of('Москва', 'en')              // 'moskva'     — with ext-intl
 *
 * Three steps. The language's own spelling of its letters first — the only
 * part that depends on the language, and why a slug takes a locale at all.
 * Then everything else to ASCII as ICU's `Any-Latin; Latin-ASCII` does it.
 * Then lower case, and every run of anything that is not a letter or a digit
 * one hyphen — apostrophes included: `dell'onda` is `dell-onda`.
 *
 * ICU is used when ext-intl is there. Without it a table baked from ICU covers
 * the Latin script (every European language); any other script is refused with
 * an exception naming the extension, rather than dropped into an empty slug.
 */
final class Slug
{
    /** Where bake() writes, relative to this library. */
    public const PATH = 'data/latin-ascii.php';

    /** The Latin blocks the baked table covers: Latin-1, Extended-A and -B, Extended Additional. */
    private const LATIN_BLOCKS = [[0x00A0, 0x024F], [0x1E00, 0x1EFF]];

    /** Letters a language spells its own way, before the general rule. */
    private const LANGUAGE_LETTERS = [
        'de' => ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', 'ẞ' => 'SS'],
        'da' => ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa'],
        'nb' => ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa'],
        'nn' => ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa'],
        'no' => ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa'],
    ];

    /** `&` as a word, by language. A language not listed makes it a hyphen. */
    private const AND_WORD = [
        'it' => 'e', 'en' => 'and', 'de' => 'und', 'fr' => 'et', 'es' => 'y', 'pt' => 'e', 'nl' => 'en',
        'da' => 'og', 'nb' => 'og', 'nn' => 'og', 'no' => 'og', 'sv' => 'och', 'fi' => 'ja', 'pl' => 'i',
        'cs' => 'a', 'sk' => 'a', 'ro' => 'si', 'hr' => 'i', 'sl' => 'in', 'hu' => 'es', 'el' => 'kai',
    ];

    /** @var array<string, string>|null */
    private static ?array $table = null;

    private function __construct()
    {
    }

    /**
     * @param string    $locale_c the URL's language — `it`, `de-CH`; '' applies no language's spelling
     * @param int       $max_n    longest slug, cut at a hyphen when one is near enough; 0 for no limit
     * @param bool|null $use_intl null: ICU when available. false forces the baked table, which is how the
     *                            portable branch gets tested on a machine that has intl
     * @throws I18nException a letter outside the Latin script without ext-intl
     */
    public static function of(string $text, string $locale_c = '', int $max_n = 0, ?bool $use_intl = null): string
    {
        $use_intl = $use_intl ?? self::has_intl();
        $language = $locale_c === '' ? '' : Locale::language_of($locale_c);

        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        $text = strtr($text, self::LANGUAGE_LETTERS[$language] ?? []);
        $text = str_replace('&', ' ' . (self::AND_WORD[$language] ?? '') . ' ', $text);

        if ($use_intl) {
            $ascii = (string) \Transliterator::create('Any-Latin; Latin-ASCII')->transliterate($text);
        } else {
            $ascii = strtr($text, self::table());

            if (preg_match('/(?![\x00-\x7F])[\p{L}\p{N}]/u', $ascii) === 1) {
                throw new I18nException(
                    'Slug::of() needs ext-intl for letters outside the Latin script: "' . $text . '".'
                );
            }
        }

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        if ($max_n > 0 && strlen($slug) > $max_n) {
            $cut  = substr($slug, 0, $max_n + 1);
            $at   = strrpos($cut, '-');
            $slug = rtrim($at !== false && $at >= (int) ($max_n * 0.6) ? substr($cut, 0, $at) : substr($slug, 0, $max_n), '-');
        }

        return $slug;
    }

    /** Whether of() can ask ICU. */
    public static function has_intl(): bool
    {
        return class_exists(\Transliterator::class) && \Transliterator::create('Any-Latin; Latin-ASCII') !== null;
    }

    /**
     * The portable table, from ICU: every assigned character of the Latin
     * blocks that is not ASCII, as the ASCII `Latin-ASCII` makes of it. What `ix i18n:bake` writes to PATH, and what
     * SlugTest compares the shipped table with.
     *
     * @return array<string, string>
     */
    public static function bake(): array
    {
        if (!self::has_intl()) {
            throw new I18nException('Baking the slug table needs ext-intl.');
        }

        $latin = \Transliterator::create('Latin-ASCII');
        $table = [];

        foreach (self::LATIN_BLOCKS as [$from, $to]) {
            for ($cp = $from; $cp <= $to; $cp++) {
                $char = \IntlChar::chr($cp);

                if ($char === null || !\IntlChar::isdefined($cp)) {
                    continue;
                }

                // What a slug makes of it: Latin-ASCII leaves some characters non-ASCII (Ǯ is Ʒ, ¡ is ¡), and of()
                // turns what is left into a separator — so does the table, with a space.
                $ascii = (string) preg_replace('/[^\x00-\x7F]+/u', ' ', (string) $latin->transliterate($char));

                if ($ascii !== $char) {
                    $table[$char] = $ascii;
                }
            }
        }

        return $table;
    }

    /** The file at PATH, as `ix i18n:bake` writes it. */
    public static function render(): string
    {
        $lines = '';
        foreach (self::bake() as $char => $ascii) {
            $lines .= '    ' . var_export($char, true) . ' => ' . var_export($ascii, true) . ",\n";
        }

        return <<<PHP
<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

// GENERATED by `ix i18n:bake` — do not edit.
//
// ICU's `Latin-ASCII` for every letter of the Latin blocks it changes: what
// Slug::of() uses when ext-intl is not there.

return [
{$lines}];

PHP;
    }

    /** Whether the table at `$path` is what this ICU would write. */
    public static function is_current(string $path): bool
    {
        return is_file($path) && trim((string) file_get_contents($path)) === trim(self::render());
    }

    /** @return array<string, string> */
    private static function table(): array
    {
        if (self::$table === null) {
            $file = __DIR__ . '/' . self::PATH;

            if (!is_file($file)) {
                throw new I18nException('The slug table ' . self::PATH . ' is missing: run `ix i18n:bake` on a machine with ext-intl.');
            }

            self::$table = require $file;
        }

        return self::$table;
    }
}
