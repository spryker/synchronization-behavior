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
    const ERROR_MISSING_RESOURCE_PARAMETER = '%s misses "resource" synchronization parameter.';
    const ERROR_MISSING_MAPPING_RESOURCE_PARAMETER = '%s misses "mapping_resource" synchronization parameter.';
    const ERROR_MUTUALLY_EXCLUSIVE_PARAMETERS = '%s uses mutually exclusive "store" and "queue_pool" synchronization attributes.';

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
\$this->setMappingResourceGeneratedKey();        
        ";
    }

    /**
     * @return string
     */
    public function postSave()
    {
        return "
\$this->syncPublishedMessage();        
        ";
    }

    /**
     * @return string
     */
    public function postDelete()
    {
        return "
\$this->syncUnpublishedMessage();        
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
        $script .= $this->addSendToQueueMethod();
        $script .= $this->addSyncPublishedMessageMethod();
        $script .= $this->addSyncUnpublishedMessageMethod();

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
        $storeSetStatement = '';
        $localeSetStatement = '';
        $referenceSetStatement = '';

        if (!isset($parameters['resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        $resource = $parameters['resource']['value'];

        if (isset($parameters['key_suffix_column'])) {
            $filter = new UnderscoreToCamelCase();
            $keySuffix = sprintf('get%s()', $filter->filter($parameters['key_suffix_column']['value']));
        }

        if (isset($parameters['store'])) {
            $storeSetStatement = "\$syncTransferData->setStore(\$this->store);";
        }

        if (isset($parameters['locale'])) {
            $localeSetStatement = "\$syncTransferData->setLocale(\$this->locale);";
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
protected function setMappingResourceGeneratedKey()
{
}';
        }
        $keySuffix = null;
        $storeSetStatement = '';
        $localeSetStatement = '';
        $referenceSetStatement = '';
        $checkSuffixStatement = '';

        if (!isset($parameters['resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        if (!isset($parameters['mapping_resource']['value'])) {
            throw new MissingAttributeException(sprintf(static::ERROR_MISSING_MAPPING_RESOURCE_PARAMETER, $this->getTable()->getPhpName()));
        }

        $mappingResourceSuffix = "'{$parameters['mapping_resource']['value']}'";

        $resource = $parameters['resource']['value'];

        if (isset($parameters['mapping_resource_key_suffix_column'])) {
            $filter = new UnderscoreToCamelCase();
            $keySuffix = sprintf('get%s()', $filter->filter($parameters['mapping_resource_key_suffix_column']['value']));
            $checkSuffixStatement = "if (empty(\$this->$keySuffix)) {
         return;       
    }
            ";
        }

        if (isset($parameters['store'])) {
            $storeSetStatement = "\$syncTransferData->setStore(\$this->store);";
        }

        if (isset($parameters['locale'])) {
            $localeSetStatement = "\$syncTransferData->setLocale(\$this->locale);";
        }

        if ($keySuffix !== null) {
            $referenceSetStatement = "\$syncTransferData->setReference($mappingResourceSuffix);";
        }

        if ($keySuffix !== null) {
            $referenceSetStatement = "\$syncTransferData->setReference($mappingResourceSuffix . ':' .\$this->$keySuffix);";
        }

        return "
/**
 * @return void
 */
protected function setMappingResourceGeneratedKey()
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

        if ($hasStore && $queuePoolName) {
            throw new InvalidConfigurationException(
                sprintf(static::ERROR_MUTUALLY_EXCLUSIVE_PARAMETERS, $this->getTable()->getPhpName())
            );
        }

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
        $parameters = $this->getParameters();

        $sendMappingStatement = '';
        if (isset($parameters['mapping_resource'])) {
            $sendMappingStatement = "
    if (!empty(\$this->getMappingResourceKey())) {
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
    $sendMappingStatement
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

        $parameters = $this->getParameters();

        $sendMappingStatement = '';
        if (isset($parameters['mapping_resource'])) {
            $sendMappingStatement = "
    if (!empty(\$this->getMappingResourceKey())) {
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

    $sendMappingStatement 
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
}
