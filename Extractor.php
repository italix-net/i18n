<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - Extractor
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * Which keys the code asks for, and which the catalogues answer.
 *
 * Translation files rot in two directions and neither is visible from reading
 * them: a key used in a template that nobody ever translated shows the raw key
 * to a user, and a key left behind after the screen was deleted is dead weight a
 * translator keeps paying for.
 *
 * ## What this deliberately does not do
 *
 * It does not evaluate anything. `$t->get($key)` and `$t->get('a.' . $suffix)`
 * are **reported as dynamic**, not guessed at. A tool that guessed would produce
 * a "missing" list containing keys that do not exist and an "unused" list
 * missing keys that do, and both lists would then be ignored — which is worse
 * than not having them.
 */
final class Extractor
{
    /**
     * Variable names that hold a translator.
     *
     * **The receiver has to be named**, and finding that out cost a run against
     * a real codebase: matching a bare `->get('…')` reported `$view->get('main')`,
     * `$form->get('email')` and `$context->get('data')` as missing translations.
     * A report that is mostly noise is a report nobody reads, which is the same
     * outcome as having no report — only more expensive.
     */
    public const DEFAULT_RECEIVERS = ['t', 'translator', 'i18n', 'lang'];

    /**
     * Class names a translator is reached through statically.
     *
     * `T::e('admin.title')` is invisible to the receiver patterns above, because
     * there is no `$t` in it. Left unlisted, a codebase that adopts the facade
     * loses this tool without being told: `lang:check` keeps reporting "nothing
     * missing" while checking a shrinking fraction of the keys, and a typo
     * renders the key itself on the page.
     *
     * Named for the same reason receivers are named: a bare `::e('…')` would
     * match anything.
     */
    public const DEFAULT_FACADES = ['T'];

    /** @var string[] */
    private array $receivers;

    /** @var string[] */
    private array $facades;

    /**
     * A key that is computed rather than written.
     *
     * Two shapes, and the second is the one that bites: `get($key)` is obviously
     * dynamic, but `get('admin.' . $suffix)` *starts* with a quoted string and
     * a pattern that stops at the closing quote happily extracts `admin.` as a
     * key. Requiring the literal to be followed by `,` or `)` is what tells the
     * two apart — measured, after a test caught `admin.` in the key list.
     */
    /** @var string[] built from the receiver list */
    private array $patterns;

    /** @var string[] */
    private array $dynamic_patterns;

    /** @var string[] */
    private array $extensions;

    /** @var string[] */
    private array $skip_dirs;

    /**
     * @param string[] $extensions
     * @param string[] $skip_dirs
     */
    /**
     * @param string[] $extensions
     * @param string[] $skip_dirs
     * @param string[] $receivers variable names holding a translator
     * @param string[] $facades   class names a translator is reached through statically
     */
    public function __construct(
        array $extensions = ['php'],
        array $skip_dirs = ['vendor', 'node_modules', '.git'],
        array $receivers = self::DEFAULT_RECEIVERS,
        array $facades = self::DEFAULT_FACADES
    ) {
        $this->extensions = array_map('strtolower', $extensions);
        $this->skip_dirs  = $skip_dirs;
        $this->receivers  = $receivers;
        $this->facades    = $facades;

        // `$t->get(…)` and `$this->t->get(…)`, and nothing else.
        $who = implode('|', array_map(static function (string $one): string {
            return preg_quote($one, '~');
        }, $receivers));

        $call = '(?:\\$(?:' . $who . ')|->\\s*(?:' . $who . '))\\s*->\\s*';

        // `T::e(…)`, `\Italix\I18n\T::choice_e(…)`. The encoding variants are
        // here too: they are the same lookup with the result typed, and a key
        // only reachable through e() is still a key.
        $facade = $facades === []
            ? null
            : '(?:\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*)?(?:' . implode('|', array_map(
                static function (string $one): string {
                    return preg_quote($one, '~');
                },
                $facades
            )) . ')\\s*::\\s*';

        $methods_c = '(?:get|choice_e|choice|has|e)';

        $this->patterns = [
            '~' . $call . '(?:get|choice|has)\\s*\\(\\s*([\'"])((?:(?!\\1).)*)\\1\\s*[,)]~',
        ];

        $this->dynamic_patterns = [
            '~' . $call . '(?:get|choice)\\s*\\(\\s*(?:[^\'")\\s]|([\'"])(?:(?!\\1).)*\\1\\s*\\.)~',
        ];

        if ($facade !== null) {
            $this->patterns[] =
                '~' . $facade . $methods_c . '\\s*\\(\\s*([\'"])((?:(?!\\1).)*)\\1\\s*[,)]~';

            $this->dynamic_patterns[] =
                '~' . $facade . $methods_c . '\\s*\\(\\s*(?:[^\'")\\s]|([\'"])(?:(?!\\1).)*\\1\\s*\\.)~';
        }
    }

