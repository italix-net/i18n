<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Merge
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * Reconcile what the source now says with what a translator has already produced.
 *
 * This is `msgmerge`, and it is the one idea worth taking from thirty years of
 * gettext. Without it a translation file has two futures and both are bad:
 * regenerate and lose everything, or hand-patch and drift.
 *
 * The rule, for every key:
 *
 * | source | existing translation | result |
 * |---|---|---|
 * | new key | — | added, `initial`, empty |
 * | unchanged | translated | kept, untouched |
 * | **changed** | translated | **kept and marked `needs-review`**, with the old text |
 * | gone | anything | **kept, marked obsolete** |
 *
 * The third row is the whole point. A reworded original does not throw away the
 * translation: it hands it back with a flag, so the work is *revised* rather
 * than redone. And the fourth means that undoing a change tomorrow does not cost
 * a translation somebody was paid for.
 *
 * Nothing here knows about XLIFF or PO. Those are transports; this is the
 * decision.
 */
final class Merge
{
    /**
     * @param array<string, string>          $source   key => text in the source language
     * @param array<string, TranslationUnit> $existing key => what the translator has
     * @param array<string, string[]>        $references key => where the code uses it
     *
     * @return array<string, TranslationUnit> keyed by message key, source order first
     */
    public static function reconcile(array $source, array $existing, array $references = []): array
    {
        $out = [];

        foreach ($source as $key_c => $text) {
            $text = (string) $text;
            $refs = $references[$key_c] ?? [];

            if (!isset($existing[$key_c])) {
                $out[$key_c] = new TranslationUnit($key_c, $text, '', TranslationUnit::INITIAL, [], $refs);

                continue;
            }

            $unit = $existing[$key_c];

            // An obsolete unit whose key came back is simply alive again, and
            // its translation with it — which is why obsolete units are kept.
            if ($unit->is_obsolete()) {
                $unit = $unit->obsolete(false);
            }

            if ($unit->source() === $text) {
                $out[$key_c] = $unit->with_references($refs);

                continue;
            }

            // The source moved. Keep the translation, keep the *old* source too
            // — a reviewer needs to see what changed — and ask for a look.
            $out[$key_c] = $unit
                ->with_state($unit->target() === '' ? TranslationUnit::INITIAL : TranslationUnit::NEEDS_REVIEW)
                ->with_source($text)
                ->with_references($refs)
                ->with_notes(array_merge(
                    array_values(array_filter(
                        $unit->notes(),
                        static fn (string $n): bool => strncmp($n, 'was: ', 5) !== 0
                    )),
                    ['was: ' . $unit->source()]
                ));
        }

        // Whatever the source no longer mentions is retired, not deleted.
        foreach ($existing as $key_c => $unit) {
            if (!isset($out[$key_c])) {
                $out[$key_c] = $unit->obsolete(true)->with_references([]);
            }
        }

        return $out;
    }

    /**
     * What a run of {@see reconcile()} did, for the console to print.
     *
     * @param array<string, TranslationUnit> $before
     * @param array<string, TranslationUnit> $after
     *
     * @return array{added: string[], outdated: string[], obsolete: string[], unchanged_n: int}
     */
    public static function summary(array $before, array $after): array
    {
        $added    = [];
        $outdated = [];
        $obsolete = [];
        $same_n   = 0;

        foreach ($after as $key_c => $unit) {
            if (!isset($before[$key_c])) {
                $added[] = $key_c;
                continue;
            }

            if ($unit->is_obsolete() && !$before[$key_c]->is_obsolete()) {
                $obsolete[] = $key_c;
                continue;
            }

            if ($unit->state_code() === TranslationUnit::NEEDS_REVIEW
                && $before[$key_c]->state_code() !== TranslationUnit::NEEDS_REVIEW) {
                $outdated[] = $key_c;
                continue;
            }

            $same_n++;
        }

        return [
            'added'       => $added,
            'outdated'    => $outdated,
            'obsolete'    => $obsolete,
            'unchanged_n' => $same_n,
        ];
    }

    /**
     * The runtime catalogue: only what is safe to show.
     *
     * A message that was never translated, or whose source has moved since it
     * was, is **left out** rather than shipped — so the translator's fallback
     * chain answers instead of a page displaying a half-current sentence with
     * no sign that it is one.
     *
     * @param array<string, TranslationUnit> $units
     * @return array<string, string> flat key => text, ready for a Catalog
     */
    public static function to_catalog(array $units): array
    {
        $out = [];

        foreach ($units as $key_c => $unit) {
            if ($unit->is_usable()) {
                $out[$key_c] = $unit->target();
            }
        }

        return $out;
    }
}
