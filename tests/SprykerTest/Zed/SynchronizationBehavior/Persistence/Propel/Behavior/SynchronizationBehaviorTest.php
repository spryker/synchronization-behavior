<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\SynchronizationBehavior\Persistence\Propel\Behavior;

use Codeception\Test\Unit;
use Codeception\Util\Stub;
use Propel\Generator\Model\Table;
use Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\InvalidConfigurationException;
use Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\MissingAttributeException;
use Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\SynchronizationBehavior;
use Spryker\Zed\SynchronizationBehavior\SynchronizationBehaviorConfig;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group SynchronizationBehavior
 * @group Persistence
 * @group Propel
 * @group Behavior
 * @group SynchronizationBehaviorTest
 * Add your own group annotations below this line
 */
class SynchronizationBehaviorTest extends Unit
{
    /**
     * @var \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\SynchronizationBehavior
     */
    protected $synchronizationBehavior;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSynchronizationBehavior();
    }

    /**
     * @dataProvider mappingsDataProvider
     *
     * @param string $expectedMappingsString
     * @param array $mappingsConfiguration
     *
     * @return void
     */
    public function testsBuildsCorrectMappings(string $expectedMappingsString, array $mappingsConfiguration): void
    {
        // Arrange
        $this->synchronizationBehavior->addParameter($mappingsConfiguration);

        // Act
        $result = $this->synchronizationBehavior->objectMethods();

        // Assert
        $this->assertStringContainsString($expectedMappingsString, $result);
    }

    /**
     * @return array
     */
    public function mappingsDataProvider(): array
    {
        return [
            'single mapping' => [
                '$mappings = [
        [
            \'source\' => \'foo\',
            \'destination\' => \'bar\',
        ],
    ];',
                [
                    'name' => 'mappings',
                    'value' => 'foo:bar',
                ],
            ],
            'multiple mappings' => [
                '$mappings = [
        [
            \'source\' => \'foo\',
            \'destination\' => \'bar\',
        ],
        [
            \'source\' => \'baz\',
            \'destination\' => \'foobar\',
        ],
        [
            \'source\' => \'foobar\',
            \'destination\' => \'baz\',
        ],
    ];',
                [
                    'name' => 'mappings',
                    'value' => 'foo:bar;baz:foobar;foobar:baz',
                ],
            ],
        ];
    }

    /**
     * @return void
     */
    public function testExceptionIsThrownWhenNoMappingValueIsProvided(): void
    {
        $this->expectException(MissingAttributeException::class);

        $mapping = [
            'name' => 'mappings',
        ];
        $this->synchronizationBehavior->addParameter($mapping);

        $this->synchronizationBehavior->objectMethods();
    }

    /**
     * @return void
     */
    public function testExceptionIsThrownWhenMappingValueIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $mapping = [
            'name' => 'mappings',
            'value' => 'foobar',
        ];
        $this->synchronizationBehavior->addParameter($mapping);

        $this->synchronizationBehavior->objectMethods();
    }

    /**
     * @return void
     */
    protected function setUpSynchronizationBehavior(): void
    {
        $this->synchronizationBehavior = new SynchronizationBehavior();
        $this->synchronizationBehavior->setConfig($this->createSynchronizationBehaviorConfigMock());
        $this->synchronizationBehavior->setTable(new Table());
        $this->synchronizationBehavior->setParameters([
            'queue_group' => [
                'value' => 'queue_group',
            ],
            'resource' => [
                'value' => 'resource',
            ],
        ]);
    }

    /**
     * @param string $mappingsDelimiter
     *
     * @return \Spryker\Zed\SynchronizationBehavior\SynchronizationBehaviorConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createSynchronizationBehaviorConfigMock($mappingsDelimiter = ';'): SynchronizationBehaviorConfig
    {
        return Stub::make(SynchronizationBehaviorConfig::class, [
            'getMappingsDelimiter' => $mappingsDelimiter,
        ]);
    }
}