    /** Whether a line computes its key rather than writing it. */
    private function is_dynamic(string $line): bool
    {
        foreach ($this->dynamic_patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function receivers(): array
    {
        return $this->receivers;
    }

    /**
     * Every literal key used under these roots.
     *
     * @param string[] $roots
     *
     * @return array{keys: array<string, string[]>, dynamic: string[], files_n: int}
     *         keys: key => where it was seen; dynamic: places a key was computed
     */
    public function scan(array $roots): array
    {
        $keys    = [];
        $dynamic = [];
        $files_n = 0;

        foreach ($roots as $root) {
            foreach ($this->files_under($root) as $file) {
                $files_n++;
                $source = (string) file_get_contents($file);
                $lines  = explode("\n", $source);

                foreach ($lines as $i => $line) {
                    $where = $file . ':' . ($i + 1);

                    foreach ($this->patterns as $pattern) {
                        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === false) {
                            continue;
                        }

                        foreach ($matches as $match) {
                            $key_c = $match[2];

                            // A key is a dot path: no spaces, no empty
                            // segments, no trailing dot. The trailing-dot rule
                            // is what stops half a concatenation being taken
                            // for a key.
                            if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9_-]*(\.[A-Za-z0-9_][A-Za-z0-9_-]*)*$~', $key_c) === 1) {
                                $keys[$key_c][] = $where;
                            }
                        }
                    }

                    if ($this->is_dynamic($line)) {
                        $dynamic[] = $where;
                    }
                }
            }
        }

        ksort($keys);

        return ['keys' => $keys, 'dynamic' => $dynamic, 'files_n' => $files_n];
    }

    /**
     * What the code needs that a catalogue does not have, and the other way
     * round.
     *
     * @param string[] $roots
     *
     * @return array{missing: array<string, string[]>, unused: string[], dynamic: string[], used_n: int, files_n: int}
     */
    public function compare(array $roots, Catalog $catalog): array
    {
        $scan    = $this->scan($roots);
        $missing = [];

        foreach ($scan['keys'] as $key_c => $where) {
            if (!$catalog->has($key_c)) {
                $missing[$key_c] = $where;
            }
        }

        $unused = array_values(array_diff($catalog->keys(), array_keys($scan['keys'])));
        sort($unused);

        return [
            'missing' => $missing,
            'unused'  => $unused,
            'dynamic' => $scan['dynamic'],
            'used_n'  => count($scan['keys']),
            'files_n' => $scan['files_n'],
        ];
    }

    /**
     * Keys one catalogue has and another does not — the translator's worklist.
     *
     * @return string[]
     */
    public static function untranslated(Catalog $source, Catalog $target): array
    {
        $missing = array_values(array_diff($source->keys(), $target->keys()));
        sort($missing);

        return $missing;
    }

    /**
     * Keys whose message is identical in both catalogues.
     *
     * Usually one of two things, and both are worth seeing: a word that is
     * genuinely the same in both languages, or a line somebody copied across and
     * never translated. The tool cannot tell them apart and does not pretend to.
     *
     * @return string[]
     */
    public static function identical(Catalog $a, Catalog $b): array
    {
        $same = [];

        foreach ($a->all() as $key_c => $message) {
            if ($b->get($key_c) === $message) {
                $same[] = $key_c;
            }
        }

        sort($same);

        return $same;
    }

    /**
     * @return string[]
     */
    private function files_under(string $root): array
    {
        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            throw new I18nException("There is nothing to scan at: {$root}");
        }

        $out      = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $item): bool {
                    return !$item->isDir() || !in_array($item->getFilename(), $this->skip_dirs, true);
                }
            )
        );

        foreach ($iterator as $item) {
            if ($item->isFile()
                && in_array(strtolower($item->getExtension()), $this->extensions, true)) {
                $out[] = (string) $item->getPathname();
            }
        }

        sort($out);

        return $out;
    }
}
