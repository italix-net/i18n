<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Translator
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

use Italix\Encode\Html;
use MessageFormatter;

/**
 * Look up a message, fill it in, and be honest when it cannot.
 *
 * ## ICU MessageFormat, not a placeholder scheme of our own
 *
 *     'files' => '{n, plural, =0 {no files} one {# file} other {# files}}'
 *
 * Plural categories are a property of a *language*, not of a number: Russian has
 * four, Arabic six, Japanese one, and no amount of `$n === 1 ? 'a' : 'b'` gets
 * that right. So the answers are always ICU's — from `ext-intl` when it is
 * installed, and otherwise from tables baked out of it, never from a rule
 * invented here. An i18n library that quietly gets Russian plurals wrong is
 * worse than one that refuses to start. See {@see Engine}.
 *
 * ## Three ways a lookup can go wrong, and none of them break the page
 *
 * - **missing key** — returns the key itself and records it
 * - **unparseable message** — returns the raw message and records it
 * - **missing parameter** — ICU leaves the placeholder, and it is recorded
 *
 * All three are readable in `problems()`, which is what `lang:extract` reports
 * and what makes them findable before a user meets them. Throwing instead would
 * turn a typo in a translation file into a 500 on a page that was otherwise
 * fine; returning silently would mean nobody ever fixes it.
 */
final class Translator implements \Italix\Contracts\Translator
{
    /** @var array<string, Catalog> locale => catalogue */
    private array $catalogs = [];

    private string $locale_c;
    private string $fallback_c;

    /** ICU when installed, the baked tables when not. */
    private Engine $engine;

    /** @var array<string, string> key => what went wrong */
    private array $problems = [];

    /**
     * @param Catalog[] $catalogs
     */
    public function __construct(array $catalogs, string $locale_c, string $fallback_c = '')
    {

        foreach ($catalogs as $catalog) {
            if (!$catalog instanceof Catalog) {
                throw new I18nException('A Translator takes Catalog instances.');
            }

            $this->catalogs[$catalog->locale_code()] = isset($this->catalogs[$catalog->locale_code()])
                ? $this->catalogs[$catalog->locale_code()]->merge($catalog)
                : $catalog;
        }

        $this->fallback_c = $fallback_c === '' ? '' : Locale::normalise($fallback_c);
        $this->locale_c   = Locale::normalise($locale_c);
        $this->engine     = IcuEngine::is_available() ? new IcuEngine() : new PortableEngine();
    }

    public function locale_code(): string
    {
        return $this->locale_c;
    }

    /**
     * The spelling `Italix\Mvc\Translator` uses, so a view written against one
     * keeps working against the other.
     *
     * Not deprecated: it is an alias, not a mistake, and removing it would be
     * the only thing standing between an application and a one-line swap.
     */
    public function locale(): string
    {
        return $this->locale_c;
    }

    public function fallback_code(): string
    {
        return $this->fallback_c;
    }

    /**
     * @return string[]
     */
    public function locales(): array
    {
        return array_keys($this->catalogs);
    }

    /** A new translator for another locale, sharing the catalogues. */
    public function in(string $locale_c): self
    {
        $clone           = clone $this;
        $clone->locale_c = Locale::normalise($locale_c);

        return $clone;
    }

    public function has(string $key_c): bool
    {
        return $this->lookup($key_c) !== null;
    }

    /**
     * The message for a key, with `$params` substituted.
     *
     * @param array<string, mixed> $params
     */
    public function get(string $key_c, array $params = []): string
    {
        $message = $this->lookup($key_c);

        if ($message === null) {
            $this->problems[$key_c] = 'missing in ' . $this->locale_c;

            // The key, not an empty string: an untranslated screen showing
            // `admin.users.title` is obviously broken, and one showing nothing
            // looks like a layout bug nobody can locate.
            return $key_c;
        }

        return $this->format($key_c, $message, $params);
    }

