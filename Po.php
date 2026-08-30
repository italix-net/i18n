<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Po
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * GNU gettext PO — thirty years old, and still what Poedit opens.
 *
 * Offered next to {@see Xliff} because some translators and some pipelines want
 * nothing else, and refusing the format they own is refusing the translator.
 *
 * ## The adaptation, and why it is safe
 *
 * PO identifies a message by its **source text**, in `msgid`. Ours are
 * identified by a key, and mapping keys onto `msgid` would mean that correcting
 * a comma in the original orphans every translation of that string.
 *
 * So the key goes in `msgctxt`, which gettext defines precisely for
 * disambiguation, and the source text stays in `msgid` where a translator
 * expects to read it. Every PO tool understands `msgctxt`; nothing has to know
 * that we use it for identity rather than for context.
 *
 * What is carried:
 *
 *     #. a note for the translator          -> notes
 *     #: src/Views/pages/post.php:41         -> references
 *     #, fuzzy                              -> needs-review
 *     #~ msgid "…"                          -> obsolete
 */
final class Po
{
    /**
     * @param array<string, TranslationUnit> $units
     */
    public static function write(array $units, string $source_locale_c, string $target_locale_c): string
    {
        $out = [
            'msgid ""',
            'msgstr ""',
            '"Project-Id-Version: \n"',
            '"Language: ' . $target_locale_c . '\n"',
            '"X-Source-Language: ' . $source_locale_c . '\n"',
            '"MIME-Version: 1.0\n"',
            '"Content-Type: text/plain; charset=UTF-8\n"',
            '"Content-Transfer-Encoding: 8bit\n"',
            '',
        ];

        foreach ($units as $key_c => $unit) {
            $block = [];

            foreach ($unit->notes() as $note) {
                $block[] = '#. ' . $note;
            }

            foreach ($unit->references() as $reference) {
                $block[] = '#: ' . $reference;
            }

            if ($unit->state_code() === TranslationUnit::NEEDS_REVIEW) {
                $block[] = '#, fuzzy';
            }

            $prefix = $unit->is_obsolete() ? '#~ ' : '';

            $block[] = $prefix . 'msgctxt ' . self::quote((string) $key_c);
            $block[] = $prefix . 'msgid ' . self::quote($unit->source());
            $block[] = $prefix . 'msgstr ' . self::quote($unit->target());
            $block[] = '';

            $out = array_merge($out, $block);
        }

        return implode("\n", $out);
    }

    /**
     * @return array<string, TranslationUnit>
     */
    public static function read(string $po): array
    {
        $units   = [];
        $current = self::empty_block();

        foreach (preg_split('/\r?\n/', $po) ?: [] as $line) {
            $line = rtrim($line);

            if ($line === '') {
                self::flush($current, $units);
                $current = self::empty_block();

                continue;
            }

            if (strncmp($line, '#.', 2) === 0) {
                $current['notes'][] = trim(substr($line, 2));
                continue;
            }

            if (strncmp($line, '#:', 2) === 0) {
                $current['references'][] = trim(substr($line, 2));
                continue;
            }

            if (strncmp($line, '#,', 2) === 0) {
                $current['fuzzy'] = strpos($line, 'fuzzy') !== false;
                continue;
            }

            if (strncmp($line, '#~', 2) === 0) {
                $current['obsolete'] = true;
                $line                = trim(substr($line, 2));
            } elseif ($line[0] === '#') {
                continue;                       // a translator's own comment
            }

            foreach (['msgctxt', 'msgid', 'msgstr'] as $field) {
                if (strncmp($line, $field . ' ', strlen($field) + 1) === 0) {
                    $current[$field]   = self::unquote(substr($line, strlen($field) + 1));
                    $current['last']   = $field;

                    continue 2;
                }
            }

            // A continuation line: PO wraps long strings across quoted pieces.
            if ($line[0] === '"' && $current['last'] !== null) {
                $current[$current['last']] .= self::unquote($line);
            }
        }

        self::flush($current, $units);

        return $units;
    }

    /** @return array<string, mixed> */
    private static function empty_block(): array
    {
        return [
            'msgctxt'    => null,
            'msgid'      => '',
            'msgstr'     => '',
            'notes'      => [],
            'references' => [],
            'fuzzy'      => false,
            'obsolete'   => false,
            'last'       => null,
        ];
    }

    /**
     * @param array<string, mixed>           $block
     * @param array<string, TranslationUnit> $units
     */
    private static function flush(array $block, array &$units): void
    {
        // The header is the entry with an empty msgid and no context.
        if ($block['msgctxt'] === null || $block['msgctxt'] === '') {
            return;
        }

        $key_c = (string) $block['msgctxt'];

        $state_c = TranslationUnit::INITIAL;

        if ($block['msgstr'] !== '') {
            $state_c = $block['fuzzy'] ? TranslationUnit::NEEDS_REVIEW : TranslationUnit::TRANSLATED;
        }

        $units[$key_c] = new TranslationUnit(
            $key_c,
            (string) $block['msgid'],
            (string) $block['msgstr'],
            $state_c,
            $block['notes'],
            $block['references'],
            (bool) $block['obsolete']
        );
    }

    private static function quote(string $text): string
    {
        return '"' . str_replace(
            ["\\", '"', "\n", "\t", "\r"],
            ['\\\\', '\"', '\n', '\t', '\r'],
            $text
        ) . '"';
    }

    private static function unquote(string $text): string
    {
        $text = trim($text);

        if (strlen($text) >= 2 && $text[0] === '"' && substr($text, -1) === '"') {
            $text = substr($text, 1, -1);
        }

        return str_replace(
            ['\n', '\t', '\r', '\"', '\\\\'],
            ["\n", "\t", "\r", '"', "\\"],
            $text
        );
    }
}
