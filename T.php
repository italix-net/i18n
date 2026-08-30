<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - T
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

use Italix\Encode\Html;

/**
 * The translator of the current request, reachable from a template.
 *
 *     use Italix\I18n\T;
 *
 *     <h1><?= T::e('admin.subjects.title') ?></h1>
 *     <p><?= T::e('orders.subtitle', ['customer' => $row['customer_name']]) ?></p>
 *     <script>const label = <?= Html::j(T::get('admin.datatable.next')) ?>;</script>
 *
 * ## Why a facade at all, when `$t` is already there
 *
 * Because `$t` is only there where somebody remembered to pass it. A template
 * gets it from the renderer, a partial gets it if the partial was given the
 * parent's scope, and a widget, a helper or a value object gets it if its
 * constructor was changed to take it. The last case is the one that produces
 * hard-coded sentences: threading a translator through three layers to
 * translate one word is enough friction that the word gets written in Italian
 * instead — which is how this codebase ended up with a CSV import wizard
 * containing forty untranslated strings.
 *
 * It is a facade over an object, not a container of its own: everything below
 * forwards, nothing is decided here. `Italix\Session\Session` is the same shape
 * for the same reason.
 *
 * ## bind() belongs in middleware, and on every request
 *
 * **Not once at boot.** Under PHP-FPM the process dies with the request and
 * anything works. On a resident worker — RoadRunner, FrankenPHP, Octane — a
 * request that does not rebind is answered in the language of the request
 * before it: right nearly every time, wrong without a pattern, and impossible
 * to reproduce from the request that shows it.
 *
 * That is the same failure `Italix\Mvc\Translator::set_locale()` documents, and
 * the same cure. Bind next to it:
 *
 *     $this->translator->set_locale($locale_c);
 *
 *     $messages = $this->translator->messages();
 *     if ($messages instanceof \Italix\I18n\Translator) {
 *         T::bind($messages);
 *     }
 *
 * Calling anything here before `bind()` throws rather than falling back to a
 * default language. A page rendered in the wrong language looks like a
 * translation bug and gets reported as one, six months later, by a customer.
 */
final class T
{
    private static ?Translator $bound = null;

    /** Point the facade at the translator for this request. */
    public static function bind(Translator $translator): void
    {
        self::$bound = $translator;
    }

    /**
     * Forget it again.
     *
     * For tests, and for a worker that would rather fail loudly between
     * requests than serve the previous one's language.
     */
    public static function unbind(): void
    {
        self::$bound = null;
    }

    public static function is_bound(): bool
    {
        return self::$bound !== null;
    }

    /**
     * Run something in another language, and put the facade back afterwards.
     *
     *     $body = T::for_locale($recipient_lang_c, function () use ($view) {
     *         return $view->render('mail/deposit_requested');
     *     });
     *
     * One request routinely needs two languages: the page in the operator's,
     * the e-mail in the recipient's. Without this the only way through the
     * facade is `T::bind($t->in('en'))` followed by remembering to bind back —
     * and the day somebody forgets, or the code in between throws, the rest of
     * the request is rendered in the recipient's language. That failure does not
     * raise anything: the page is simply in the wrong language, from the point
     * of the mistake onwards, for whoever happened to trigger the e-mail.
     *
     * So the restore is in a `finally`. An exception on the way out is exactly
     * the case that would otherwise leave the state dirty, and it is also the
     * case nobody writes a test for.
     *
     * The bound translator is left untouched: `in()` returns a copy, which is
     * why it is on the contract.
     *
     * @template TReturn
     * @param  callable():TReturn $work
     * @return TReturn
     */
    public static function for_locale(string $locale_c, callable $work)
    {
        $previous = self::translator();

        self::bind($previous->in($locale_c));

        try {
            return $work();
        } finally {
            self::bind($previous);
        }
    }

    /** The bound translator, for the cases these forwarders do not cover. */
    public static function translator(): Translator
    {
        if (self::$bound === null) {
            throw new I18nException(
                'Italix\I18n\T is not bound. Call T::bind($translator) from the middleware that '
                . 'sets the locale, on every request — see the class docblock for why not at boot.'
            );
        }

        return self::$bound;
    }

    // -------------------------------------------------------------------------
    // Forwarders
    // -------------------------------------------------------------------------

    /**
     * The message, encoded for an HTML document: parameters escaped, template
     * left alone. This is the one templates want.
     *
     * @param array<string, mixed> $params
     */
    public static function e(string $key_c, array $params = []): Html
    {
        return self::translator()->e($key_c, $params);
    }

    /**
     * The message as a plain string.
     *
     * For everywhere that is not an HTML document — an e-mail subject, a CSV
     * cell, a log line — and for values on their way into `Html::j()` or
     * `Html::attribs()`, which do their own encoding for their own context.
     *
     * @param array<string, mixed> $params
     */
    public static function get(string $key_c, array $params = []): string
    {
        return self::translator()->get($key_c, $params);
    }

    /** @param array<string, mixed> $params */
    public static function choice(string $key_c, int $count_n, array $params = []): string
    {
        return self::translator()->choice($key_c, $count_n, $params);
    }

    /** @param array<string, mixed> $params */
    public static function choice_e(string $key_c, int $count_n, array $params = []): Html
    {
        return self::translator()->choice_e($key_c, $count_n, $params);
    }

    public static function has(string $key_c): bool
    {
        return self::translator()->has($key_c);
    }

    public static function locale_code(): string
    {
        return self::translator()->locale_code();
    }

    /** @return array<string, mixed> */
    public static function group(string $key_c): array
    {
        return self::translator()->group($key_c);
    }
}
