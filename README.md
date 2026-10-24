# Italix I18n

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

Translation and formatting on ICU. Plural categories that are correct in Russian, numbers and money
the way each locale writes them, and a key extractor that refuses to guess.

```php
$t = translator(__DIR__ . '/lang', 'it-IT', 'en');

$t->get('admin.users.title');                  // 'Utenti'
$t->get('greeting', ['name' => 'Anna']);       // 'Ciao, Anna!'
$t->choice('files.count', 3);                  // '3 file'

$f = formatter('it-IT', 'Europe/Rome');
$f->currency(1234.5, 'EUR');                   // '1.234,50 €'
$f->percent(0.075);                            // '7,5%'
$f->date_time($when, 'full', 'short');         // 'giovedì 6 agosto 2026 09:06'
```

## Why the rules come from ICU, and why `ext-intl` is still only suggested

**Plural categories are a property of a language, not of a number.** Russian has four, Arabic six,
Japanese one, and `$n === 1 ? 'a' : 'b'` is wrong for most of the world — including for 21 in
Russian, which takes the *one* category. So the answers are always ICU's.

```php
'files' => '{n, plural, =0 {nessun file} one {# file} other {# file}}'
```

They do not always come from ICU *at runtime*. The official `php:8.x` images ship without intl, so
requiring it was the friction everybody met in their first five minutes. When the extension is
present `IcuEngine` asks it; when it is not, `PortableEngine` reads tables **baked from ICU** by
`ix i18n:bake` and answers the same thing. `Formatter::engine_code()` says which one is answering, and
`using_engine('portable')` forces the other — on a machine with intl the portable branch would
otherwise never execute, and code that never executes is code that does not work.

The portable engine **refuses** what it cannot do honestly — collation, spellout, display names,
non-Gregorian calendars — rather than approximating it. Those calls raise a message naming the
extension. Everything this README shows below works on both.

## Three ways a lookup goes wrong, and none of them break the page

| | what you get | where you find it |
|---|---|---|
| missing key | the key itself | `problems()` |
| unparseable message | the raw message | `problems()` |
| missing parameter | the placeholder | `problems()` |

A screen showing `admin.users.title` is obviously broken; one showing nothing is a mystery nobody can
locate. And throwing would turn a typo in a translation file into a 500 on a page that was otherwise
fine.

`ix lang:check` reports the same information across the whole codebase.

## The apostrophe

ICU treats `'` as a quoting character, so `"L'utente non esiste"` loses letters if it goes through
the parser. A message with no braces has nothing for ICU to do and is returned as-is — which is why
Italian and French messages survive.

## Formatting

```php
$f->number(1234567.891);      // it: 1.234.567,891   en: 1,234,567.891
$f->currency(1234.5, 'EUR');  // it: 1.234,50 €      en: €1,234.50
$f->percent(0.075);           // 7,5%  — see below
$f->ordinal(3);               // it: 3º   en: 3rd
$f->spell(42);                // quarantadue
$f->bytes(1536000);           // 1,5 MiB
$f->date_time($when, 'full', 'short');
$f->pattern($when, 'd MMMM y');
```

**`percent()` does not round by default**, and that is a deliberate departure. ICU's own percent
formatter uses zero fraction digits, so `0.075` comes out as `8%` — measured. A rate quietly rounded
from 7.5 to 8 is the kind of wrong that reaches an invoice. Pass `$decimals_n` to narrow it.

## Relative time is computed, not worded

PHP's `ext-intl` does not expose ICU's `RelativeDateTimeFormatter` — `IntlRelativeDateTimeFormatter`
does not exist. Wording it here would mean shipping translations for every language this library
claims to support, which is a promise it cannot keep. So it computes and your catalogue words it:

```php
$parts = $f->relative_parts($then);
// ['unit_c' => 'day', 'count_n' => 3, 'is_past' => true, 'seconds_n' => -259200]

$t->choice('time.past.' . $parts['unit_c'], $parts['count_n']);
// 'time.past.day' => '{n, plural, one {# giorno fa} other {# giorni fa}}'
```

## Locales

```php
Locale::normalise('it_IT');                    // 'it-IT'
Locale::chain('de-AT', 'en');                  // ['de-AT', 'de', 'en']
Locale::best_of('it', ['en', 'it-CH']);        // 'it-CH'
Locale::from_header('fr-CH, fr;q=0.9, en;q=0.8');
```

The chain falls back to a **related language before the default one**: an Austrian visitor is better
served by German than by English.

## Catalogues

`lang/{locale}/{group}.php`, each returning a nested array. `messages.php` is the default group and
contributes no prefix, so the common case reads `admin.title` rather than `messages.admin.title` and
a project that never needs a second group never meets the concept.

Nesting is flattened once at load. A boolean in a language file is **refused, not cast** — PHP turns
`true` into `"1"` and `false` into `""`, and either reaches production looking like a translation.

## Printing into a page: the two halves of a message

A message has a **template**, which lives in a file in your repository, and **parameters**, which
routinely come from a column somebody filled in through a form. Only one of the two is trusted, and
`get()` returns a single string that cannot tell them apart:

