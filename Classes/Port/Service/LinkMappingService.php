<?php
declare(strict_types=1);
namespace In2code\Migration\Port\Service;

use Doctrine\DBAL\DBALException;
use In2code\Migration\Utility\DatabaseUtility;
use In2code\Migration\Utility\StringUtility;
use RuntimeException;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class LinkMappingService
 * will change links (in fields or in FlexForm) after an import with new identifiers
 */
class LinkMappingService
{
    /**
     * Hold the complete configuration like
     *
     *  'excludedTables' => [
     *      'be_users'
     *  ],
     *  'linkMapping' => [
     *      'propertiesWithLinks' => [
     *          'tt_content' => [
     *              'bodytext'
     *          ]
     *      ],
     *      'propertiesWithRelations' => [
     *          [
     *              'field' => 'header_link',
     *              'table' => 'pages'
     *          ]
     *      ],
     *      'propertiesWithRelationsInFlexForms' => [
     *          'tt_content' => [
     *              'pi_flexform' => [
     *                  [
     *                      // tt_news update flexform
     *                      'condition' => [
     *                          'Ctype' => 'list',
     *                          'list_type' => 9
     *                      ],
     *                      'selection' => '//T3FlexForms/data/sheet[@index="s_misc"]/language/field[@index="PIDitemDisplay"]/value',
     *                      'table' => 'pages'
     *                  ]
     *              ]
     *          ]
     *      ]
     *  ]
     *
     * @var array
     */
    protected $configuration = [];

    /**
     * @var null
     */
    protected $mappingService = null;

    /**
     * LinkMappingService constructor.
     * @param MappingService $mappingService
     * @param array $configuration
     */
    public function __construct(MappingService $mappingService, array $configuration)
    {
        $this->mappingService = $mappingService;
        $this->configuration = $configuration;
    }

    /**
     * @return void
     * @throws DBALException
     */
    public function updateLinksAndRecordsInNewRecords(): void
    {
        foreach ($this->mappingService->getMapping() as $tableName => $identifiers) {
            if ($this->isTableInAnyLinkConfiguration($tableName)) {
                foreach ($identifiers as $identifier) {
                    $properties = $this->getPropertiesFromIdentifierAndTable($identifier, $tableName);
                    $newProperties = $this->updatePropertiesWithNewLinkMapping($properties, $tableName);
                    $newProperties = $this->updatePropertiesWithNewRelationMapping($newProperties, $tableName);
                    if ($newProperties !== $properties) {
                        $this->updateRecord($newProperties, $tableName);
                    }
                    $this->updatePropertiesWithNewRelationsInFlexForms($newProperties, $tableName);
                }
            }
        }
    }

    /**
     * Update links in RTE
     *
     * @param array $properties
     * @param string $tableName
     * @return array
     * @throws DBALException
     */
    protected function updatePropertiesWithNewLinkMapping(array $properties, string $tableName): array
    {
        foreach ($properties as $fieldName => $value) {
            if (isset($this->getPropertiesWithLinks()[$tableName])
                && in_array($fieldName, $this->getPropertiesWithLinks()[$tableName])) {
                if (!empty($value)) {
                    $properties[$fieldName] = $this->updateValueWithNewLinkMapping($value);
                }
            }
        }
        return $properties;
    }

    /**
     * @param array $properties
     * @param string $tableName
     * @return array
     * @throws DBALException
     */
    protected function updatePropertiesWithNewRelationMapping(array $properties, string $tableName): array
    {
        if (isset($this->getPropertiesWithRelations()[$tableName])) {
            foreach ($this->getPropertiesWithRelations()[$tableName] as $configuration) {
                $field = $configuration['field'];
                $table = $this->getRelationTable($configuration, $properties, $tableName);
                if (array_key_exists($field, $properties)) {
                    $properties[$field] = $this->updateValueWithSimpleLinks((string)$properties[$field], $table);
                }
            }
        }
        return $properties;
    }

    protected function getRelationTable(array $configuration, array $properties, string $parentTable): string
    {
        $table = $configuration['table'] ?? '';
        if ($table) {
            return $table;
        }

        $tableNameField = $configuration['tableNameField'] ?? '';
        if (!$tableNameField) {
            throw new RuntimeException(
                sprintf(
                    'Neither table nor tableNameField is configured for %s:%s',
                    $parentTable,
                    $configuration['field']
                )
            );
        }
        $table = $properties[$tableNameField] ?? '';
        if (!$table) {
            throw new RuntimeException(
                sprintf('table field %s is empty in record %s:%d', $tableNameField, $parentTable, $properties['uid'])
            );
        }
        return $table;
    }

