<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SynchronizationBehavior;

use Spryker\Zed\Kernel\AbstractBundleConfig;

class SynchronizationBehaviorConfig extends AbstractBundleConfig
{
    protected const MAPPINGS_DELIMITER = ';';

    /**
     * @uses \Propel\Generator\Model\PropelTypes::CLOB
     */
    protected const DATA_COLUMN_TYPE = 'CLOB';

    /**
     * Specification:
     * - Enables/disables synchronization for all the modules.
     * - This value can be overridden on a per-module basis.
     *
     * @api
     *
     * @return bool
     */
    public function isSynchronizationEnabled(): bool
    {
        return true;
    }

    /**
     * Specification:
     * - If true, then the alias_keys column is added to all the storage tables, for which mappings are defined.
     * - The new column is populated with JSON object, containing mapping keys and their respective mapping data for each resource.
     *
     * @api
     *
     * @return bool
     */
    public function isAliasKeysEnabled(): bool
    {
        return true;
    }

    /**
     * Specification:
     * - Returns the delimiter used to separate multiple mappings in schema configuration.
     *
     * @api
     *
     * @phpstan-return non-empty-string
     *
     * @return string
     */
    public function getMappingsDelimiter(): string
    {
        return static::MAPPINGS_DELIMITER;
    }

    /**
     * Specification:
     * - Returns Propel's type for `data` column.
     *
     * @api
     *
     * @return string
     */
    public function getDataColumnType(): string
    {
        return static::DATA_COLUMN_TYPE;
    }
}
