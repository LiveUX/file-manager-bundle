<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Bridge\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Persistence\ManagerRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Zero-ORM-mapping Doctrine DBAL metadata storage for multi-server / Docker / Kubernetes deployments.
 *
 * Activated automatically when `liveux_file_manager.defaults.metadata.driver: 'doctrine'` is configured.
 * Stores folder appearance (color, icon, pinned) and file editorial metadata (alt, title, caption, tags, focalPoint)
 * in a portable DBAL table (`liveux_fm_metadata` by default) compatible with MySQL, MariaDB, PostgreSQL, and SQLite.
 */
final class DoctrineDbalMetadataStorage implements FolderMetadataStorageInterface, ResetInterface
{
    private const int MAX_ITEM_CACHE_ENTRIES = 16384;
    private const int ITEM_CACHE_TRIM_KEEP = 8192;

    /**
     * @var array<string, FolderMetadata>|null
     */
    private ?array $memoryCache = null;

    /**
     * Bounded per-key lookup cache so single `get()` calls execute indexed `key_hash = :hash` queries
     * without scanning and decoding the entire table into memory.
     *
     * @var array<string, FolderMetadata>
     */
    private array $itemCache = [];

    private bool $tableVerified = false;
    private readonly string $tableName;

    public function __construct(
        private readonly ?object $doctrineOrConnection,
        private readonly string $connectionName = 'default',
        string $tableName = 'liveux_fm_metadata',
        private readonly bool $autoCreateTable = true,
        private readonly ?Connection $fallbackConnection = null,
        private readonly ?Connection $defaultConnection = null,
        private readonly bool $resolveViaEntityManager = false,
    ) {
        $cleanTable = trim($tableName);
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $cleanTable) !== 1) {
            throw new RuntimeException(sprintf('Invalid metadata table name "%s".', $tableName));
        }

        $this->tableName = $cleanTable;
    }

    public function get(string $canonicalKey): FolderMetadata
    {
        $canonicalKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalKey);
        if ($this->memoryCache !== null) {
            return $this->memoryCache[$canonicalKey] ?? new FolderMetadata();
        }

        if (array_key_exists($canonicalKey, $this->itemCache)) {
            return $this->itemCache[$canonicalKey];
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $rawPayload = $conn->fetchOne(
            sprintf('SELECT payload FROM %s WHERE key_hash = :hash', $quotedTable),
            ['hash' => self::hashKey($canonicalKey)],
        );

        $resolved = new FolderMetadata();
        if (is_string($rawPayload) && $rawPayload !== '') {
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                $resolved = FolderMetadata::fromArray($decoded);
            }
        }

        if (count($this->itemCache) >= self::MAX_ITEM_CACHE_ENTRIES) {
            $this->itemCache = array_slice($this->itemCache, -self::ITEM_CACHE_TRIM_KEEP, null, true);
        }
        $this->itemCache[$canonicalKey] = $resolved;

        return $resolved;
    }

    /**
     * Batch-loads multiple canonical keys into the bounded in-memory item cache in a single SQL query
     * so directory listings and tree loads execute 1 SELECT instead of N+1 queries.
     *
     * @param list<string> $canonicalKeys
     */
    public function preload(array $canonicalKeys): void
    {
        if ($canonicalKeys === [] || $this->memoryCache !== null) {
            return;
        }

        $missingByHash = [];
        foreach ($canonicalKeys as $rawKey) {
            $key = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($rawKey);
            if ($key === '' || array_key_exists($key, $this->itemCache)) {
                continue;
            }
            $missingByHash[self::hashKey($key)] = $key;
        }

        if ($missingByHash === []) {
            return;
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);
        $quotedTable = $conn->quoteIdentifier($this->tableName);

        foreach (array_chunk($missingByHash, 400, true) as $chunk) {
            $hashes = array_keys($chunk);
            $placeholders = implode(', ', array_fill(0, count($hashes), '?'));
            $rows = $conn->fetchAllAssociative(
                sprintf('SELECT key_hash, payload FROM %s WHERE key_hash IN (%s)', $quotedTable, $placeholders),
                $hashes,
            );

            $foundHashes = [];
            foreach ($rows as $row) {
                $rowHash = (string) ($row['key_hash'] ?? '');
                $rawPayload = $row['payload'] ?? null;
                if ($rowHash === '' || !isset($chunk[$rowHash])) {
                    continue;
                }
                $canonicalKey = $chunk[$rowHash];
                $resolved = new FolderMetadata();
                if (is_string($rawPayload) && $rawPayload !== '') {
                    $decoded = json_decode($rawPayload, true);
                    if (is_array($decoded)) {
                        $resolved = FolderMetadata::fromArray($decoded);
                    }
                }
                $this->itemCache[$canonicalKey] = $resolved;
                $foundHashes[$rowHash] = true;
            }

            foreach ($chunk as $hash => $canonicalKey) {
                if (!isset($foundHashes[$hash])) {
                    $this->itemCache[$canonicalKey] = new FolderMetadata();
                }
            }

            if (count($this->itemCache) > self::MAX_ITEM_CACHE_ENTRIES) {
                $this->itemCache = array_slice($this->itemCache, -self::ITEM_CACHE_TRIM_KEEP, null, true);
            }
        }
    }

    /**
     * @return array<string, FolderMetadata>
     */
    public function getByPrefix(string $canonicalPrefix): array
    {
        $canonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalPrefix);
        $childPrefix = self::buildChildPrefix($canonicalPrefix);
        if ($this->memoryCache !== null) {
            $filtered = [];
            foreach ($this->memoryCache as $k => $v) {
                if ($k === $canonicalPrefix || str_starts_with($k, $childPrefix)) {
                    $filtered[$k] = $v;
                }
            }

            return $filtered;
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);
        $quotedTable = $conn->quoteIdentifier($this->tableName);
        [$prefixClause, $prefixParams] = $this->buildPrefixWhere($conn, $canonicalPrefix);
        $rows = $conn->fetchAllAssociative(
            sprintf('SELECT canonical_key, payload FROM %s WHERE %s', $quotedTable, $prefixClause),
            $prefixParams,
        );

        $result = [];
        foreach ($rows as $row) {
            $rawKey = $row['canonical_key'] ?? null;
            $rawPayload = $row['payload'] ?? null;
            if (!is_string($rawKey) || $rawKey === '' || !is_string($rawPayload)) {
                continue;
            }
            $key = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($rawKey);
            if ($key !== $canonicalPrefix && !str_starts_with($key, $childPrefix)) {
                continue;
            }
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                $result[$key] = FolderMetadata::fromArray($decoded);
            }
        }

        return $result;
    }

    public function all(): array
    {
        if ($this->memoryCache !== null) {
            return $this->memoryCache;
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $rows = $conn->fetchAllAssociative(sprintf('SELECT canonical_key, payload FROM %s', $quotedTable));

        $result = [];
        foreach ($rows as $row) {
            $key = $row['canonical_key'] ?? null;
            $rawPayload = $row['payload'] ?? null;
            if (!is_string($key) || $key === '' || !is_string($rawPayload)) {
                continue;
            }
            $nfcKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($key);
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                $result[$nfcKey] = FolderMetadata::fromArray($decoded);
            }
        }

        $this->memoryCache = $result;

        return $result;
    }

    public function save(string $canonicalKey, FolderMetadata $metadata): void
    {
        $canonicalKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalKey);
        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $keyHash = self::hashKey($canonicalKey);

        if ($metadata->isEmpty()) {
            $conn->delete($this->tableName, ['key_hash' => $keyHash]);
            if (count($this->itemCache) >= self::MAX_ITEM_CACHE_ENTRIES) {
                $this->itemCache = array_slice($this->itemCache, -self::ITEM_CACHE_TRIM_KEEP, null, true);
            }
            $this->itemCache[$canonicalKey] = new FolderMetadata();
            if ($this->memoryCache !== null) {
                unset($this->memoryCache[$canonicalKey]);
            }

            return;
        }

        $json = json_encode($metadata->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $this->upsertRow($conn, $quotedTable, $keyHash, $canonicalKey, $json, $now);

        if (count($this->itemCache) >= self::MAX_ITEM_CACHE_ENTRIES) {
            $this->itemCache = array_slice($this->itemCache, -self::ITEM_CACHE_TRIM_KEEP, null, true);
        }
        $this->itemCache[$canonicalKey] = $metadata;
        if ($this->memoryCache !== null) {
            $this->memoryCache[$canonicalKey] = $metadata;
        }
    }

    /**
     * Atomically persists a batch of metadata entries inside a single DBAL transaction,
     * eliminating N+1 queries and transaction-aborting duplicate-key races.
     *
     * @param array<string, FolderMetadata> $entries
     */
    public function saveMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $normalized = [];
        foreach ($entries as $rawKey => $meta) {
            $nfcKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($rawKey);
            $normalized[$nfcKey] = $meta;
        }

        $conn->transactional(function (Connection $tx) use ($normalized, $quotedTable, $now): void {
            $hashesToDelete = [];
            foreach ($normalized as $canonicalKey => $meta) {
                $keyHash = self::hashKey($canonicalKey);
                if ($meta->isEmpty()) {
                    $hashesToDelete[] = $keyHash;
                    continue;
                }
                $json = json_encode($meta->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($json)) {
                    continue;
                }
                $this->upsertRow($tx, $quotedTable, $keyHash, $canonicalKey, $json, $now);
            }
            if ($hashesToDelete !== []) {
                $this->deleteKeyHashesBatch($tx, $hashesToDelete);
            }
        });

        foreach ($normalized as $canonicalKey => $meta) {
            if (count($this->itemCache) >= self::MAX_ITEM_CACHE_ENTRIES) {
                $this->itemCache = array_slice($this->itemCache, -self::ITEM_CACHE_TRIM_KEEP, null, true);
            }
            if ($meta->isEmpty()) {
                $this->itemCache[$canonicalKey] = new FolderMetadata();
                if ($this->memoryCache !== null) {
                    unset($this->memoryCache[$canonicalKey]);
                }
            } else {
                $this->itemCache[$canonicalKey] = $meta;
                if ($this->memoryCache !== null) {
                    $this->memoryCache[$canonicalKey] = $meta;
                }
            }
        }
    }

    private function upsertRow(
        Connection $conn,
        string $quotedTable,
        string $keyHash,
        string $canonicalKey,
        string $json,
        string $now,
    ): void {
        $platform = $conn->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            $conn->executeStatement(
                sprintf(
                    'INSERT INTO %s (key_hash, canonical_key, payload, updated_at) VALUES (:hash, :canonical_key, :payload, :updated_at) ON CONFLICT (key_hash) DO UPDATE SET canonical_key = EXCLUDED.canonical_key, payload = EXCLUDED.payload, updated_at = EXCLUDED.updated_at',
                    $quotedTable,
                ),
                [
                    'hash' => $keyHash,
                    'canonical_key' => $canonicalKey,
                    'payload' => $json,
                    'updated_at' => $now,
                ],
            );

            return;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $conn->executeStatement(
                sprintf(
                    'INSERT INTO %s (key_hash, canonical_key, payload, updated_at) VALUES (:hash, :canonical_key, :payload, :updated_at) ON DUPLICATE KEY UPDATE canonical_key = VALUES(canonical_key), payload = VALUES(payload), updated_at = VALUES(updated_at)',
                    $quotedTable,
                ),
                [
                    'hash' => $keyHash,
                    'canonical_key' => $canonicalKey,
                    'payload' => $json,
                    'updated_at' => $now,
                ],
            );

            return;
        }

        $exists = $conn->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE key_hash = :hash', $quotedTable),
            ['hash' => $keyHash],
        );

        if ($exists !== false) {
            $conn->update(
                $this->tableName,
                [
                    'canonical_key' => $canonicalKey,
                    'payload' => $json,
                    'updated_at' => $now,
                ],
                ['key_hash' => $keyHash],
            );

            return;
        }

        try {
            $conn->insert($this->tableName, [
                'key_hash' => $keyHash,
                'canonical_key' => $canonicalKey,
                'payload' => $json,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($conn->isTransactionActive()) {
                throw $e;
            }
            $conn->update(
                $this->tableName,
                [
                    'canonical_key' => $canonicalKey,
                    'payload' => $json,
                    'updated_at' => $now,
                ],
                ['key_hash' => $keyHash],
            );
        }
    }

    public function delete(string $canonicalKey): void
    {
        $canonicalKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($canonicalKey);
        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $prefix = self::buildChildPrefix($canonicalKey);

        $conn->transactional(function (Connection $tx) use ($canonicalKey, $quotedTable, $prefix): void {
            [$prefixClause, $prefixParams] = $this->buildPrefixWhere($tx, $canonicalKey);
            $rows = $tx->fetchAllAssociative(
                sprintf('SELECT key_hash, canonical_key FROM %s WHERE %s', $quotedTable, $prefixClause),
                $prefixParams,
            );

            $hashesToDelete = [];
            foreach ($rows as $row) {
                $rowKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc((string) ($row['canonical_key'] ?? ''));
                $rowHash = (string) ($row['key_hash'] ?? '');
                if ($rowHash !== '' && ($rowKey === $canonicalKey || str_starts_with($rowKey, $prefix))) {
                    $hashesToDelete[] = $rowHash;
                }
            }

            $this->deleteKeyHashesBatch($tx, $hashesToDelete);
        });

        foreach (array_keys($this->itemCache) as $cachedKey) {
            if ($cachedKey === $canonicalKey || str_starts_with($cachedKey, $prefix)) {
                unset($this->itemCache[$cachedKey]);
            }
        }

        if ($this->memoryCache !== null) {
            foreach (array_keys($this->memoryCache) as $key) {
                if ($key === $canonicalKey || str_starts_with($key, $prefix)) {
                    unset($this->memoryCache[$key]);
                }
            }
        }
    }

    public function relocatePrefix(string $oldCanonicalPrefix, string $newCanonicalPrefix): void
    {
        $oldCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($oldCanonicalPrefix);
        $newCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($newCanonicalPrefix);
        if ($oldCanonicalPrefix === $newCanonicalPrefix) {
            return;
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->transactional(function (Connection $tx) use ($oldCanonicalPrefix, $newCanonicalPrefix, $quotedTable, $now): void {
            [$oldClause, $oldParams] = $this->buildPrefixWhere($tx, $oldCanonicalPrefix);
            $rows = $tx->fetchAllAssociative(
                sprintf('SELECT key_hash, canonical_key, payload FROM %s WHERE %s', $quotedTable, $oldClause),
                $oldParams,
            );

            $oldSlash = self::buildChildPrefix($oldCanonicalPrefix);
            $matched = [];
            foreach ($rows as $row) {
                if (!is_string($row['canonical_key'] ?? null)) {
                    continue;
                }
                $rawKey = $row['canonical_key'];
                $nfcRowKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($rawKey);
                if ($nfcRowKey === $oldCanonicalPrefix || str_starts_with($nfcRowKey, $oldSlash)) {
                    $row['canonical_key'] = $nfcRowKey;
                    $row['_isExactNfc'] = $rawKey === $nfcRowKey;
                    $matched[] = $row;
                }
            }

            [$newClause, $newParams] = $this->buildPrefixWhere($tx, $newCanonicalPrefix);
            $staleTargetRows = $tx->fetchAllAssociative(
                sprintf('SELECT key_hash, canonical_key FROM %s WHERE %s', $quotedTable, $newClause),
                $newParams,
            );
            $newSlash = self::buildChildPrefix($newCanonicalPrefix);
            $hashesToDelete = [];
            foreach ($staleTargetRows as $sr) {
                $sk = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc((string) ($sr['canonical_key'] ?? ''));
                $sh = (string) ($sr['key_hash'] ?? '');
                if ($sh !== '' && ($sk === $newCanonicalPrefix || str_starts_with($sk, $newSlash))) {
                    $hashesToDelete[] = $sh;
                }
            }

            if ($matched === [] && $hashesToDelete === []) {
                return;
            }

            $inserts = [];
            $exactNfcSelected = [];
            foreach ($matched as $row) {
                $oldHash = (string) ($row['key_hash'] ?? '');
                if ($oldHash !== '') {
                    $hashesToDelete[] = $oldHash;
                }
                $oldKey = (string) $row['canonical_key'];
                $payload = (string) ($row['payload'] ?? '{}');
                $newKey = $oldKey === $oldCanonicalPrefix
                    ? $newCanonicalPrefix
                    : $newSlash . substr($oldKey, strlen($oldSlash));
                $newHash = self::hashKey($newKey);
                $hashesToDelete[] = $newHash;
                $isExactNfc = ($row['_isExactNfc'] ?? false) === true;
                if (!isset($inserts[$newHash]) || $isExactNfc || !($exactNfcSelected[$newHash] ?? false)) {
                    $inserts[$newHash] = [
                        'key_hash' => $newHash,
                        'canonical_key' => $newKey,
                        'payload' => $payload,
                        'updated_at' => $now,
                    ];
                    if ($isExactNfc) {
                        $exactNfcSelected[$newHash] = true;
                    }
                }
            }

            $this->deleteKeyHashesBatch($tx, array_values(array_unique($hashesToDelete)));
            $this->insertRowsBatch($tx, array_values($inserts));
        });

        $this->memoryCache = null;
        $this->itemCache = [];
    }

    public function copyPrefix(string $sourceCanonicalPrefix, string $targetCanonicalPrefix): void
    {
        $sourceCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($sourceCanonicalPrefix);
        $targetCanonicalPrefix = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($targetCanonicalPrefix);
        if ($sourceCanonicalPrefix === $targetCanonicalPrefix) {
            return;
        }

        $conn = $this->getConnection();
        $this->ensureTable($conn);

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->transactional(function (Connection $tx) use ($sourceCanonicalPrefix, $targetCanonicalPrefix, $quotedTable, $now): void {
            [$srcClause, $srcParams] = $this->buildPrefixWhere($tx, $sourceCanonicalPrefix);
            $rows = $tx->fetchAllAssociative(
                sprintf('SELECT canonical_key, payload FROM %s WHERE %s', $quotedTable, $srcClause),
                $srcParams,
            );

            $sourceSlash = self::buildChildPrefix($sourceCanonicalPrefix);
            $matched = [];
            foreach ($rows as $row) {
                if (!is_string($row['canonical_key'] ?? null)) {
                    continue;
                }
                $rawKey = $row['canonical_key'];
                $nfcRowKey = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc($rawKey);
                if ($nfcRowKey === $sourceCanonicalPrefix || str_starts_with($nfcRowKey, $sourceSlash)) {
                    $row['canonical_key'] = $nfcRowKey;
                    $row['_isExactNfc'] = $rawKey === $nfcRowKey;
                    $matched[] = $row;
                }
            }

            [$tgtClause, $tgtParams] = $this->buildPrefixWhere($tx, $targetCanonicalPrefix);
            $staleTargetRows = $tx->fetchAllAssociative(
                sprintf('SELECT key_hash, canonical_key FROM %s WHERE %s', $quotedTable, $tgtClause),
                $tgtParams,
            );
            $targetSlash = self::buildChildPrefix($targetCanonicalPrefix);
            $hashesToDelete = [];
            foreach ($staleTargetRows as $sr) {
                $sk = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc((string) ($sr['canonical_key'] ?? ''));
                $sh = (string) ($sr['key_hash'] ?? '');
                if ($sh !== '' && ($sk === $targetCanonicalPrefix || str_starts_with($sk, $targetSlash))) {
                    $hashesToDelete[] = $sh;
                }
            }

            if ($matched === [] && $hashesToDelete === []) {
                return;
            }

            $inserts = [];
            $exactNfcSelected = [];
            foreach ($matched as $row) {
                $srcKey = (string) $row['canonical_key'];
                $payload = (string) ($row['payload'] ?? '{}');
                $dstKey = $srcKey === $sourceCanonicalPrefix
                    ? $targetCanonicalPrefix
                    : $targetSlash . substr($srcKey, strlen($sourceSlash));
                $dstHash = self::hashKey($dstKey);
                $hashesToDelete[] = $dstHash;
                $isExactNfc = ($row['_isExactNfc'] ?? false) === true;
                if (!isset($inserts[$dstHash]) || $isExactNfc || !($exactNfcSelected[$dstHash] ?? false)) {
                    $inserts[$dstHash] = [
                        'key_hash' => $dstHash,
                        'canonical_key' => $dstKey,
                        'payload' => $payload,
                        'updated_at' => $now,
                    ];
                    if ($isExactNfc) {
                        $exactNfcSelected[$dstHash] = true;
                    }
                }
            }

            $this->deleteKeyHashesBatch($tx, $hashesToDelete);
            $this->insertRowsBatch($tx, array_values($inserts));
        });

        $this->memoryCache = null;
        $this->itemCache = [];
    }

    public function reset(): void
    {
        $this->memoryCache = null;
        $this->itemCache = [];
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    private function getConnection(): Connection
    {
        if ($this->doctrineOrConnection instanceof Connection) {
            return $this->doctrineOrConnection;
        }

        if ($this->doctrineOrConnection instanceof ManagerRegistry) {
            if ($this->resolveViaEntityManager) {
                try {
                    $manager = $this->doctrineOrConnection->getManager($this->connectionName);
                    if (method_exists($manager, 'getConnection')) {
                        $emConn = $manager->getConnection();
                        if ($emConn instanceof Connection) {
                            return $emConn;
                        }
                    }
                } catch (\Throwable) {
                    // Fall back to DBAL connection name lookup below.
                }
            }

            $conn = $this->doctrineOrConnection->getConnection($this->connectionName);
            if ($conn instanceof Connection) {
                return $conn;
            }
        }

        if ($this->fallbackConnection instanceof Connection) {
            return $this->fallbackConnection;
        }

        if ($this->defaultConnection instanceof Connection) {
            return $this->defaultConnection;
        }

        throw new RuntimeException('Doctrine DBAL connection is unavailable. Install doctrine/doctrine-bundle or doctrine/dbal to use metadata.driver="doctrine".');
    }

    private function ensureTable(Connection $conn): void
    {
        if ($this->tableVerified || !$this->autoCreateTable) {
            $this->tableVerified = true;

            return;
        }

        $schemaManager = $conn->createSchemaManager();
        if (!$schemaManager->tablesExist([$this->tableName])) {
            $table = new Table($this->tableName);
            $table->addColumn('key_hash', 'string', ['length' => 64, 'fixed' => true]);
            $table->addColumn('canonical_key', 'string', ['length' => 4096]);
            $table->addColumn('payload', 'text');
            $table->addColumn('updated_at', 'datetime');
            $table->setPrimaryKey(['key_hash']);
            $indexName = 'idx_' . substr($this->tableName, 0, 40) . '_ckey';
            $isPostgres = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform;
            if (!$isPostgres) {
                $table->addIndex(['canonical_key'], $indexName, [], ['lengths' => [191]]);
            }
            try {
                $schemaManager->createTable($table);
                if ($isPostgres) {
                    try {
                        $conn->executeStatement(sprintf(
                            'CREATE INDEX IF NOT EXISTS %s ON %s (LEFT(canonical_key, 191) text_pattern_ops)',
                            $conn->quoteIdentifier($indexName),
                            $conn->quoteIdentifier($this->tableName),
                        ));
                    } catch (\Throwable) {
                    }
                }
            } catch (TableExistsException) {
                // Created concurrently by another worker/container.
            }
        }

        $this->tableVerified = true;
    }

    /**
     * @param list<string> $hashes
     */
    private function deleteKeyHashesBatch(Connection $conn, array $hashes): void
    {
        $unique = array_values(array_unique(array_filter($hashes, static fn (string $h): bool => $h !== '')));
        if ($unique === []) {
            return;
        }

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        foreach (array_chunk($unique, 400) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $conn->executeStatement(
                sprintf('DELETE FROM %s WHERE key_hash IN (%s)', $quotedTable, $placeholders),
                $chunk,
            );
        }
    }

    /**
     * @param list<array{key_hash: string, canonical_key: string, payload: string, updated_at: string}> $rows
     */
    private function insertRowsBatch(Connection $conn, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $quotedTable = $conn->quoteIdentifier($this->tableName);
        foreach (array_chunk($rows, 100) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $row) {
                $tuples[] = '(?, ?, ?, ?)';
                $params[] = $row['key_hash'];
                $params[] = $row['canonical_key'];
                $params[] = $row['payload'];
                $params[] = $row['updated_at'];
            }
            $conn->executeStatement(
                sprintf(
                    'INSERT INTO %s (key_hash, canonical_key, payload, updated_at) VALUES %s',
                    $quotedTable,
                    implode(', ', $tuples),
                ),
                $params,
            );
        }
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildPrefixWhere(Connection $conn, string $canonicalPrefix): array
    {
        $hash = self::hashKey($canonicalPrefix);
        $childPrefix = self::buildChildPrefix($canonicalPrefix);
        $likePrefix = self::escapeLike($childPrefix) . '%';

        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            if (mb_strlen($childPrefix, 'UTF-8') >= 191) {
                return [
                    "(key_hash = :hash OR (LEFT(canonical_key, 191) = :prefix191 AND canonical_key LIKE :prefix ESCAPE '!'))",
                    [
                        'hash' => $hash,
                        'prefix191' => mb_substr($childPrefix, 0, 191, 'UTF-8'),
                        'prefix' => $likePrefix,
                    ],
                ];
            }

            // Use PostgreSQL text_pattern_ops operators (~>=~ / ~<=~) so codepoint ordering is
            // immune to glibc UTF-8 LC_COLLATE (where U+10FFFF has weight IGNORE) AND uses the
            // (LEFT(canonical_key, 191) text_pattern_ops) B-Tree index with prepared statements.
            return [
                "(key_hash = :hash OR (LEFT(canonical_key, 191) ~>=~ :rangeStart AND LEFT(canonical_key, 191) ~<=~ :rangeEnd AND canonical_key LIKE :prefix ESCAPE '!'))",
                [
                    'hash' => $hash,
                    'rangeStart' => $childPrefix,
                    'rangeEnd' => $childPrefix . "\u{10FFFF}",
                    'prefix' => $likePrefix,
                ],
            ];
        }

        return [
            "key_hash = :hash OR canonical_key LIKE :prefix ESCAPE '!'",
            [
                'hash' => $hash,
                'prefix' => $likePrefix,
            ],
        ];
    }

    private static function buildChildPrefix(string $canonicalPrefix): string
    {
        return str_ends_with($canonicalPrefix, '::') ? $canonicalPrefix : $canonicalPrefix . '/';
    }

    private static function hashKey(string $canonicalKey): string
    {
        return hash('sha256', $canonicalKey);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
