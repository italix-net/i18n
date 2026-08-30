<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Catalog
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * The messages for one locale, flattened to dot paths.
 *
 * Translation files are written nested because that is how people think —
 * `['admin' => ['users' => ['title' => '…']]]` — and read flat because that is
 * how they are looked up. Flattening once at load is a few microseconds; walking
 * a nested array on every `get()` is a few microseconds *per call*, on a page
 * that makes hundreds.
 *
 * Immutable: `merge()` returns a new catalogue. A catalogue handed to a
 * translator cannot then be changed by whoever else holds a reference to it.
 */
final class Catalog
{
    private string $locale_c;

    /** @var array<string, string> dot path => message */
    private array $messages;

    /**
     * @param array<string, mixed> $messages nested or already flat
     */
    public function __construct(string $locale_c, array $messages = [])
    {
        $this->locale_c = Locale::normalise($locale_c);
        $this->messages = self::flatten($messages);
    }

    public function locale_code(): string
    {
        return $this->locale_c;
    }

    public function has(string $key_c): bool
    {
        return isset($this->messages[$key_c]);
    }

    public function get(string $key_c): ?string
    {
        return $this->messages[$key_c] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->messages);
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * A new catalogue with the other's messages on top.
     *
     * Used to layer an application's overrides over a package's defaults, so the
     * later one wins — which is the only ordering anybody ever wants.
     */
    /**
     * A copy of this catalogue, deliberately mangled, for finding what is not
     * translated at all.
     *
     *     'Salva le modifiche'  ->  '⟦Šàľvê ľê mõđíƒíçhê ····⟧'
     *
     * Three things fall out of a page rendered in it, and no other technique
     * finds all three:
     *
     *   - **a string that reads normally was never translated** — it came from
     *     the source, not from the catalogue;
     *   - **text that overflows** shows which layouts assume English lengths.
     *     German and Finnish routinely run 30% longer, and the padding here
     *     simulates that before a translator is paid to discover it;
     *   - **a truncated bracket** marks a string that was cut, since every
     *     message is fenced by `⟦` and `⟧`.
     *
     * ICU has no part in this. `en-XA` is a CLDR *seed* pseudo-locale and is not
     * in the standard data — measured, ICU falls back to plain English — so the
     * transformation is ours and is applied to our own source text. That is not
     * inventing a translation: it is defacing one on purpose.
     *
     * **Placeholders, ICU constructs and markup are left alone.** Accenting
     * `{count}` would break the message it is meant to test.
     */
    public function pseudo(string $locale_c = 'en-XA'): self
    {
        $out = [];

        foreach ($this->messages as $key_c => $message) {
            $out[$key_c] = self::deface((string) $message);
        }

        $copy           = new self($locale_c, []);
        $copy->messages = $out;

        return $copy;
    }

    /**
     * Accent the letters, pad by ~30%, fence the whole thing.
     *
     * Everything between braces is skipped: a placeholder, a plural branch or an
     * ICU argument must survive intact or the message under test stops being a
     * message.
     */
    private static function deface(string $message): string
    {
        static $map = [
            'a' => 'à', 'b' => 'ƀ', 'c' => 'ç', 'd' => 'đ', 'e' => 'ê', 'f' => 'ƒ', 'g' => 'ĝ',
            'h' => 'h', 'i' => 'í', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ľ', 'm' => 'm', 'n' => 'ñ',
            'o' => 'õ', 'p' => 'þ', 'q' => 'q', 'r' => 'ŕ', 's' => 'š', 't' => 'ť', 'u' => 'ü',
            'v' => 'v', 'w' => 'ŵ', 'x' => 'x', 'y' => 'ý', 'z' => 'ž',
            'A' => 'Á', 'B' => 'Ɓ', 'C' => 'Ç', 'D' => 'Đ', 'E' => 'É', 'F' => 'Ƒ', 'G' => 'Ĝ',
            'H' => 'Ĥ', 'I' => 'Í', 'J' => 'Ĵ', 'K' => 'Ķ', 'L' => 'Ľ', 'M' => 'M', 'N' => 'Ñ',
            'O' => 'Õ', 'P' => 'Þ', 'Q' => 'Q', 'R' => 'Ŕ', 'S' => 'Š', 'T' => 'Ť', 'U' => 'Ü',
            'V' => 'V', 'W' => 'Ŵ', 'X' => 'X', 'Y' => 'Ý', 'Z' => 'Ž',
        ];

        $out       = '';
        $depth_n   = 0;
        $letters_n = 0;

        foreach (preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === '{') {
                $depth_n++;
            } elseif ($char === '}') {
                $depth_n = max(0, $depth_n - 1);
            }

            if ($depth_n > 0 || $char === '}') {
                $out .= $char;
                continue;
            }

            if (isset($map[$char])) {
                $letters_n++;
                $out .= $map[$char];
                continue;
            }

            $out .= $char;
        }

        // Roughly a third longer, which is what German and Finnish cost.
        $padding_n = (int) ceil($letters_n * 0.3);

        return '⟦' . $out . ($padding_n > 0 ? ' ' . str_repeat('·', $padding_n) : '') . '⟧';
    }

    public function merge(self $other): self
    {
        $merged = new self($this->locale_c);
        $merged->messages = array_merge($this->messages, $other->messages);

        return $merged;
    }

    /**
     * `['a' => ['b' => 'x']]` becomes `['a.b' => 'x']`.
     *
     * An empty array under a key disappears rather than becoming a key with no
     * message: a translator who left a section unfinished should show up as a
     * *missing* key, which is reportable, not as a key whose value is `Array`.
     *
     * @param array<string, mixed> $messages
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix_c = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $key_c = $prefix_c === '' ? (string) $key : $prefix_c . '.' . $key;

            if (is_array($value)) {
                foreach (self::flatten($value, $key_c) as $nested_key => $nested) {
                    $flat[$nested_key] = $nested;
                }

                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $flat[$key_c] = (string) $value;

                continue;
            }

            // Deliberately NOT `is_scalar()`, which is true for booleans: PHP
            // casts `true` to "1" and `false` to "", so a stray boolean in a
            // language file becomes a message that renders as `1` or as nothing
            // at all, and reaches production looking like a translation.
            // Measured — the first version of this used `is_scalar()` and a test
            // caught it.
            throw new I18nException(
                "The message at \"{$key_c}\" is a " . gettype($value) . ', which cannot be a message.'
            );
        }

        return $flat;
    }
}