    /**
     * A message chosen by count.
     *
     * A convenience over `get()` with the count already named `n`, because that
     * is the shape almost every plural message wants:
     *
     *     $t->choice('files.count', 3)
     *     // '{n, plural, =0 {no files} one {# file} other {# files}}'
     *
     * @param array<string, mixed> $params
     */
    public function choice(string $key_c, int $count_n, array $params = []): string
    {
        return $this->get($key_c, array_merge(['n' => $count_n], $params));
    }

    /**
     * Format an ICU message directly, without a catalogue.
     *
     * For messages that come from somewhere else — a database column, a job
     * payload — and still deserve correct plurals.
     *
     * @param array<string, mixed> $params
     */
    public function format_message(string $message, array $params = []): string
    {
        return $this->format('(inline)', $message, $params);
    }

    /**
     * The message for a key, ready to be printed in an HTML document.
     *
     *     <h1><?= $t->e('admin.subjects.title') ?></h1>
     *     <?= $t->e('orders.subtitle', ['customer' => $row['customer_name']]) ?>
     *
     * ## The two halves of a message are not equally trustworthy
     *
     * This is the reason the method exists, and it is not brevity. A translated
     * message has a **template**, which comes from a catalogue in the
     * repository and is therefore ours, and **parameters**, which routinely
     * come from a database column somebody filled in through a form. Only one
     * of the two is trusted, and until now nothing in the API could tell them
     * apart.
     *
     * `get()` returns one string, so a caller had exactly two options and both
     * were wrong for somebody:
     *
     *     H::e($t->get('k', $params))     // safe, and destroys any markup the
     *                                     // message legitimately contains
     *     H::raw($t->get('k', $params))   // keeps the markup, and prints the
     *                                     // parameter unencoded — XSS
     *
     * The second is not a hypothetical: it is what a developer reaches for on
     * the day a translator writes `<strong>{customer}</strong>` and the page shows
     * `&lt;strong&gt;` in plain sight. The fix looks like a rendering fix.
     *
     * So the parameters are encoded and the template is not, and the result is
     * an `Html` — already encoded, so `H::e()` around it is a no-op rather than
     * a second pass, and `H::j()` around it throws, because a JavaScript literal
     * is not an HTML context and silently accepting one there would put `&amp;`
     * inside a string the browser hands to a user.
     *
     * ## Counts are encoded too, and it was worth measuring rather than assuming
     *
     * The obvious worry is that encoding turns `3` into `'3'` and that ICU's
     * plural selector then stops choosing the right form. Measured against
     * `ext-intl`, it does not: `plural`, `number`, `currency` and `percent` all
     * coerce a numeric string identically to the number, scientific notation
     * (`1.0E+20`) included. So there is no special case here — one would be code
     * defending against something that does not happen, and the suite asserts
     * the selection still works for 0, 1 and 5.
     *
     * A value that is already `Html` is left alone by the encoder, so a caller
     * composing one message inside another does not get a doubly-encoded
     * fragment.
     *
     * @param array<string, mixed> $params
     */
    public function e(string $key_c, array $params = []): Html
    {
        return Html::raw($this->get($key_c, self::encode_params($params)));
    }

