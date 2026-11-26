<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Metadata;

use Doctrine\DBAL\DriverManager;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use PHPUnit\Framework\TestCase;

final class DoctrineDbalMetadataStorageTest extends TestCase
{
    public function testCrudPrefixDeleteAndTransactionalRelocateOnSqlite(): void
    {
        if (!class_exists(DriverManager::class) || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('doctrine/dbal + pdo_sqlite are required to test DoctrineDbalMetadataStorage.');
        }

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_metadata');

        // 1. Save folder + nested file metadata (including UTF-8 + focal point)
        $folderMeta = new FolderMetadata(color: '#10b981', icon: 'bag', pinned: true);
        $fileMeta = new FolderMetadata(
            alt: 'Mat siyah termos',
            title: 'Termos Pro',
            caption: '500 ml vakumlu',
            tags: ['termos', 'promosyon'],
            focalPoint: ['x' => 0.4, 'y' => 0.6],
        );

        $storage->save('local:urunler', $folderMeta);
        $storage->save('local:urunler/termos.webp', $fileMeta);

        // Fresh storage instance reads back cleanly from DBAL table
        $reader = new DoctrineDbalMetadataStorage($conn, 'default', 'liveux_fm_metadata');
        $loadedFolder = $reader->get('local:urunler');
        self::assertSame('#10b981', $loadedFolder->color);
        self::assertSame('bag', $loadedFolder->icon);
        self::assertTrue($loadedFolder->pinned);

        $loadedFile = $reader->get('local:urunler/termos.webp');
        self::assertSame('Mat siyah termos', $loadedFile->alt);
        self::assertSame(['termos', 'promosyon'], $loadedFile->tags);
        self::assertSame(['x' => 0.4, 'y' => 0.6], $loadedFile->focalPoint);

        // 2. Update existing record (upsert)
        $storage->save('local:urunler', new FolderMetadata(color: '#ef4444', icon: 'star', pinned: false));
        self::assertSame('#ef4444', (new DoctrineDbalMetadataStorage($conn))->get('local:urunler')->color);

        // 3. Relocate folder prefix moves both folder and nested file metadata
        $storage->relocatePrefix('local:urunler', 'local:katalog');
        $afterMove = new DoctrineDbalMetadataStorage($conn);
        self::assertTrue($afterMove->get('local:urunler')->isEmpty());
        self::assertTrue($afterMove->get('local:urunler/termos.webp')->isEmpty());
        self::assertSame('#ef4444', $afterMove->get('local:katalog')->color);
        self::assertSame('Mat siyah termos', $afterMove->get('local:katalog/termos.webp')->alt);

        // 4. Deleting folder removes both folder and nested descendants
        $storage->delete('local:katalog');
        $afterDelete = new DoctrineDbalMetadataStorage($conn);
        self::assertSame([], $afterDelete->all());

        // 5. Saving empty metadata deletes row
        $storage->save('local:temp.jpg', new FolderMetadata(alt: 'temp'));
        self::assertCount(1, $storage->all());
        $storage->save('local:temp.jpg', new FolderMetadata());
        self::assertCount(0, (new DoctrineDbalMetadataStorage($conn))->all());
    }

    public function testInvalidTableNameThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        new DoctrineDbalMetadataStorage(null, 'default', 'bad; DROP TABLE users;--');
    }
}
