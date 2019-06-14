<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior;

use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Unique;
use Propel\Generator\Util\PhpParser;
use Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\InvalidConfigurationException;
use Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\MissingAttributeException;
use Zend\Filter\Word\UnderscoreToCamelCase;

class SynchronizationBehavior extends Behavior
{
    public const ERROR_MISSING_RESOURCE_PARAMETER = '%s misses "resource" synchronization parameter.';
    public const ERROR_MISSING_MAPPING_RESOURCE_PARAMETER = '%s misses "mapping_resource" synchronization parameter.';
    public const ERROR_MISSING_MAPPINGS_PARAMETER = '%s misses "mappings" synchronization parameter.';
    public const ERROR_MUTUALLY_EXCLUSIVE_PARAMETERS = '%s uses mutually exclusive "store" and "queue_pool" synchronization attributes.';
    public const ERROR_INVALID_MAPPINGS_PARAMETER = '%s define incorrect value of mappings parameter.';

    /**
     * @var array
     */
    protected $parameters = [
        'resource' => null,
        'queue_group' => null,
        'queue_pool' => null,
    ];

    /**
     * @return string
     */
    public function preSave()
    {
        return "
\$this->setGeneratedKey();
\$this->setGeneratedKeyForMappingResource();      
\$this->setGeneratedAliasKeys();      
        ";
    }

    /**
     * @return string
     */
    public function postSave()
    {
        return "
\$this->syncPublishedMessage();
\$this->syncPublishedMessageForMappingResource();   
\$this->syncPublishedMessageForMappings();   
        ";
    }

    /**
     * @return string
     */
    public function postDelete()
    {
        return "
\$this->syncUnpublishedMessage();        
\$this->syncUnpublishedMessageForMappingResource();        
\$this->syncUnpublishedMessageForMappings();        
        ";
    }

    /**
     * Adds a single parameter.
     *
     * Expects an associative array looking like
     * [ 'name' => 'foo', 'value' => bar ]
     *
     * @param array $parameter
     *
     * @return void
     */
    public function addParameter(array $parameter)
    {
        $parameter = array_change_key_case($parameter, CASE_LOWER);

        $this->parameters[$parameter['name']] = [];

        if (isset($parameter['value'])) {
            $this->parameters[$parameter['name']]['value'] = $parameter['value'];
        }

        if (isset($parameter['required'])) {
            $this->parameters[$parameter['name']]['required'] = $parameter['required'];
        }
    }

    /**
     * @return string
     */
    public function objectAttributes()
    {
        $script = '';
        $script .= $this->addBaseAttribute();

        return $script;
    }

    /**
     * @return string
     */
    public function objectMethods()
    {
        $script = '';
        $script .= $this->addToggleEnqueueMethod();
        $script .= $this->addGetStorageKeyBuilderMethod();
        $script .= $this->addGenerateKeyMethod();
        $script .= $this->addGenerateMappingResourceKeyMethod();
        $script .= $this->addGenerateMappingsKeyMethod();
        $script .= $this->addGenerateAliasKeysMethod();
        $script .= $this->addSendToQueueMethod();
        $script .= $this->addSyncPublishedMessageMethod();
        $script .= $this->addSyncUnpublishedMessageMethod();
        $script .= $this->addSyncPublishedMessageForMappingResourceMethod();
        $script .= $this->addSyncUnpublishedMessageForMappingResourceMethod();
        $script .= $this->addSyncPublishedMessageForMappingsMethod();
        $script .= $this->addSyncUnpublishedMessageForMappingsMethod();

        return $script;
    }

