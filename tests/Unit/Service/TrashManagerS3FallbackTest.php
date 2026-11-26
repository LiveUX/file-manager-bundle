<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use DateTimeInterface;
use FilesystemIterator;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Storage\StorageFactoryInterface;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class TrashManagerS3FallbackTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_trash_s3_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/bucket', 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testMimeRestrictedCloudBucketUsesLocalFallbackForTrashMetadataAndRestoresCleanly(): void
    {
        $inner = new Filesystem(new LocalFilesystemAdapter($this->tempDir . '/bucket'));
        $inner->write('reports/invoice.pdf', '%PDF-1.4 sample');

        // Decorator simulating Supabase bucket allowing ONLY PDFs (rejecting application/json .meta.json)
        $restrictedFs = new class($inner) implements FilesystemOperator {
            public function __construct(private readonly FilesystemOperator $inner)
            {
            }

            public function fileExists(string $location): bool
            {
                return $this->inner->fileExists($location);
            }

            public function directoryExists(string $location): bool
            {
                return $this->inner->directoryExists($location);
            }

            public function has(string $location): bool
            {
                return $this->inner->has($location);
            }

            public function read(string $location): string
            {
                return $this->inner->read($location);
            }

            public function readStream(string $location)
            {
                return $this->inner->readStream($location);
            }

            public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
            {
                return $this->inner->listContents($location, $deep);
            }

            public function lastModified(string $path): int
            {
                return $this->inner->lastModified($path);
            }

            public function fileSize(string $path): int
            {
                return $this->inner->fileSize($path);
            }

            public function mimeType(string $path): string
            {
                return $this->inner->mimeType($path);
            }

            public function visibility(string $path): string
            {
                return $this->inner->visibility($path);
            }

            public function writeStream(string $location, $contents, array $config = []): void
            {
                $this->inner->writeStream($location, $contents, $config);
            }

            public function setVisibility(string $path, string $visibility): void
            {
                $this->inner->setVisibility($path, $visibility);
            }

            public function delete(string $location): void
            {
                $this->inner->delete($location);
            }

            public function deleteDirectory(string $location): void
            {
                $this->inner->deleteDirectory($location);
            }

            public function createDirectory(string $location, array $config = []): void
            {
                $this->inner->createDirectory($location, $config);
            }

            public function move(string $source, string $destination, array $config = []): void
            {
                $this->inner->move($source, $destination, $config);
            }

            public function copy(string $source, string $destination, array $config = []): void
            {
                $this->inner->copy($source, $destination, $config);
            }

            public function publicUrl(string $path, array $config = []): string
            {
                return $this->inner->publicUrl($path, $config);
            }

            public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
            {
                return $this->inner->temporaryUrl($path, $expiresAt, $config);
            }

            public function checksum(string $path, array $config = []): string
            {
                return $this->inner->checksum($path, $config);
            }

            public function write(string $location, string $contents, array $config = []): void
            {
                if (str_ends_with($location, '.json')) {
                    throw UnableToWriteFile::atLocation($location, 'InvalidMimeType: mime type application/json is not supported');
                }
                $this->inner->write($location, $contents, $config);
            }
        };

        $provider = new YamlConfigProvider(
            [],
            [
                'supabase_s3' => [
                    'label' => 'Supabase',
                    'group' => 'network',
                    'driver' => 'AwsS3',
                    'driver_options' => ['bucket' => 'baski'],
                ],
            ],
            [
                'default' => [
                    'volumes' => ['supabase_s3'],
                    'trash' => ['enabled' => true, 'directory' => '.trash', 'retention_days' => 30],
                ],
            ],
        );

        $factory = new class($restrictedFs) implements StorageFactoryInterface {
            public function __construct(private readonly FilesystemOperator $fs)
            {
            }

            public function create(VolumeConfig $volume): FilesystemOperator
            {
                return $this->fs;
            }
        };

        $fallbackFile = $this->tempDir . '/var/trash-meta.json';
        $trashManager = new TrashManager(
            new VolumeRegistry($provider, $factory),
            new PathNormalizer(),
            new EventDispatcher(),
            localMetaFallbackFile: $fallbackFile,
        );
        $profile = $provider->getProfile('default');

        // 1. Moving PDF to trash succeeds even though Supabase rejects .meta.json
        $entry = $trashManager->moveToTrash($profile, 'reports/invoice.pdf', 'supabase_s3', 'admin@example.com');
        self::assertSame('invoice.pdf', $entry->name);
        self::assertSame('reports/invoice.pdf', $entry->originalPath);
        self::assertFalse($inner->fileExists('reports/invoice.pdf'));
        self::assertTrue($inner->fileExists('.trash/' . $entry->id . '/invoice.pdf'));
        self::assertFalse($inner->fileExists('.trash/' . $entry->id . '/.meta.json'));
        self::assertFileExists($fallbackFile);

        // 2. Listing trash finds it from local fallback metadata
        $listed = $trashManager->listTrash($profile, 'supabase_s3');
        self::assertCount(1, $listed);
        self::assertSame('reports/invoice.pdf', $listed[0]->originalPath);
        self::assertSame('admin@example.com', $listed[0]->deletedBy);

        // 3. Restoring moves it back to original path and removes local fallback metadata
        $trashManager->restore($profile, $entry->id, 'supabase_s3');
        self::assertTrue($inner->fileExists('reports/invoice.pdf'));
        self::assertSame([], $trashManager->listTrash($profile, 'supabase_s3'));
        $remaining = json_decode((string) file_get_contents($fallbackFile), true);
        self::assertSame([], $remaining);

        // 4. Orphan recovery: a previously stranded .trash/<id>/orphan.pdf without .meta.json is still listed & restorable!
        $inner->write('.trash/20261123_151807_a0493ca0/orphan.pdf', '%PDF-1.4 orphan');
        $recovered = $trashManager->listTrash($profile, 'supabase_s3');
        self::assertCount(1, $recovered);
        self::assertSame('20261123_151807_a0493ca0', $recovered[0]->id);
        self::assertSame('orphan.pdf', $recovered[0]->name);

        $trashManager->restore($profile, '20261123_151807_a0493ca0', 'supabase_s3');
        self::assertTrue($inner->fileExists('orphan.pdf'));
    }
}
