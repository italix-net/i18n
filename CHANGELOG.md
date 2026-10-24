# Changelog — italix/i18n

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.1.0] — 2026-10-24

### Added

- `Slug::of($text, $locale_c, $max_n)` — a URL slug in the language of the URL: the language's own spelling of
  its letters (`de`: ä ö ü ß → ae oe ue ss; `da`/`nb`/`nn`/`no`: æ ø å → ae oe aa), `&` as the language's "and",
  then ICU's `Any-Latin; Latin-ASCII`, apostrophes as separators. Without ext-intl a baked table
  (`data/latin-ascii.php`, `Slug::bake()`/`render()`/`is_current()`, written by `ix i18n:bake`) covers the Latin
  script; other scripts are refused. `SlugTest` (45), the portable branch compared with ICU over all 688
  characters of the table; two mutations caught.

## [2.0.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only method names changed, no behavior. This library carries most of
the renames in the whole codebase, because most of it genuinely is bounded codes (locales, plural
categories, text direction):

- `Engine::name_c()` → `name_code()`, `Engine::plural_category_c()` → `plural_category_code()` —
  interface methods; `IcuEngine` and `PortableEngine` renamed their overrides to match
- `Formatter::engine_c()` → `engine_code()`, `locale_c()` → `locale_code()`,
  `direction_c()` → `direction_code()`
- `Locale::direction_c()` → `direction_code()` (static)
- `Catalog::locale_c()` → `locale_code()`
- `T::locale_c()` → `locale_code()` (static)
- `Translator::locale_c()` → `locale_code()`, `fallback_c()` → `fallback_code()` — also
  implements `Italix\Contracts\Translator`, whose own `locale_c()` renamed the same way; requires
  `italix/contracts` `^2.0` now (was `^1.0`)
- `TranslationUnit::state_c()` → `state_code()`; `key_c()` → `key()` — a catalog key like
  `admin.users.title` is closer to an identifier path than a bounded code
- `Parsed::error_c()` → `error_code()`, `currency_c()` → `currency_code()`

## [1.6.1] — 2026-08-17

### Fixed

- **`italix/contracts` was missing from `require`.** `Translator implements
  Italix\Contracts\Translator` since 1.2.0, and an interface that is absent at load time is a fatal
  error, not a degraded feature — so `composer require italix/i18n` on its own produced a package
  that dies on first use. It was never noticed because the application that grew this library
  autoloads all of them from one PSR-4 map and never resolves the per-package manifests. Found by
  comparing declared requirements against the namespaces each package actually references.

- **The README still said `ext-intl` was required**, and `Translator`'s docblock still called it a
  requirement rather than a suggestion — stale since 1.5.0, where `PortableEngine` made it optional.
  The first page an adopter reads was asking for an extension the library does not need.

## [1.6.0] — 2026-08-16

A translated message has two halves and only one of them is trusted. 122 assertions.

### Added

- **`Translator::e()` and `Translator::choice_e()`** — the message as an `Italix\Encode\Html`, with
  the **parameters encoded and the template left alone**.

  ```php
  // lang/it/messages.php
  'subtitle' => 'Order for <strong>{customer}</strong>',

  <?= $t->e('orders.subtitle', ['customer' => $row['customer_name']]) ?>
  // Order for <strong>&lt;script&gt;…&lt;/script&gt;</strong>
  ```

- **`T`, a facade over the translator of the current request.**

  ```php
  use Italix\I18n\T;

  <h1><?= T::e('admin.subjects.title') ?></h1>
  ```

  Not sugar for `$t->e()`. `$t` is only present where somebody remembered to pass it, and threading a
  translator through three layers to translate one word is enough friction that the word gets written
  in Italian instead — which is how the codebase this was built for grew a CSV import wizard with
  forty hard-coded strings and not one translation key.

  `T::for_locale($locale_c, $work)` runs something in another language and puts the facade back in
  a `finally` — the e-mail to a recipient who reads something else, composed from a partial shared
  with the page, which has no injected translator to switch. Restoring outside the `finally` passes
  every test anybody writes; the suite asserts the throwing path specifically.

  `bind()` belongs in the middleware that sets the locale, **on every request**. Not once at boot: on
  a resident worker a request that does not rebind is answered in the language of the request before
  it. Calling anything before `bind()` throws rather than falling back to a default language, because
  a page in the wrong language looks like a translation bug and gets reported as one, months later,
  by a customer.

- **`Translator::group()`** — a whole branch of the catalogue, nested, for handing to something that
  is not PHP. `get()` answers one key, which is right for a template and wrong for a client-side
  mirror that needs the sentence for every code a rule can return. Messages are not formatted: the
  receiver has the parameters, we do not. The fallback locale is merged underneath, so a branch that
  is half translated still arrives whole.

