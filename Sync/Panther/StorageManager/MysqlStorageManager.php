<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ConnectionsBundle\Sync\Panther\StorageManager;

use DateTime;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Table;
use InvalidArgumentException;
use ONGR\ConnectionsBundle\Sync\DiffProvider\SyncJobs\TableManager;

/**
 * The service to create/update database table and manipulate its data for Panther.
 */
class MysqlStorageManager extends TableManager implements StorageManagerInterface
{
    /**
     * {@inheritdoc}
     */
    public function createStorage($shopId = null, $connection = null)
    {
        $connection = $connection ? : $this->getConnection();
        $schemaManager = $connection->getSchemaManager();

        if ($schemaManager->tablesExist([$this->getTableName($shopId)])) {
            return true;
        }

        $table = new Table($this->getTableName($shopId));
        $this->buildTable($table);
        $schemaManager->createTable($table);

        return true;
    }

    /**
     * Builds table structure.
     *
     * @param Table $table
     */
    protected function buildTable(Table $table)
    {
        $table->addColumn('id', 'bigint')
            ->setUnsigned(true)
            ->setAutoincrement(true);

        $table->addColumn('type', 'string')
            ->setLength(1)
            ->setComment('C-CREATE(INSERT),U-UPDATE,D-DELETE');

        $table->addColumn('document_type', 'string')
            ->setLength(32);

        $table->addColumn('document_id', 'string')
            ->setLength(32);

        $table->addColumn('timestamp', 'datetime');

        $table->addColumn('status', 'boolean', ['default' => self::STATUS_NEW])
            ->setComment('0-new,1-inProgress,2-error');

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['type', 'document_type', 'document_id', 'status']);
    }

    /**
     * Returns table name for specified shop.
     *
     * @param int $shopId
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    public function getTableName($shopId = null)
    {
        $tableName = parent::getTableName();
        if (!preg_match('|^[a-zA-Z_0-9]+$|i', $tableName)) {
            throw new InvalidArgumentException("Invalid table name specified: \"$tableName\"");
        }

        $suffix = null;
        if ($shopId !== null) {
            $suffix = '_' . $shopId;
        }

        return $tableName . $suffix;
    }

    /**
     * {@inheritdoc}
     */
    public function addRecord($operationType, $documentType, $documentId, DateTime $dateTime, array $shopIds = null)
    {
        if (empty($shopIds)) {
            $shopIds = [null];
        }
        $connection = $this->getConnection();

        foreach ($shopIds as $shopId) {
            $tableName = $connection->quoteIdentifier($this->getTableName($shopId));

            try {
                $connection->executeUpdate(
                    'INSERT INTO ' . $tableName . '
                        (`type`, `document_type`, `document_id`, `timestamp`, `status`)
                    VALUES
                        (:operationType, :documentType, :documentId, :timestamp, :status)',
                    [
                        'operationType' => $operationType,
                        'documentType' => $documentType,
                        'documentId' => $documentId,
                        'timestamp' => $dateTime->format('Y-m-d H:i:s'),
                        'status' => self::STATUS_NEW,
                    ]
                );
            } catch (DBALException $e) {
                // Record exists, check if update is needed.
                $statement = $connection->prepare(
                    'SELECT COUNT(*) AS count FROM ' . $tableName . '
                    WHERE
                        `type` = :operationType
                        AND `document_type` = :documentType
                        AND `document_id` = :documentId
                        AND `status` = :status
                        AND `timestamp` >= :dateTime'
                );
                $statement->execute(
                    [
                        'operationType' => $operationType,
                        'documentType' => $documentType,
                        'documentId' => $documentId,
                        'status' => self::STATUS_NEW,
                        'dateTime' => $dateTime->format('Y-m-d H:i:s'),
                    ]
                );
                $newerRecordExists = $statement->fetchColumn(0) > 0;
                if ($newerRecordExists) {
                    continue;
                }

                // More recent record info, attempt to update existing record.
                $connection->executeUpdate(
                    'UPDATE ' . $tableName . '
                    SET `timestamp` = :dateTime
                    WHERE
                        `type` = :operationType
                        AND `document_type` = :documentType
                        AND `document_id` = :documentId
                        AND `status` = :status',
                    [
                        'dateTime' => $dateTime->format('Y-m-d H:i:s'),
                        'operationType' => $operationType,
                        'documentType' => $documentType,
                        'documentId' => $documentId,
                        'status' => self::STATUS_NEW,
                    ]
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeRecord($pantherStorageRecordId, array $shopIds = null)
    {
        if (empty($shopIds)) {
            $shopIds = [null];
        }

        $connection = $this->getConnection();

        foreach ($shopIds as $shopId) {
            try {
                $connection->delete($this->getTableName($shopId), ['id' => $pantherStorageRecordId]);
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    /**
     * Returns next $recordCount (or less) available for processing records from storage.
     *
     * @param int    $count
     * @param string $documentType
     * @param int    $shopId
     *
     * @return array
     */
    public function getNextRecords($count, $documentType = null, $shopId = null)
    {
        $count = (int)$count;
        if ($count === 0) {
            return [];
        }

        $connection = $this->getConnection();
        $connection->beginTransaction();

        $tableName = $connection->quoteIdentifier($this->getTableName($shopId));

        if (!empty($documentType) && is_string($documentType)) {
            // Return only records of certain type.
            $statement = $connection->prepare(
                'SELECT * FROM ' . $tableName . '
                WHERE
                    `status` = :status
                    AND `document_type` = :documentType
                ORDER BY `timestamp` ASC
                LIMIT :limit
                FOR UPDATE'
            );
            $statement->bindValue('status', self::STATUS_NEW, \PDO::PARAM_INT);
            $statement->bindValue('documentType', $documentType, \PDO::PARAM_STR);
            $statement->bindValue('limit', $count, \PDO::PARAM_INT);
            $statement->execute();
            $nextRecords = $statement->fetchAll();

            $statement = $connection->prepare(
                'UPDATE ' . $tableName . '
                SET `status` = :toStatus
                WHERE
                    `status` = :fromStatus
                    AND `document_type` = :documentType
                ORDER BY `timestamp` ASC
                LIMIT :limit'
            );
            $statement->bindValue('fromStatus', self::STATUS_NEW, \PDO::PARAM_INT);
            $statement->bindValue('toStatus', self::STATUS_IN_PROGRESS, \PDO::PARAM_INT);
            $statement->bindValue('documentType', $documentType, \PDO::PARAM_STR);
            $statement->bindValue('limit', $count, \PDO::PARAM_INT);
            $statement->execute();
        } else {
            // Return all records.
            $statement = $connection->prepare(
                'SELECT * FROM ' . $tableName . '
                WHERE
                    `status` = :status
                ORDER BY `timestamp` ASC
                LIMIT :limit
                FOR UPDATE'
            );
            $statement->bindValue('status', self::STATUS_NEW, \PDO::PARAM_INT);
            $statement->bindValue('limit', $count, \PDO::PARAM_INT);
            $statement->execute();
            $nextRecords = $statement->fetchAll();

            $statement = $connection->prepare(
                'UPDATE ' . $tableName . '
                SET `status` = :toStatus
                WHERE `status` = :fromStatus
                ORDER BY `timestamp` ASC
                LIMIT :limit'
            );
            $statement->bindValue('toStatus', self::STATUS_IN_PROGRESS, \PDO::PARAM_INT);
            $statement->bindValue('fromStatus', self::STATUS_NEW, \PDO::PARAM_INT);
            $statement->bindValue('limit', $count, \PDO::PARAM_INT);
            $statement->execute();
        }

        $connection->commit();

        return $nextRecords;
    }
}
