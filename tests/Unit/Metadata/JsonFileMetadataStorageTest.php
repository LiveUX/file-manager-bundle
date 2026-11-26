<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Metadata;

use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use PHPUnit\Framework\TestCase;

final class JsonFileMetadataStorageTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/liveux_fm_meta_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }

    public function testSaveReadRelocateAndDeleteMetadata(): void
    {
        $storage = new JsonFileMetadataStorage($this->tempFile);

        $storage->save('local::products/promo', new FolderMetadata('#2563eb', 'bag', true));
        $storage->save('local::products/promo/pens', new FolderMetadata('#10b981', 'pen', false));

        // Fresh instance reads persisted JSON
        $reloaded = new JsonFileMetadataStorage($this->tempFile);
        self::assertSame('#2563eb', $reloaded->get('local::products/promo')->color);
        self::assertTrue($reloaded->get('local::products/promo')->pinned);

        // Relocate prefix moves both parent and nested child
        $reloaded->relocatePrefix('local::products/promo', 'local::archive/promo');
        self::assertSame('#2563eb', $reloaded->get('local::archive/promo')->color);
        self::assertSame('#10b981', $reloaded->get('local::archive/promo/pens')->color);
        self::assertTrue($reloaded->get('local::products/promo')->isEmpty());

        // Delete removes parent and nested child
        $reloaded->delete('local::archive/promo');
        self::assertSame([], $reloaded->all());
    }
}