    /**
     * @param array $properties
     * @param string $tableName
     * @return void
     * @throws DBALException
     */
    protected function updatePropertiesWithNewRelationsInFlexForms(array $properties, string $tableName): void
    {
        if (isset($this->getPropertiesWithRelationsInFlexForms()[$tableName])) {
            foreach ($this->getPropertiesWithRelationsInFlexForms()[$tableName] as $field => $configurations) {
                if (array_key_exists($field, $properties) && !empty($properties[$field])) {
                    foreach ($configurations as $configuration) {
                        if ($this->isConditionFittingForFlexFormRelations($properties, $configuration)) {
                            $this->updateFlexFormValue($properties['uid'], $tableName, $field, $configuration);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array $properties
     * @param array $configuration
     * @return bool
     */
    protected function isConditionFittingForFlexFormRelations(array $properties, array $configuration): bool
    {
        foreach ($configuration['condition'] as $field => $value) {
            if (array_key_exists($field, $properties) && $properties[$field] !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param int $identifier
     * @param string $tableName
     * @param string $fieldName
     * @param array $configuration
     * @return void
     * @throws DBALException
     */
    protected function updateFlexFormValue(
        int $identifier,
        string $tableName,
        string $fieldName,
        array $configuration
    ): void {
        $connection = DatabaseUtility::getConnectionForTable($tableName);
        $sql = 'select ExtractValue(' . $fieldName . ', \'' . $configuration['selection'] . '\') value from '
            . $tableName . ' where uid=' . (int)$identifier;
        $value = (string)$connection->executeQuery($sql)->fetchColumn(0);
        $newValue = $this->updateValueWithSimpleLinks((string)$value, $configuration['table']);
        if (!empty($newValue)) {
            $sql = 'update ' . $tableName . ' set '
                . $fieldName . ' = UpdateXML(' . $fieldName . ', \''
                . $configuration['selection'] . '\', concat(\'<value index="vDEF">\', \''
                . $newValue . '\', \'</value>\' )) WHERE uid=' . (int)$identifier;
        }
        $connection->executeQuery($sql);
    }

    /**
     * Update links in RTE
     *
     * @param string $value
     * @return string
     * @throws DBALException
     */
    protected function updateValueWithNewLinkMapping(string $value): string
    {
        if (!empty($value)) {
            $value = $this->updatePageLinks($value);
            $value = $this->updateFileLinks($value);
            $value = $this->updateRteImages($value);
            $value = $this->updateSpecifiedIdentifiers($value);
        }
        return $value;
    }

    /**
     * Update fields that keeps relations (like tt_content.header_link)
     *
     * @param string $value
     * @param string $table
     * @return string
     * @throws DBALException
     */
    protected function updateValueWithSimpleLinks(string $value, string $table): string
    {
        $newValue = $value;
        if (!empty($value)) {
            if (StringUtility::isIntegerListOrInteger($value)) {
                $newValue = $this->updateRecordLinksSimple($value, $table);
            } else {
                $newValue = $this->updateValueWithNewLinkMapping($value);
            }
        }
        return $newValue;
    }

    /**
     * Search for t3://page?uid=123
     *
     * @param string $value
     * @return string
     */
    protected function updatePageLinks(string $value): string
    {
        $value = preg_replace_callback(
            '~(t3://page\?uid=)(\d+)~',
            [$this, 'updatePageLinksCallback'],
            $value
        );
        return $value;
    }

    /**
     * Search for "123" or "123,124"
     *
     * @param string $value
     * @param string $table Link to this kind of records
     * @return string
     */
    protected function updateRecordLinksSimple(string $value, string $table): string
    {
        $identifiers = GeneralUtility::intExplode(',', $value);
        $newIdentifiers = [];
        foreach ($identifiers as $identifier) {
            $newIdentifiers[] = $this->mappingService->getNewFromOld($identifier, $table);
        }
        return (string)implode(',', $newIdentifiers);
    }

    /**
     * Search for t3://file?uid=123
     *
     * @param string $value
     * @return string
     */
    protected function updateFileLinks(string $value): string
    {
        $value = preg_replace_callback(
            '~(t3://file\?uid=)(\d+)~',
            [$this, 'updateFileLinksCallback'],
            $value
        );
        return $value;
    }

    /**
     * Search for data-htmlarea-file-uid="123"
     *
     * @param string $value
     * @return string
     */
    protected function updateRteImages(string $value): string
    {
        $value = preg_replace_callback(
            '~(data-htmlarea-file-uid=")(\d+)~',
            [$this, 'updateFileLinksCallback'],
            $value
        );
        return $value;
    }

    /**
     * Search for "pages_123,tt_content_123,tx_news_domain_model_news_123"
     *
     * @param string $value
     * @return string
     * @throws DBALException
     */
    protected function updateSpecifiedIdentifiers(string $value): string
    {
        $identifiers = GeneralUtility::trimExplode(',', $value, true);
        $newValue = '';
        foreach ($identifiers as $identifier) {
            if (DatabaseUtility::isSpecifiedIdentifier($identifier)) {
                $table = DatabaseUtility::getTableFromSpecifiedIdentifier($identifier);
                $newIdentifier = $this->mappingService->getNewFromOld(
                    DatabaseUtility::getUidFromSpecifiedIdentifier($identifier),
                    $table
                );
                if ($newValue !== '') {
                    $newValue .= ',';
                }
                $newValue .= $table . '_' . $newIdentifier;
            }
        }
        if ($newValue !== '') {
            return $newValue;
        }
        return $value;
    }

    /**
     * Replace t3://page?uid=123 => t3://page?uid=234
     *
     * @param array $match
     * @return string
     */
    protected function updatePageLinksCallback(array $match): string
    {
        $oldPageIdentifier = (int)$match[2];
        $newPageIdentifier = $this->mappingService->getNewPidFromOldPid($oldPageIdentifier);
        return $match[1] . $newPageIdentifier;
    }

    /**
     * Replace
     * t3://file?uid=123 => t3://file?uid=234
     * and
     * data-htmlarea-file-uid="123" => data-htmlarea-file-uid="234"
     *
     * @param array $match
     * @return string
     */
    protected function updateFileLinksCallback(array $match): string
    {
        $oldIdentifier = (int)$match[2];
        $newIdentifier = $this->mappingService->getNewFromOld($oldIdentifier, 'sys_file');
        return $match[1] . $newIdentifier;
    }

    /**
     * @param int $identifier
     * @param string $tableName
     * @return array
     */
    protected function getPropertiesFromIdentifierAndTable(int $identifier, string $tableName): array
    {
        $queryBuilder = DatabaseUtility::getQueryBuilderForTable($tableName, true);
        $rows = (array)$queryBuilder
            ->select('*')
            ->from($tableName)
            ->where('uid=' . $identifier)
            ->execute()
            ->fetchAll();
        if (!empty($rows[0]['uid'])) {
            return $rows[0];
        }
        return [];
    }

    /**
     * @param array $properties
     * @param string $tableName
     * @return void
     */
    protected function updateRecord(array $properties, string $tableName): void
    {
        if (!empty($properties['uid'])) {
            $connection = DatabaseUtility::getConnectionForTable($tableName);
            $connection->update(
                $tableName,
                $properties,
                ['uid' => (int)$properties['uid']]
            );
        }
    }

    /**
     * @param $tableName
     * @return bool
     */
    protected function isTableInAnyLinkConfiguration($tableName): bool
    {
        return array_key_exists($tableName, $this->getPropertiesWithLinks())
            || array_key_exists($tableName, $this->getPropertiesWithRelations())
            || array_key_exists($tableName, $this->getPropertiesWithRelationsInFlexForms());
    }

    /**
     * @return array
     */
    protected function getPropertiesWithLinks(): array
    {
        return (array)$this->configuration['linkMapping']['propertiesWithLinks'];
    }

    /**
     * @return array
     */
    protected function getPropertiesWithRelations(): array
    {
        $properties = [
            'sys_file_metadata' => [
                [
                    'field' => 'file',
                    'table' => 'sys_file',
                ],
            ]
        ];
        ArrayUtility::mergeRecursiveWithOverrule(
            $properties,
            (array)$this->configuration['linkMapping']['propertiesWithRelations']
        );

        return $properties;
    }

    /**
     * @return array
     */
    protected function getPropertiesWithRelationsInFlexForms(): array
    {
        return (array)$this->configuration['linkMapping']['propertiesWithRelationsInFlexForms'];
    }
}
