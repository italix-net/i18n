<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Xliff
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

use DOMDocument;
use DOMElement;

/**
 * XLIFF 2.0 — the format translation tools actually read.
 *
 * OASIS standard; Trados, memoQ, Phrase, Crowdin, Weblate and Lokalise all
 * import it. That is the whole argument for having it: a library whose messages
 * cannot be handed to a professional translator is a library that only ever gets
 * translated by programmers.
 *
 * ## Why XLIFF rather than PO, for this library
 *
 * PO identifies a message **by its source text** — `msgid`. Ours are identified
 * by a key, and the difference is not cosmetic: under PO, fixing a comma in the
 * Italian original silently orphans the German translation of that string. XLIFF
 * has `<unit id="…">`, which is exactly our key.
 *
 * PO is still supported ({@see Po}) because some workflows want nothing else.
 *
 * ## What is written
 *
 *     <xliff version="2.0" srcLang="it" trgLang="de">
 *       <file id="messages">
 *         <notes><note id="n1">…</note></notes>
 *         <unit id="admin.posts.edit">
 *           <notes>…</notes>
 *           <segment state="translated">
 *             <source>Modifica</source>
 *             <target>Bearbeiten</target>
 *           </segment>
 *         </unit>
 *       </file>
 *     </xliff>
 *
 * Obsolete units are kept, marked with a note, because deleting a translation
 * that a reverted change would want back is a cost nobody sees until it lands.
 */
final class Xliff
{
    /** XLIFF 2.0 states. Ours map onto them; `needs-review` is not one of theirs. */
    private const STATE_OUT = [
        TranslationUnit::INITIAL      => 'initial',
        TranslationUnit::TRANSLATED   => 'translated',
        TranslationUnit::NEEDS_REVIEW => 'translated',   // plus the marker below
        TranslationUnit::REVIEWED     => 'reviewed',
    ];

    /**
     * XLIFF 2.0 has no `fuzzy`. `subState` is the standard's own extension point
     * for exactly this, so the flag survives a round trip through a tool that
     * does not understand it rather than being silently dropped.
     */
    private const SUBSTATE_NEEDS_REVIEW = 'italix:needs-review';

    /**
     * @param array<string, TranslationUnit> $units
     */
    public static function write(array $units, string $source_locale_c, string $target_locale_c, string $file_id = 'messages'): string
    {
        $dom               = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('xliff');
        $root->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:2.0');
        $root->setAttribute('version', '2.0');
        $root->setAttribute('srcLang', $source_locale_c);
        $root->setAttribute('trgLang', $target_locale_c);
        $dom->appendChild($root);

        $file = $dom->createElement('file');
        $file->setAttribute('id', $file_id);
        $root->appendChild($file);

        foreach ($units as $key_c => $unit) {
            $file->appendChild(self::unit_element($dom, (string) $key_c, $unit));
        }

        return (string) $dom->saveXML();
    }

    private static function unit_element(DOMDocument $dom, string $key_c, TranslationUnit $unit): DOMElement
    {
        $element = $dom->createElement('unit');
        $element->setAttribute('id', $key_c);

        $notes = $unit->notes();

        foreach ($unit->references() as $reference) {
            $notes[] = 'ref: ' . $reference;
        }

        if ($unit->is_obsolete()) {
            // Marked rather than removed: a change reverted next week wants this
            // translation back, and regenerating would have thrown it away.
            array_unshift($notes, 'obsolete: no longer used by the source');
        }

        if ($notes !== []) {
            $notes_element = $dom->createElement('notes');

            foreach ($notes as $i => $note) {
                $note_element = $dom->createElement('note');
                $note_element->setAttribute('id', 'n' . ($i + 1));
                $note_element->appendChild($dom->createTextNode($note));
                $notes_element->appendChild($note_element);
            }

            $element->appendChild($notes_element);
        }

        $segment = $dom->createElement('segment');
        $segment->setAttribute('state', self::STATE_OUT[$unit->state_code()] ?? 'initial');

        if ($unit->state_code() === TranslationUnit::NEEDS_REVIEW) {
            $segment->setAttribute('subState', self::SUBSTATE_NEEDS_REVIEW);
        }

        $source = $dom->createElement('source');
        $source->appendChild($dom->createTextNode($unit->source()));
        $segment->appendChild($source);

        $target = $dom->createElement('target');
        $target->appendChild($dom->createTextNode($unit->target()));
        $segment->appendChild($target);

        $element->appendChild($segment);

        return $element;
    }

    /**
     * @return array<string, TranslationUnit>
     */
    public static function read(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        $dom = new DOMDocument();

        // Entity expansion is off by default in modern libxml, but a translation
        // file arrives from outside and this is not the place to find out.
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOENT ^ LIBXML_NOENT);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $first = $errors === [] ? 'not well-formed' : trim($errors[0]->message);

            throw new I18nException("That XLIFF could not be read: {$first}");
        }

        $units = [];

        foreach ($dom->getElementsByTagName('unit') as $element) {
            $key_c = (string) $element->getAttribute('id');

            if ($key_c === '') {
                continue;
            }

            $units[$key_c] = self::unit_from($element, $key_c);
        }

        return $units;
    }

    private static function unit_from(DOMElement $element, string $key_c): TranslationUnit
    {
        $segments = $element->getElementsByTagName('segment');
        $segment  = $segments->length > 0 ? $segments->item(0) : null;

        $source     = '';
        $target     = '';
        $state_c    = TranslationUnit::INITIAL;

        if ($segment instanceof DOMElement) {
            $source_nodes = $segment->getElementsByTagName('source');
            $target_nodes = $segment->getElementsByTagName('target');

            $source = $source_nodes->length > 0 ? (string) $source_nodes->item(0)->textContent : '';
            $target = $target_nodes->length > 0 ? (string) $target_nodes->item(0)->textContent : '';

            $state_c = self::state_from(
                (string) $segment->getAttribute('state'),
                (string) $segment->getAttribute('subState')
            );
        }

        $notes       = [];
        $references  = [];
        $is_obsolete = false;

        foreach ($element->getElementsByTagName('note') as $note) {
            $text = trim((string) $note->textContent);

            if (strncmp($text, 'ref: ', 5) === 0) {
                $references[] = substr($text, 5);
                continue;
            }

            if (strncmp($text, 'obsolete:', 9) === 0) {
                $is_obsolete = true;
                continue;
            }

            $notes[] = $text;
        }

        return new TranslationUnit($key_c, $source, $target, $state_c, $notes, $references, $is_obsolete);
    }

    private static function state_from(string $state_c, string $sub_state_c): string
    {
        if ($sub_state_c === self::SUBSTATE_NEEDS_REVIEW) {
            return TranslationUnit::NEEDS_REVIEW;
        }

        switch ($state_c) {
            case 'translated':
                return TranslationUnit::TRANSLATED;
            case 'reviewed':
            case 'final':
                return TranslationUnit::REVIEWED;
            default:
                return TranslationUnit::INITIAL;
        }
    }
}