```php
H::e($t->get('k', $params))     // safe, and destroys any markup the message carries
H::raw($t->get('k', $params))   // keeps the markup, and prints the parameter unencoded
```

The second is what a developer reaches for on the day a translator writes `<strong>{customer}</strong>`
and the page shows `&lt;strong&gt;` in plain sight. It looks like a rendering fix.

```php
// lang/it/messages.php
'subtitle' => 'Order for <strong>{customer}</strong>',

<?= $t->e('orders.subtitle', ['customer' => $row['customer_name']]) ?>
// Order for <strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>
```

`e()` and `choice_e()` return `Italix\Encode\Html`, so composition is decided by the type rather than
by the caller remembering: `H::e()` around one is a no-op, and `H::j()` around one **throws** — a
JavaScript literal is not an HTML context, and quietly accepting one there is how `&amp;` ends up
inside a string the browser shows to a user.

`get()`, `choice()` and `format_message()` are unchanged and still return plain strings. That is
deliberate: the same catalogue serves e-mail subjects, PDFs, CSV files and CLI output, where HTML
encoding would be a bug.

Counts go through the encoder like everything else. The obvious worry — that `'3'` breaks the plural
selector — was measured and is false: `plural`, `number`, `currency` and `percent` all coerce a
numeric string identically to the number, scientific notation included. The special case that had
been written for it was deleted.

One request routinely needs two languages — the page in the operator's, the e-mail in the
recipient's. `in()` is the primitive and returns a copy, so the surrounding page never moves. Through
the facade, where a shared partial has no `$t` to switch:

```php
$body = T::for_locale($recipient_lang_c, fn () => $view->render('mail/deposit_requested'));
```

The restore is in a `finally`, because an exception on the way out is exactly the path that would
otherwise leave the rest of the request in the recipient's language — and it is the path nobody
writes a test for.

`group()` is the bulk form, for handing a whole branch to something that is not PHP — a client-side
validator that needs the sentence for every code a rule can return. Messages come back unformatted,
because the parameters are known at the other end.

## The extractor

```
$ ix lang:check
  it — 419 message(s), 143 key(s) used across 64 file(s)
    nothing missing
    6 computed key(s) — not checked, and not guessed at
```

It does not evaluate anything. `$t->get($key)` and `$t->get('admin.' . $suffix)` are **reported as
dynamic**. A tool that guessed would produce a missing list containing keys that do not exist, and
that list would then be ignored — which is worse than not having one.

The **receiver has to be named** (`$t`, `$translator`, `$i18n`, `$lang`, configurable). Matching a
bare `->get('…')` reported `$view->get('main')` and `$form->get('email')` as missing translations —
found by running it against a real codebase, not by reasoning about it.

`Extractor::untranslated($en, $it)` is the translator's worklist; `identical()` surfaces messages that
are the same in both, which is either a real cognate or a line somebody copied and never translated.
The tool cannot tell them apart and does not pretend to.

## Slugs, in the language of the URL

```php
Slug::of('Grönland', 'de');              // 'groenland'  — German writes ö as oe
Slug::of('Grönland', 'it');              // 'gronland'
Slug::of('Peter Høeg', 'it');            // 'peter-hoeg'
Slug::of('Ærø', 'da');                   // 'aeroe'      — Danish writes ø as oe, å as aa
Slug::of("Dell'onda & altro", 'it');     // 'dell-onda-e-altro' — & is the language's "and"
Slug::of('Il senso di Smilla per la neve', 'it', 20);   // 'il-senso-di-smilla' — cut at a hyphen
```

The language's own spelling of its letters, then ICU's `Any-Latin; Latin-ASCII`, then one hyphen for every run
of anything that is not a letter or a digit — apostrophes too. Without ext-intl a table baked from ICU
(`data/latin-ascii.php`, written by `ix i18n:bake` with the CLDR tables) covers the whole Latin script, and
`SlugTest` checks it against ICU character by character; Cyrillic, Greek, Han and the rest are refused with
an exception naming the extension, never dropped into an empty slug. `Slug::of(…, use_intl: false)` forces the
portable branch, for the same reason `using_engine('portable')` exists.

## Catalogues for a JavaScript front end

`$translator->in($lang)->group('ui')` is one branch of the catalogue, nested, the fallback locale merged
underneath key by key: what a build step writes to `ui.{lang}.json` for an SPA, so the strings live in one place
and TypeScript checks every key the components read.

## Requirements

`php >= 7.4`, `ext-mbstring`, `italix/contracts`, `italix/encode`. `ext-intl` is *suggested*: with it
you get every locale and every formatter, without it the baked tables. Tested against ICU 66.

`italix/contracts` is a `require` because `Translator implements Italix\Contracts\Translator` — an
interface that is missing at load time is a fatal error, not a degraded feature. It carries interfaces
only and requires nothing itself.

`italix/encode` is a `require` rather than a `suggest` because `e()` returns one of its types, and a
method that fatals when somebody calls it is not an optional feature. It requires only `php >= 7.4`
itself, so nothing transitive arrives — but it does ship `bin/encode-lint`, which will appear in
`vendor/bin` whether or not your project has templates.
