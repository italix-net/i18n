<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Parsed
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

use DateTimeImmutable;

/**
 * The outcome of reading a localised string back into a value.
 *
 * A parser that answers `false` is not answering: `false` is indistinguishable
 * from zero after a cast, and it says nothing about *why*. Since the strings
 * being read here come from human beings typing into forms, the "why" is the
 * part the application needs — it is what turns a red field into a sentence.
 *
 * Immutable, and built only by {@see Formatter}.
 */
final class Parsed
{
    /** The input was empty or only whitespace. */
    public const EMPTY_INPUT = 'empty';

    /** Nothing at the start of the input looked like a value. */
    public const UNREADABLE = 'unreadable';

    /**
     * A value was read, but the input continued past it.
     *
     * This is the failure ICU does **not** report: it stops at the first
     * character it cannot use and declares success, so `"12,5x"` parses to 12.5
     * with no error at all. Left unchecked, a typo silently becomes a number.
     */
    public const TRAILING = 'trailing';

    /** @var mixed */
    private $value;

    private ?string $error_c;
    private string $input;

    /** For parse_currency: the ISO 4217 code ICU recognised, if any. */
    private ?string $currency_c;

    /**
     * @param mixed $value
     */
    private function __construct($value, ?string $error_c, string $input, ?string $currency_c = null)
    {
        $this->value      = $value;
        $this->error_c    = $error_c;
        $this->input      = $input;
        $this->currency_c = $currency_c;
    }

    /**
     * @param mixed $value
     */
    public static function ok($value, string $input, ?string $currency_c = null): self
    {
        return new self($value, null, $input, $currency_c);
    }

    public static function fail(string $error_c, string $input): self
    {
        return new self(null, $error_c, $input);
    }

    public function is_ok(): bool
    {
        return $this->error_c === null;
    }

    /**
     * The parsed value, or null when the input could not be read.
     *
     * @return mixed float, int or DateTimeImmutable depending on the caller
     */
    public function value()
    {
        return $this->value;
    }

    /** A machine code — never a sentence. The wording is the application's. */
    public function error_code(): ?string
    {
        return $this->error_c;
    }

    /** What was handed in, unchanged — for an error message or a log. */
    public function input(): string
    {
        return $this->input;
    }

    /** ISO 4217 code, set only by parse_currency on success. */
    public function currency_code(): ?string
    {
        return $this->currency_c;
    }

    /**
     * The value as a float, or $default when the parse failed.
     *
     * For the caller that has already decided what an unreadable field means
     * and does not want to branch.
     */
    public function or_float(float $default = 0.0): float
    {
        return $this->is_ok() ? (float) $this->value : $default;
    }

    public function or_null(): ?DateTimeImmutable
    {
        return $this->value instanceof DateTimeImmutable ? $this->value : null;
    }
}