    /**
     * @return void
     */
    public function modifyTable()
    {
        $table = $this->getTable();
        $parameters = $this->getParameters();

        if (!$table->hasColumn('data')) {
            $table->addColumn([
                'name' => 'data',
                'type' => 'LONGVARCHAR',
            ]);
        }

        if (isset($parameters['store'])) {
            $required = false;
            if (isset($parameters['store']['required'])) {
                $required = $parameters['store']['required'];
            }

            if (!$table->hasColumn('store')) {
                $table->addColumn([
                    'name' => 'store',
                    'type' => 'VARCHAR',
                    'size' => '128',
                    'required' => $required,
                ]);
            }
        }

        if (isset($parameters['locale'])) {
            $required = false;
            if (isset($parameters['locale']['required'])) {
                $required = $parameters['locale']['required'];
            }

            if (!$table->hasColumn('locale')) {
                $table->addColumn([
                    'name' => 'locale',
                    'type' => 'VARCHAR',
                    'size' => '16',
                    'required' => $required,
                ]);
            }
        }

        if (isset($parameters['mapping_resource'])) {
            if (!$table->hasColumn('mapping_resource_key')) {
                $table->addColumn([
                    'name' => 'mapping_resource_key',
                    'type' => 'VARCHAR',
                ]);
                $uniqueIndex = new Unique();
                $uniqueIndex->setName($table->getName() . '-unique-mapping-resource-key');
                $uniqueIndex->addColumn($table->getColumn('mapping_resource_key'));
                $table->addUnique($uniqueIndex);
            }
        }

        if (!$table->hasColumn('alias_keys')) {
            $table->addColumn([
                'name' => 'alias_keys',
                'type' => 'VARCHAR',
            ]);
            $uniqueIndex = new Unique();
            $uniqueIndex->setName($table->getName() . '-unique-alias-keys');
            $uniqueIndex->addColumn($table->getColumn('alias_keys'));
            $table->addUnique($uniqueIndex);
        }

        if (!$table->hasColumn('key')) {
            $table->addColumn([
                'name' => 'key',
                'type' => 'VARCHAR',
            ]);

            $uniqueIndex = new Unique();
            $uniqueIndex->setName($table->getName() . '-unique-key');
            $uniqueIndex->addColumn($table->getColumn('key'));
            $table->addUnique($uniqueIndex);
        }
    }

    /**
     * @return string
     */
    public function addBaseAttribute()
    {
        return "
/**
 * @var array
 */
private \$_dataTemp;

/**
 * @var bool
 */
private \$_isSendingToQueue = true;

/**
 * @var \\Spryker\\Zed\\Kernel\\Locator
 */
private \$_locator;
        ";
    }

    /**
     * @return string
     */
    protected function addToggleEnqueueMethod()
    {
        return "
/**
 * @return bool
 */
public function isSendingToQueue()
{    
    return \$this->_isSendingToQueue;
}

/**
 * @param bool \$_isSendingToQueue
 *
 * @return \$this
 */
public function setIsSendingToQueue(\$_isSendingToQueue)
{
    \$this->_isSendingToQueue = \$_isSendingToQueue;
    
    return \$this;
}        
        ";
    }

    /**
     * @return string
     */
    protected function addGetStorageKeyBuilderMethod()
    {
        return "
/**
 * @param string \$resource
 *
 * @return \\Spryker\\Service\\Synchronization\\Dependency\\Plugin\\SynchronizationKeyGeneratorPluginInterface
 */
protected function getStorageKeyBuilder(\$resource)
{
    if (\$this->_locator === null) {
        \$this->_locator = \\Spryker\\Zed\\Kernel\\Locator::getInstance();
    }
    
    /** @var \\Spryker\\Service\\Synchronization\\SynchronizationServiceInterface \$synchronizationService */
    \$synchronizationService = \$this->_locator->synchronization()->service();

    return \$synchronizationService->getStorageKeyBuilder(\$resource);
}        
        ";
    }

