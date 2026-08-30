<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - PhpDirectoryLoader
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * `lang/{locale}/{group}.php`, each returning a nested array.
 *
 *     lang/it/messages.php   ->  ['admin' => ['title' => '…']]   ->  admin.title
 *     lang/it/errors.php     ->  ['required' => '…']             ->  errors.required
 *
 * The filename becomes the first path segment **except** for `messages.php`,
 * which is the default group and contributes nothing — so the common case reads
 * `admin.title` rather than `messages.admin.title`, and a project that never
 * needs a second group never sees the concept.
 *
 * PHP files rather than JSON or YAML: they are opcached, they cost nothing to
 * parse on the hundredth request, and a syntax error is caught by `php -l` in
 * a hook rather than at render time.
 */
final class PhpDirectoryLoader implements CatalogLoader
{
    public const DEFAULT_GROUP = 'messages';

    private string $dir;

    /** @var string[]|null */
    private ?array $only;

    /**
     * @param string[]|null $only load just these locales, or all of them
     */
    public function __construct(string $dir, ?array $only = null)
    {
        $real = realpath($dir);

        if ($real === false || !is_dir($real)) {
            throw new I18nException("There is no language directory at: {$dir}");
        }

        $this->dir  = rtrim($real, '/');
        $this->only = $only;
    }

    public function load(): array
    {
        $catalogs = [];

        foreach ((array) glob($this->dir . '/*', GLOB_ONLYDIR) as $locale_dir) {
            $locale_c = basename((string) $locale_dir);

            if (!Locale::is_valid($locale_c)) {
                continue;
            }

            $locale_c = Locale::normalise($locale_c);

            if ($this->only !== null && !in_array($locale_c, array_map(
                static function (string $one): string {
                    return Locale::normalise($one);
                },
                $this->only
            ), true)) {
                continue;
            }

            $messages = [];

            foreach ((array) glob($locale_dir . '/*.php') as $file) {
                $group_c = basename((string) $file, '.php');
                $loaded  = require $file;

                if (!is_array($loaded)) {
                    throw new I18nException("{$file} must return an array.");
                }

                if ($group_c === self::DEFAULT_GROUP) {
                    $messages = array_merge($messages, $loaded);
                } else {
                    $messages[$group_c] = array_merge($messages[$group_c] ?? [], $loaded);
                }
            }

            if ($messages !== []) {
                $catalogs[] = new Catalog($locale_c, $messages);
            }
        }

        return $catalogs;
    }

    public function describe(): string
    {
        $locales = array_map(static function (Catalog $c): string {
            return $c->locale_code() . ' (' . $c->count() . ')';
        }, $this->load());

        return $this->dir . ': ' . ($locales === [] ? 'nothing' : implode(', ', $locales));
    }
}
