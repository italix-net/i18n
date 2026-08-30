<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - factory functions
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

if (!function_exists(__NAMESPACE__ . '\translator')) {

    /**
     * Load `lang/{locale}/*.php` and hand back a translator.
     *
     * The common case in one line, because the assembly — find the directory,
     * load every locale, pick one, remember the fallback — is the same in every
     * application and getting it slightly wrong is silent.
     */
    function translator(string $lang_dir, string $locale_c, string $fallback_c = 'en'): Translator
    {
        return new Translator((new PhpDirectoryLoader($lang_dir))->load(), $locale_c, $fallback_c);
    }
}

if (!function_exists(__NAMESPACE__ . '\formatter')) {

    function formatter(string $locale_c, string $timezone_c = 'UTC'): Formatter
    {
        return new Formatter($locale_c, $timezone_c);
    }
}