    /**
     * @throws \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\MissingAttributeException
     *
     * @return string
     */
    protected function addGenerateKeyMethod()
    {
        $parameters = $this->getParameters();
        $keySuffix = null;
        $storeSetStatement = $this->getStoreStatement($parameters);
        $localeSetStatement = $this->getLocaleStatement($parameters);
        $referenceSetStatement = '';

        if (!isset($parameters['resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        $resource = $parameters['resource']['value'];

        if (isset($parameters['key_suffix_column'])) {
            $filter = new UnderscoreToCamelCase();
            $keySuffix = sprintf('get%s()', $filter->filter($parameters['key_suffix_column']['value']));
        }

        if ($keySuffix !== null) {
            $referenceSetStatement = "\$syncTransferData->setReference(\$this->$keySuffix);";
        }

        return "
/**
 * @return void
 */
protected function setGeneratedKey()
{
    \$syncTransferData = new \\Generated\\Shared\\Transfer\\SynchronizationDataTransfer();
    $referenceSetStatement
    $storeSetStatement
    $localeSetStatement    
    \$keyBuilder = \$this->getStorageKeyBuilder('$resource');

    \$key = \$keyBuilder->generateKey(\$syncTransferData);
    \$this->setKey(\$key);
}        
        ";
    }

    /**
     * @throws \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\MissingAttributeException
     *
     * @return string
     */
    protected function addGenerateMappingResourceKeyMethod()
    {
        $parameters = $this->getParameters();
        if (!isset($parameters['mapping_resource'])) {
            return '/**
 * @return void
 */
protected function setGeneratedKeyForMappingResource()
{
}';
        }
        $keySuffix = null;
        $storeSetStatement = $this->getStoreStatement($parameters);
        $localeSetStatement = $this->getLocaleStatement($parameters);
        $checkSuffixStatement = '';

        if (!isset($parameters['resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        if (!isset($parameters['mapping_resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_MAPPING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        $mappingResourceSuffix = "'{$parameters['mapping_resource']['value']}'";
        $referenceSetStatement = "\$syncTransferData->setReference($mappingResourceSuffix);";

        $resource = $parameters['resource']['value'];

        if (isset($parameters['mapping_resource_key_suffix_column'])) {
            $filter = new UnderscoreToCamelCase();
            $keySuffix = sprintf('get%s()', $filter->filter($parameters['mapping_resource_key_suffix_column']['value']));
            $checkSuffixStatement = "if (empty(\$this->$keySuffix)) {
         return;       
    }
            ";
        }

        if ($keySuffix !== null) {
            $referenceSetStatement = "\$syncTransferData->setReference($mappingResourceSuffix . ':' .\$this->$keySuffix);";
        }

        return "
/**
 * @return void
 */
protected function setGeneratedKeyForMappingResource()
{
    $checkSuffixStatement
    \$syncTransferData = new \\Generated\\Shared\\Transfer\\SynchronizationDataTransfer();
    $referenceSetStatement
    $storeSetStatement
    $localeSetStatement    
    \$keyBuilder = \$this->getStorageKeyBuilder('$resource');

    \$key = \$keyBuilder->generateKey(\$syncTransferData);
    \$this->setMappingResourceKey(\$key);
}        
        ";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    public function objectFilter(&$script)
    {
        $parser = new PhpParser($script, true);
        $parser->replaceMethod('getData', $this->getNewGetDataMethod());
        $parser->replaceMethod('setData', $this->getNewSetDataMethod());
        $script = $parser->getCode();
    }

    /**
     * @return string
     */
    protected function getNewSetDataMethod()
    {
        $tableName = $this->getTable()->getPhpName();

        $newCode = "
    /**
     * Set the value of [data] column.
     *
     * @param array \$v new value
     * @return \$this The current object (for fluent API support)
     */
    public function setData(\$v)
    {
        if (is_array(\$v)) {
            \$this->_dataTemp = \$v;
            \$v = json_encode(\$v);        
        }
        
        if (\$v !== null) {
            \$v = (string) \$v;
        }
    
        if (\$this->data !== \$v) {
            \$this->data = \$v;
            \$this->modifiedColumns[%sTableMap::COL_DATA] = true;
        }
    
        return \$this;
    }        
        ";

        return sprintf($newCode, $tableName);
    }

    /**
     * @return string
     */
    protected function getNewGetDataMethod()
    {
        return "
    /**
     * Get the [data] column value.
     *
     * @return array
     */
    public function getData()
    {
        return json_decode(\$this->data, true);
    }";
    }

    /**
     * @throws \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\InvalidConfigurationException
     *
     * @return string
     */
    protected function addSendToQueueMethod()
    {
        $queueName = $this->getParameter('queue_group')['value'];
        $queuePoolName = $this->getQueuePoolName();
        $hasStore = $this->hasStore();
        $hasLocale = $this->hasLocale();

        if ($hasStore && $queuePoolName) {
            throw new InvalidConfigurationException(
                sprintf(static::ERROR_MUTUALLY_EXCLUSIVE_PARAMETERS, $this->getTable()->getPhpName())
            );
        }

        $setLocale = $hasLocale ? '$queueSendTransfer->setLocale($this->locale);' : '';

        $setMessageQueueRouting = '';
        if ($hasStore) {
            $setMessageQueueRouting = "\$queueSendTransfer->setStoreName(\$this->store);";
        }

        if ($queuePoolName) {
            $setMessageQueueRouting = "\$queueSendTransfer->setQueuePoolName('$queuePoolName');";
        }

        if ($queueName === null) {
            $queueName = $this->getParameter('resource')['value'];
        }

        return "
/**
 * @param array \$message
 *
 * @return void
 */
protected function sendToQueue(array \$message)
{
    if (\$this->_locator === null) {
        \$this->_locator = \\Spryker\\Zed\\Kernel\\Locator::getInstance();
    }
    
    \$queueSendTransfer = new \\Generated\\Shared\\Transfer\\QueueSendMessageTransfer();
    \$queueSendTransfer->setBody(json_encode(\$message));
    $setLocale
    $setMessageQueueRouting
    
    \$queueClient = \$this->_locator->queue()->client();
    \$queueClient->sendMessage('$queueName', \$queueSendTransfer);
}        
        ";
    }

    /**
     * @return string
     */
    protected function addSyncPublishedMessageMethod()
    {
        $params = $this->getParams();
        $resource = $this->getParameter('resource')['value'];

        return "
/**
 * @throws PropelException
 * 
 * @return void
 */
public function syncPublishedMessage()
{
    if (!\$this->_isSendingToQueue) {
        return;
    }

    if (empty(\$this->getKey())) {
        throw new PropelException(\"Synchronization failed, the column 'key' is null or empty\");
    }

    if (\$this->_dataTemp !== null) {
        \$data = \$this->_dataTemp;
    } else {
        \$data = \$this->getData();
    }
    
    /* The value for `\$params` has been loaded from schema file */
    \$params = '$params';
    \$decodedParams = [];
    if (!empty(\$params)) {
        \$decodedParams = json_decode(\$params, true);
    }
    
    \$data['_timestamp'] = microtime(true);
    \$message = [
        'write' => [
            'key' => \$this->getKey(),
            'value' => \$data,
            'resource' => '$resource',
            'params' => \$decodedParams,
        ]
    ];
    \$this->sendToQueue(\$message);
}        
        ";
    }

    /**
     * @return string
     */
    protected function addSyncUnpublishedMessageMethod()
    {
        $params = $this->getParams();
        $resource = $this->getParameter('resource')['value'];

        return "
/**
 * @return void
 */
public function syncUnpublishedMessage()
{
    if (!\$this->_isSendingToQueue) {
        return;
    }
    
    /* The value for `\$params` has been loaded from schema file */
    \$params = '$params';
    \$decodedParams = [];
    if (!empty(\$params)) {
        \$decodedParams = json_decode(\$params, true);
    }
    
    \$data['_timestamp'] = microtime(true);
    \$message = [
        'delete' => [
            'key' => \$this->getKey(),
            'value' => \$data,
            'resource' => '$resource',
            'params' => \$decodedParams,
        ]
    ];

    \$this->sendToQueue(\$message); 
}        
        ";
    }

    /**
     * @return string
     */
    protected function addSyncPublishedMessageForMappingResourceMethod()
    {
        $params = $this->getParams();
        $resource = $this->getParameter('resource')['value'];
        $behaviorParameters = $this->getParameters();

        $sendMappingStatement = '';
        if (isset($behaviorParameters['mapping_resource'])) {
            $sendMappingStatement = "
    if (!empty(\$this->getMappingResourceKey())) {
        /* The value for `\$params` has been loaded from schema file */
        \$params = '$params';
        \$decodedParams = [];
        if (!empty(\$params)) {
            \$decodedParams = json_decode(\$params, true);
        }
    
        \$message = [
            'write' => [
                'key' => \$this->getMappingResourceKey(),
                'value' => [
                    'key' => \$this->getKey(),
                    '_timestamp' => microtime(true),
                ],
                'resource' => '$resource',
                'params' => \$decodedParams,
            ]
        ];
        \$this->sendToQueue(\$message);
    }
            ";
        }

        return "
/**
 * @return void
 */
public function syncPublishedMessageForMappingResource()
{
    $sendMappingStatement;
}        
        ";
    }

    /**
     * @return string
     */
    protected function addSyncUnpublishedMessageForMappingResourceMethod()
    {
        $params = $this->getParams();
        $behaviorParameters = $this->getParameters();
        $resource = $this->getParameter('resource')['value'];

        $sendMappingStatement = '';
        if (isset($behaviorParameters['mapping_resource'])) {
            $sendMappingStatement = "
    if (!empty(\$this->getMappingResourceKey())) {
        /* The value for `\$params` has been loaded from schema file */
        \$params = '$params';
        \$decodedParams = [];
        if (!empty(\$params)) {
            \$decodedParams = json_decode(\$params, true);
        }
    
        \$message = [
            'delete' => [
                'key' => \$this->getMappingResourceKey(),
                'value' => [
                    'key' => \$this->getKey(),
                    '_timestamp' => microtime(true),
                ],
                'resource' => '$resource',
                'params' => \$decodedParams,
            ]
        ];
    
        \$this->sendToQueue(\$message);
    }
            ";
        }

        return "
/**
 * @return void
 */
public function syncUnpublishedMessageForMappingResource()
{
    $sendMappingStatement;
}        
        ";
    }

    /**
     * @return string
     */
    protected function addSyncPublishedMessageForMappingsMethod()
    {
        $parameters = $this->getParameters();
        $resource = $this->getParameter('resource')['value'];
        $sendMappingsStatement = '';
        if (isset($parameters['mappings'])) {
            $mappings = $this->getMappings();
            $sendMappingsStatement = "\$mappings = $mappings;
    foreach (\$mappings as \$mapping) {
        \$data = \$this->getData(); 
        \$source = \$mapping['source'];
        \$destination = \$mapping['destination'];
        if (isset(\$data[\$source]) && isset(\$data[\$destination])) {
            \$message = [
                'write' => [
                    'key' => \$this->generateMappingKey(\$source, \$data[\$source]),
                    'value' => [
                        'id' => \$data[\$destination],
                        '_timestamp' => microtime(true),
                    ],
                    'resource' => '$resource',
                ]
            ];
            \$this->sendToQueue(\$message);
        }
    }
            ";
        }

        return "
/**
 * @return void
 */
public function syncPublishedMessageForMappings()
{
    $sendMappingsStatement
}        
        ";
    }

    /**
     * @return string
     */
    protected function addSyncUnpublishedMessageForMappingsMethod()
    {
        $parameters = $this->getParameters();
        $resource = $this->getParameter('resource')['value'];
        $sendMappingsStatement = '';
        if (isset($parameters['mappings'])) {
            $mappings = $this->getMappings();
            $sendMappingsStatement = "\$mappings = $mappings;
    foreach (\$mappings as \$mapping) {
        \$data = \$this->getData(); 
        \$source = \$mapping['source'];
        \$destination = \$mapping['destination'];
        if (isset(\$data[\$source]) && isset(\$data[\$destination])) {
            \$message = [
                'delete' => [
                    'key' => \$this->generateMappingKey(\$source, \$data[\$source]),
                    'value' => [
                        'id' => \$data[\$destination],
                        '_timestamp' => microtime(true),
                    ],
                    'resource' => '$resource',
                ]
            ];
            \$this->sendToQueue(\$message);
        }
    }
            ";
        }

        return "
/**
 * @return void
 */
public function syncUnpublishedMessageForMappings()
{
    $sendMappingsStatement
}        
        ";
    }

    /**
     * @throws \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\MissingAttributeException
     *
     * @return string
     */
    protected function addGenerateMappingsKeyMethod()
    {
        $parameters = $this->getParameters();
        $storeSetStatement = $this->getStoreStatement($parameters);
        $localeSetStatement = $this->getLocaleStatement($parameters);

        if (!isset($parameters['resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        $resource = $parameters['resource']['value'];

        return "
/**
 * @param string \$source
 * @param string \$sourceIdentifier
 
 * @return string
 */
protected function generateMappingKey(\$source, \$sourceIdentifier)
{
    \$syncTransferData = new \\Generated\\Shared\\Transfer\\SynchronizationDataTransfer();
    \$syncTransferData->setReference(\$source . ':' . \$sourceIdentifier);
    $storeSetStatement
    $localeSetStatement    
    \$keyBuilder = \$this->getStorageKeyBuilder('$resource');

    return \$keyBuilder->generateKey(\$syncTransferData);
}        
        ";
    }

    /**
     * @return bool
     */
    protected function hasStore()
    {
        return isset($this->getParameters()['store']);
    }

    /**
     * @return bool
     */
    protected function hasLocale(): bool
    {
        return isset($this->getParameters()['locale']);
    }

    /**
     * @return bool
     */
    protected function hasMappings(): bool
    {
        return isset($this->getParameters()['mappings']);
    }

    /**
     * @return string|null
     */
    protected function getQueuePoolName()
    {
        $parameters = $this->getParameters();
        if (!isset($parameters['queue_pool'])) {
            return null;
        }

        if (!isset($parameters['queue_pool']['value'])) {
            return null;
        }

        return $parameters['queue_pool']['value'];
    }

    /**
     * @return string
     */
    protected function getParams()
    {
        $params = '';
        if (isset($this->getParameters()['params'])) {
            $params = $this->getParameters()['params']['value'];
        }

        return $params;
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    protected function getStoreStatement(array $parameters): string
    {
        if (isset($parameters['store'])) {
            return "\$syncTransferData->setStore(\$this->store);";
        }

        return '';
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    protected function getLocaleStatement(array $parameters): string
    {
        if (isset($parameters['locale'])) {
            return "\$syncTransferData->setLocale(\$this->locale);";
        }

        return '';
    }

    /**
     * @throws \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\MissingAttributeException
     * @throws \Spryker\Zed\SynchronizationBehavior\Persistence\Propel\Behavior\Exception\InvalidConfigurationException
     *
     * @return string
     */
    protected function getMappings(): string
    {
        $parameters = $this->getParameters();
        $mappings = [];
        if (isset($parameters['mappings'])) {
            if (!isset($parameters['mappings']['value'])) {
                throw new MissingAttributeException(sprintf(static::ERROR_MISSING_MAPPINGS_PARAMETER, $this->getTable()->getPhpName()));
            }
            $mappingsParts = explode(':', $parameters['mappings']['value']);
            if (count($mappingsParts) !== 2) {
                throw new InvalidConfigurationException(
                    sprintf(static::ERROR_INVALID_MAPPINGS_PARAMETER, $this->getTable()->getPhpName())
                );
            }

            $mappings = "[
        [
            'source' => '{$mappingsParts[0]}',
            'destination' => '{$mappingsParts[1]}',
        ],
    ]";
        }

        return $mappings;
    }

    /**
     * @return string
     */
    protected function addGenerateAliasKeysMethod(): string
    {
        if (!$this->hasMappings()) {
            return '/**
 * @return void
 */
protected function setGeneratedAliasKeys()
{
}';
        }
        $mappings = $this->getMappings();

        return "
/**
 * @return void
 */
protected function setGeneratedAliasKeys()
{
    \$mappings = $mappings;
    \$data = \$this->getData();
    \$aliasKeys = json_decode(\$this->getAliasKeys()) ?? [];
    foreach (\$mappings as \$mapping) {
        \$source = \$mapping['source'];
        if (isset(\$data[\$source])) {
            \$aliasKeys[] = \$this->generateMappingKey(\$source, \$data[\$source]);
        }
    }
    \$aliasKeys = json_encode(array_unique(\$aliasKeys));
    \$this->setAliasKeys(\$aliasKeys);
}        
        ";
    }
}