    /**
     * `choice()` for an HTML document. See {@see e()} for what is encoded.
     *
     * @param array<string, mixed> $params
     */
    public function choice_e(string $key_c, int $count_n, array $params = []): Html
    {
        return Html::raw($this->choice($key_c, $count_n, self::encode_params($params)));
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function encode_params(array $params): array
    {
        foreach ($params as $name_c => $value) {
            $params[$name_c] = (string) Html::e($value);
        }

        return $params;
    }

    /**
     * A whole branch of the catalogue, nested, for handing to something that is
     * not PHP.
     *
     * `get()` answers one key, which is right for a template and wrong for a
     * client-side mirror that needs the sentences for every code a rule can
     * return — `italix/rules` emits exactly that. Without this the page carries
     * a hand-written list, and a hand-written list falls behind the moment a
     * rule gains a code.
     *
     * Messages are **not** formatted: a branch is data on its way to another
     * runtime, and running ICU over a message whose parameters are only known
     * in the browser would either fail or eat the placeholders. The receiver
     * formats them.
     *
     * The fallback locale is merged underneath, so a branch half-translated
     * still arrives whole. A missing key, or a key that is a message rather
     * than a branch, is `[]` rather than an exception: a page that renders no
     * messages beats a page that does not render.
     *
     * @return array<string, mixed>
     */
    public function group(string $key_c): array
    {
        $prefix_c = $key_c . '.';
        $length_n = strlen($prefix_c);
        $nested   = [];

        // Least specific first, so the current locale overwrites the fallback
        // key by key rather than the whole branch winning or losing at once.
        foreach (array_reverse(Locale::chain($this->locale_c, $this->fallback_c)) as $step) {
            if (!isset($this->catalogs[$step])) {
                continue;
            }

            foreach ($this->catalogs[$step]->all() as $flat_key_c => $message) {
                if (strncmp($flat_key_c, $prefix_c, $length_n) !== 0) {
                    continue;
                }

                $node = &$nested;

                foreach (explode('.', substr($flat_key_c, $length_n)) as $segment_c) {
                    if (!isset($node[$segment_c]) || !is_array($node[$segment_c])) {
                        $node[$segment_c] = [];
                    }

                    $node = &$node[$segment_c];
                }

                $node = $message;
                unset($node);
            }
        }

        return $nested;
    }

    /**
     * What went wrong since this translator was built.
     *
     * @return array<string, string> key => reason
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function forget_problems(): void
    {
        $this->problems = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** Walk the fallback chain until a catalogue has the key. */
    private function lookup(string $key_c): ?string
    {
        foreach (Locale::chain($this->locale_c, $this->fallback_c) as $step) {
            if (isset($this->catalogs[$step]) && $this->catalogs[$step]->has($key_c)) {
                return $this->catalogs[$step]->get($key_c);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function format(string $key_c, string $message, array $params): string
    {
        // A message with no braces has nothing for ICU to do, and running it
        // through the parser anyway would make an apostrophe meaningful —
        // "l'utente" is a quoting character to ICU and would silently disappear.
        if (strpos($message, '{') === false) {
            return $message;
        }

        try {
            $out = $this->engine->format_message($this->locale_c, $message, $params);
        } catch (I18nException $e) {
            // Including the portable engine's refusals, which are a kind of
            // problem the caller should see rather than a crash: `problems()`
            // is the channel `ix lang:check` already reads.
            $this->problems[$key_c] = $e->getMessage();

            return $message;
        }

        $unfilled = self::unfilled_in($out, $params);

        if ($unfilled !== []) {
            $this->problems[$key_c] = 'parameter(s) not supplied: ' . implode(', ', $unfilled);
        }

        return $out;
    }

    /**
     * Placeholders that survived formatting, which means nothing was passed for
     * them.
     *
     * This looks at the **output**, not at the message, and deliberately so.
     * When a named argument is missing ICU does not fail — `format()` returns a
     * string with `{name}` still in it, so the `$out === false` branch above
     * never fires and the placeholder reaches the page in silence. Measured:
     * a message using `{count}` called with `n` came back as the literal
     * `{count}` while `problems()` stayed empty, contradicting this class's own
     * documentation.
     *
     * Reading the output also avoids parsing ICU's grammar, where telling an
     * argument from a plural branch (`other {parti}`) needs a real parser and
     * a wrong guess would invent problems that do not exist.
     *
     * The one thing it can misread is a message containing a quoted literal
     * brace around a single word — `'{'foo'}'`. That is rare, and the cost is a
     * spurious line in a diagnostic report rather than anything a user sees.
     *
     * @param array<string, mixed> $params
     * @return string[]
     */
    private static function unfilled_in(string $out, array $params): array
    {
        if (strpos($out, '{') === false) {
            return [];
        }

        if (preg_match_all('~\{([A-Za-z][A-Za-z0-9_]*)\}~', $out, $matches) === 0) {
            return [];
        }

        $missing = [];

        foreach ($matches[1] as $name) {
            if (!array_key_exists($name, $params) && !in_array($name, $missing, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