### The gap this closes

`get()` returns one string, so a caller printing a message had two options and both were wrong for
somebody:

```php
H::e($t->get('k', $params))     // safe, and destroys any markup the message carries
H::raw($t->get('k', $params))   // keeps the markup, and prints the parameter unencoded
```

The second is not hypothetical. It is what a developer reaches for on the day a translator writes
`<strong>{customer}</strong>` and the page shows `&lt;strong&gt;` in plain sight — the fix looks like a
rendering fix, and the parameter it lets through comes from a form.

### The measurement that changed the implementation

The first version skipped encoding for `int` and `float`, reasoning that `'3'` would break ICU's
plural selector. Measured against `ext-intl`, that is false: `plural`, `number`, `currency` and
`percent` all coerce a numeric string identically to the number, scientific notation included. The
special case was deleted rather than documented, and the suite asserts selection still works for
0, 1 and 5.

### Changed

- **`italix/encode` moves from nothing to `require`.** `e()` returns an `Html`, so the type is in
  this package's public API and a `suggest` would be a method that fatals when somebody calls it.
  The cost is small and worth stating: `italix/encode` itself requires only `php >= 7.4`, so nothing
  transitive arrives — but it ships `bin/encode-lint`, which now appears in `vendor/bin` for every
  project that installs i18n, template engine or not.

### Upgrading

Nothing changes for existing callers: `get()`, `choice()` and `format_message()` return the same
strings they returned before, which is what keeps them usable from a mailer, a CSV writer and a CLI
where HTML encoding would be wrong. `e()` is the one that knows it is writing into a page.

## [1.5.0] — 2026-08

### Added

- **`ext-intl` is no longer required.** It moves from `require` to `suggest`. When it is present the
  library uses it; when it is not, `PortableEngine` reads tables **baked from ICU** and answers the
  same thing.

  This was the one place a comparable framework did better. The official `php:8.x` images do not
  include intl, so the requirement was the friction everybody met in their first five minutes.

- **`Engine`, `IcuEngine`, `PortableEngine`** — one seam, chosen once at construction.
  `Formatter::engine_c()` says who is answering; `using_engine('portable')` forces the other.

  Forcing is not a curiosity. On any machine with ICU the portable branch would otherwise never
  execute, and code that never executes is code that does not work. The suite forces both.

- **`Baker` and `ix i18n:bake`** — the tables are **ICU's own answers, written down**, not a
  reimplementation of its opinions. Nobody would guess that `de-AT` groups its numbers with a space
  and its money with a full stop, or that Bulgarian groups the first and not the second.

  `--check` fails when the committed file no longer matches the local ICU: the one failure mode this
  design has is a *stale* answer, and a stale answer formats last year's way with no sign of it.

  Plural categories are sampled rather than derived — the CLDR rule is not reachable through PHP's
  API — and the sampling is sound only because the rules are periodic. That is **verified**, not
  assumed: 4,800 values per locale, and a locale that broke it would be refused rather than written
  down wrong.

- **`tests/EngineTest.php`** — 26 assertions, and the reason any of this can be trusted: **6,144
  plural categories, 891 renderings and 77 messages, identical byte for byte** across 33 locales.
  A fallback that is nearly right is worse than none, because the same catalogue renders one way on
  a laptop and another on a server and the page does not look broken.

  Writing it found five real defects, none of which reading either implementation would have found:

  | | |
  |---|---|
  | **currency symbols are locale-dependent** | `USD` is `$` in English, `USD` in Italian, `$US` in French, `US$` in Swedish — and `getSymbol()` returns the placeholder `¤` whatever currency is set |
  | **de-AT** | the *monetary* separator symbols are a different pair from the plain ones |
  | **Bulgarian** | groups its numbers and not its money; its currency pattern has no `#,##` at all |
  | **CLDR currency spacing** | a space appears between an alphabetic symbol and a digit — `CHF 1,000.00` from a pattern with no space in it |
  | **Slavic and Greek months** | `MMMM` and `LLLL` are different words: "13 sierpnia" inside a date, "sierpień" standing alone. And Greek writes μ.μ., not PM |

  The last four were caught by the **bake refusing to write** what it could not verify by reassembly,
  which is the check being worth its keep before the tests even ran.

### Changed

