<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix I18n - CatalogLoader
 *
 * @package Italix\I18n
 */

declare(strict_types=1);

namespace Italix\I18n;

/**
 * Where messages come from.
 *
 * PHP files today; a database, a `.po` importer or a remote service are
 * implementations, not changes to this library.
 */
interface CatalogLoader
{
    /**
     * @return Catalog[] one per locale found
     */
    public function load(): array;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
