<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - TranslationUnit
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * One message on its way to or from a translator.
 *
 * The runtime catalogue — `lang/{locale}/messages.php` — holds only the
 * translated text, which is all a page needs and not enough to *maintain* a
 * translation. Three things are missing from it and present here:
 *
 *   - the **source** the translation was made from, without which nobody can
 *     tell that the original has since been reworded;
 *   - a **state**, so a revised original can be handed back as "look at this
 *     again" instead of being silently dropped or silently kept;
 *   - **notes and source references**, because a translator given the bare word
 *     "Open" cannot know whether it is a verb on a button.
 *
 * Hence two formats rather than one: PHP arrays are what runs, XLIFF or PO is
 * what travels. This class is the shape they meet in.
 */
final class TranslationUnit
{
    /** Never seen by a translator. */
    public const INITIAL = 'initial';

    /** Translated, and the source has not moved since. */
    public const TRANSLATED = 'translated';

    /**
     * Translated, but the source changed afterwards — gettext's `fuzzy`.
     *
     * The single most useful state, and the reason this whole file exists: the
     * old translation is kept and shown, so the work is *revised* rather than
     * redone.
     */
    public const NEEDS_REVIEW = 'needs-review';

    /** Checked by a second pair of eyes. */
    public const REVIEWED = 'reviewed';

    private string $key_c;
    private string $source;
    private string $target;
    private string $state_c;

    /** @var string[] free-text notes for whoever translates this */
    private array $notes;

    /** @var string[] where in the code this message is used */
    private array $references;

    /**
     * A message the source no longer uses.
     *
     * Kept rather than deleted — gettext's `#~` — so that reverting a change
     * does not throw away a translation somebody was paid for.
     */
    private bool $is_obsolete;

    public function __construct(
        string $key_c,
        string $source,
        string $target = '',
        string $state_c = self::INITIAL,
        array $notes = [],
        array $references = [],
        bool $is_obsolete = false
    ) {
        $this->key_c       = $key_c;
        $this->source      = $source;
        $this->target      = $target;
        $this->state_c     = $state_c;
        $this->notes       = array_values($notes);
        $this->references  = array_values($references);
        $this->is_obsolete = $is_obsolete;
    }

    public function key(): string
    {
        return $this->key_c;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function state_code(): string
    {
        return $this->state_c;
    }

    /** @return string[] */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return string[] */
    public function references(): array
    {
        return $this->references;
    }

    public function is_obsolete(): bool
    {
        return $this->is_obsolete;
    }

    /** Translated and current — the only state safe to ship without a look. */
    public function is_usable(): bool
    {
        return !$this->is_obsolete
            && $this->target !== ''
            && ($this->state_c === self::TRANSLATED || $this->state_c === self::REVIEWED);
    }

    /** Waiting for somebody: never translated, or translated and then outdated. */
    public function needs_work(): bool
    {
        return !$this->is_obsolete && !$this->is_usable();
    }

    public function with_target(string $target, string $state_c = self::TRANSLATED): self
    {
        $copy          = clone $this;
        $copy->target  = $target;
        $copy->state_c = $state_c;

        return $copy;
    }

    public function with_source(string $source): self
    {
        $copy         = clone $this;
        $copy->source = $source;

        return $copy;
    }

    public function with_state(string $state_c): self
    {
        $copy          = clone $this;
        $copy->state_c = $state_c;

        return $copy;
    }

    public function with_references(array $references): self
    {
        $copy             = clone $this;
        $copy->references = array_values($references);

        return $copy;
    }

    public function with_notes(array $notes): self
    {
        $copy        = clone $this;
        $copy->notes = array_values($notes);

        return $copy;
    }

    public function obsolete(bool $flag = true): self
    {
        $copy              = clone $this;
        $copy->is_obsolete = $flag;

        return $copy;
    }
}