- The portable engine **refuses** collation, spellout, display names, relative times, non-Gregorian
  calendars, nested plural/select, and any message containing an apostrophe — each with a reason.

  The apostrophe is the sharpest: to ICU `'` is a quoting character, so a sub-parser that treated it
  even slightly differently would render **the same catalogue two ways** depending on which engine
  ran. That is the worst failure available here, and refusing is the only honest answer.

  Collation is the one that looks easiest and is not: Swedish sorts Å/Ä/Ö *after* Z, so the obvious
  shortcut of stripping accents gives correct Italian and wrong Swedish — a confident wrong answer,
  which is what this library exists to remove.


### Legal

- **Licensed under MPL-2.0**, applied 2026-08-13: the `license` field in `composer.json`, a `LICENSE`
  file, and the Exhibit A notice in every source file — MPL §1.4 defines "Covered Software" per file,
  so the per-file header is what makes the licence apply rather than decoration.

  This is a **first declaration, not a relicensing.** The package carried no licence at all before,
  which in most jurisdictions means all rights reserved: nothing had been granted, so nothing is
  taken away and no consumer's position gets worse. That is why it is recorded here rather than
  treated as a breaking change — unlike `italix/orm`, which went Apache-2.0 → MPL-2.0 and took a
  MAJOR because that direction does narrow what a consumer already had.

## [1.4.0] — 2026-08

The half that turns a catalogue into something a professional translator can be handed: a merge, and
the two formats their tools read.

### Added

- **`Merge`** — the reconciliation, and the reason for all the rest. This is `msgmerge`, the one idea
  worth taking from thirty years of gettext.

  | source | existing translation | result |
  |---|---|---|
  | new key | — | added, `initial`, empty |
  | unchanged | translated | kept, untouched |
  | **reworded** | translated | **kept and marked `needs-review`**, old source in a note |
  | gone | anything | **kept, marked obsolete** |

  The third row is the point. Without it a translation file has two futures and both are bad:
  regenerate and lose work somebody was paid for, or hand-patch and drift. The fourth means that
  undoing a change next week does not cost a translation.

  `Merge::to_catalog()` ships **only what is safe to show** — an outdated or untranslated message is
  left out, so the fallback chain answers instead of a page displaying a sentence that no longer
  matches its original with nothing to say so.

- **`TranslationUnit`** — key, source, target, state, notes, references, obsolete flag. The runtime
  catalogue holds only the translated text, which is all a page needs and not enough to *maintain* a
  translation: without the source nobody can tell the original has been reworded.

- **`Xliff`** — XLIFF 2.0, the OASIS format Trados, memoQ, Phrase, Crowdin, Weblate and Lokalise all
  import.

  **Preferred over PO for this library**, and the reason is structural: PO identifies a message by its
  source text, ours by a key. Under PO, fixing a comma in the Italian original orphans the German
  translation of that string. XLIFF has `<unit id="…">`, which is exactly our key.

  XLIFF 2.0 has no `fuzzy`, so `needs-review` rides in `subState` — the standard's own extension
  point — and survives a tool that does not understand it instead of being erased.

- **`Po`** — GNU gettext, because some translators and some pipelines want nothing else, and refusing
  the format they own is refusing the translator.

  The key travels in `msgctxt` and the source stays in `msgid` where a translator reads it. Every PO
  tool understands `msgctxt`; none has to know we use it for identity rather than for context.

- **`ix lang:sync <locale>`** — the verb. Reconciles the working file, reports what is new, what needs
  a second look and what was retired. `--import` writes `messages.php` from it, `--format=po` for
  gettext, `--list` for a status line per locale.

  A locale that already has a `messages.php` but no working file has its translations **adopted**
  rather than discarded, taking the current source as theirs — nothing else is knowable, and claiming
  they all need review would flag an entire catalogue on day one.

## [1.3.0] — 2026-08

### Added

- **`Locale::is_rtl()` and `direction_c()`**, plus `Formatter::direction_c()` / `is_rtl()` — for the
  `dir` attribute, and for anything that lays out rather than merely prints.

  Direction belongs to the **script**, not to the language: `az-Arab` is right to left and `az-Latn`
  is not, and the same holds for Kurdish and Uzbek. A locale naming its script is answered exactly;
  only one that does not falls back to a list of fourteen languages. `ext-intl` exposes no direction
  of its own — checked on the target build.

- **`Formatter::currency_minor(int $minor_n, string $currency_c)`** — an amount held as integer
  cents, pence or øre, so an application can keep money out of floats. `23 × 0.10` is
  `2.30000000000000071054` in IEEE 754, and once such a value has been added a few times the error
  is in the total rather than in the display.

  The exponent comes from **the currency being formatted**, not from the locale's own — a distinction
  that cost a real defect during development: reading `FRACTION_DIGITS` off a plain `it-IT` formatter
  gives EUR's 2, so `currency_minor(1000, 'JPY')` printed `10 JPY` instead of a thousand yen. JPY has
  no minor unit and TND has three.

- **`Formatter::date_traditional()`** — the calendar a locale records as its own: Buddhist years in
  Thailand, Persian in Iran, Hijri in Saudi Arabia.

  It exists to close a silent trap. Without `IntlDateFormatter::TRADITIONAL` as the **fifth**
  constructor argument, ICU formats in the Gregorian calendar and reports no error, *even when the
  locale explicitly names another one*. Measured: the same `th-TH` locale gives `2026` without it and
  `2569` with it. A wrong year that looks like a right year is noticed by a user, not by a test.

  «Traditional» means what CLDR records, which is not always the historical calendar: for `ja-JP` it
  is Gregorian, and the imperial era must be asked for by name — `ja-JP-u-ca-japanese` gives
  `令和8年8月12日`. Stated here because the opposite is easy to assume.

- **`Catalog::pseudo()`** — a defaced copy of the catalogue, for finding what was never translated.

  `'Salva le modifiche'` becomes `'⟦Šàľvà ľê mõđíƒíçhê ·····⟧'`. Three things fall out of a page
  rendered in it: a string that still reads normally never came from the catalogue; text that
  overflows shows which layouts assume English lengths (German and Finnish run ~30% longer, which is
  what the padding simulates); and a missing closing bracket marks a truncated string.

  ICU has no part in this: `en-XA` is a CLDR *seed* pseudo-locale absent from the standard data —
  measured, ICU falls back to plain English — so the transformation is ours, applied to our own
  source text. **Placeholders and ICU constructs are left intact**, since defacing `{count}` would
  break the message it is meant to test.

### Fixed

- **`Locale::normalise()` mangled BCP-47 extensions.** `ja-JP-u-ca-japanese` came back as
  `ja-JP-u-CA-japanese`: after the `u` singleton, `ca` was read as a region subtag and upper-cased,
  naming Canada in a request for the Japanese calendar. Everything following a single-character
  subtag is extension content now and is passed through lower-cased, per BCP-47.

### Not added, and why

- **List formatting** — "Rossi, Bianchi **e** Verdi". PHP's `ext-intl` exposes no list formatter
  (`IntlListFormatter` does not exist on the target build, checked), so the conjunction would have to
  be shipped by this library for every language it claims to serve. That is shipping translations,
  which `relative_parts()` already refuses for the same reason. The application has a catalogue and
  can say it in three lines; the note is in `Formatter` next to where it would have gone.

## [1.2.0] — 2026-08

### Added

- **`Translator` implements `Italix\Contracts\Translator`**, and gained `locale()` as an alias of
  `locale_c()` — the spelling `Italix\Mvc\Translator` uses, so a view written against one keeps
  working against the other. Not deprecated: it is the one thing standing between an application and
  a one-line swap.

- **`Formatter::compare()`, `sorted()`, `sorted_by()`** — ordering by language instead of by byte.

  PHP's own comparison puts every accented letter after `Z`, which is wrong in every language that
  has any. Measured on five surnames:

  ```
  sort() PHP     Ortiz, Zani, Ärling, Åberg, Özdemir
  Italian        Åberg, Ärling, Ortiz, Özdemir, Zani
  Swedish        Ortiz, Zani, Åberg, Ärling, Özdemir
  ```

  Italian and Swedish disagree and **both are right**: in Swedish, Å, Ä and Ö are letters in their
  own right and come after Z. No accent-stripping approximation produces both answers, which is why
  this defers to ICU rather than inventing a rule.

  `sorted()` returns a copy rather than sorting in place — a function that quietly rearranges its
  argument is a surprise. `sorted_by()` keeps the array keys, so a row can still be found by the id
  it came with, and reads each sort key once instead of once per comparison.

  The `Collator` is built on first use and cached: constructing one is not free and lists ask often.

## [1.1.0] — 2026-08

The direction that was missing: reading localised input back. Plus display names, and a defect fix
in `problems()`.

### Added

- **`Formatter::parse_number()`, `parse_integer()`, `parse_currency()`, `parse_date()`,
  `parse_date_time()`** — and a **`Parsed`** outcome carrying a machine `error_c()` rather than a
  `false` that cannot be told from zero.

  Two reasons this is not a one-line wrapper around ICU:

  - `(float) "1.234,50"` is **1.234** in PHP. An Italian typing one thousand two hundred into a form
    gets one and a bit, silently.
  - **ICU stops at the first character it cannot use and reports success** — measured, `"12,5x"`
    parses to `12.5` with `U_ZERO_ERROR`. So the parse position is checked against the end of the
    input, and a typo is refused instead of becoming a number.

  That check compares **characters, not bytes**: ICU's position counts code points, and Swiss and
  French groupings use multi-byte separators (`1’234.50` is 10 bytes and 8 characters). A
  `strlen()` comparison would reject every correctly typed Swiss amount — verified by mutation.

  `parse_date()` parses **non-leniently**: `31/02/2026` is refused rather than rolled over into
  March. `parse_integer()` refuses `2,7` rather than truncating it — deciding that 2,7 means 2 is
  the caller's business.

- **`Formatter::region_name()`, `language_name()`, `currency_name()`** — ICU has the tables, so no
  translated country list has to be maintained by hand.

  `region_name()` pads a bare code to `und-XX`, because ICU's `getDisplayRegion` handed a bare `DE`
  returns an **empty string** rather than an error. `currency_name()` goes through the `¤¤¤`
  pattern, since PHP does not expose `ucurr_getName` — and strips a **non-breaking** space, which
  `trim()` does not touch.

### Fixed

- **`problems()` now reports a parameter that was never supplied.** The class documented three ways
  a lookup can go wrong, all readable in `problems()`; the third did not work. When a named argument
  is missing ICU does **not** return `false` — it returns the string with `{name}` still inside — so
  the failure branch never fired and the placeholder reached the page in silence.

  Measured before the fix: a message using `{count}` called with `n` came back as the literal
  `{count}` while `problems()` stayed empty.

  Detection reads the **output**, not the message: telling an argument from a plural branch
  (`other {parti}`) needs a real ICU parser, and a wrong guess would invent problems that do not
  exist.

## [1.0.0] — 2026-08

Phase 4.4 of `FRAMEWORK-FUTURE.md`. Replaces nothing: `Italix\Mvc\Translator` stays where it is and
keeps doing locale-aware URLs, which this library has no opinion about.

### Added

- **`Translator`** — ICU MessageFormat, fallback chain, and three failure modes that are all
  recorded and none of which break a page: a missing key returns the key, an unparseable message
  returns the raw message, a missing parameter leaves the placeholder. `problems()` lists them.

- **`ext-intl` as a hard requirement.** Plural categories belong to a language: Russian has four,
  Arabic six, Japanese one, and 21 in Russian takes the *one* category. A library that gets that
  wrong quietly is worse than one that refuses to start.

- **`Catalog`** — nested files flattened once to dot paths, immutable, with `merge()` for layering an
  application's overrides over a package's defaults.

- **`Formatter`** — numbers, currency, percent, ordinals, spelled-out numbers, IEC byte sizes, dates
  by style or ICU pattern, and `relative_parts()`.

- **`PhpDirectoryLoader`** — `lang/{locale}/{group}.php`, where `messages.php` contributes no prefix.

- **`Locale`** — normalisation, fallback chains, `best_of()`, `Accept-Language` parsing. The chain
  reaches a related language before the default: German before English for an Austrian visitor.

- **`Extractor`** and `ix lang:check` — which keys the code uses, which messages exist, which are
  missing, unused or computed.

### Decisions worth restating

- **`percent()` keeps three fraction digits by default.** ICU's own default is zero, so `0.075`
  formats as `8%` — measured. A rate rounded from 7.5 to 8 reaches an invoice.

- **A message with no braces never touches the ICU parser**, because `'` is a quoting character
  there and `"L'utente"` would lose letters.

- **Relative time is computed, not worded.** `IntlRelativeDateTimeFormatter` does not exist in PHP,
  and inventing the wording would mean shipping translations for every language this claims to
  support.

### Found by testing, not by reasoning

- **`is_scalar()` is true for booleans.** The first `Catalog` accepted `true` and flattened it to
  `"1"` — a message that reaches production looking like a translation. Now only string, int and
  float are messages.

- **`->get('…')` matches half a PHP framework.** The first extractor reported `$view->get('main')`
  and `$form->get('email')` as missing translations when run against a real codebase. The receiver is
  now named and configurable, and a report that is mostly noise is a report nobody reads.

- **A concatenated key is half a literal.** `$t->get('admin.' . $suffix)` starts with a quoted string,
  and a pattern that stops at the closing quote extracted `admin.` as a key. The literal must now be
  followed by `,` or `)`.

### Testing

`tests/I18nTest.php`, 103 assertions, plain runner. The plural section asserts real CLDR behaviour
across six languages rather than trusting that ICU was wired up correctly.
