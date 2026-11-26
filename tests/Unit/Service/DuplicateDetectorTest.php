<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Command\DuplicatesCommand;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DuplicateDetectorTest extends TestCase
{
    private string $tempDir;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_dup_' . bin2hex(random_bytes(4));
        $this->storageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'uploads';
        mkdir($this->storageDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testUploadDuplicatePoliciesRejectReuseAndWarn(): void
    {
        $payload = str_repeat('UNIQUE-BINARY-CONTENT-2026', 50);
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'existing.txt', $payload);

        $incoming = $this->tempDir . DIRECTORY_SEPARATOR . 'upload_copy.txt';
        file_put_contents($incoming, $payload);

        // 1. Policy: reject -> throws UploadValidationException
        [$uploadReject, $profileReject] = $this->createUploadStack(['enabled' => true, 'policy' => 'reject']);
        try {
            $uploadReject->handleImportedTempFile($profileReject, $incoming, 'new-name.txt', '', 'local');
            self::fail('Expected UploadValidationException for duplicate reject policy.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.duplicate_file_detected', $e->getTranslationKey());
            self::assertSame(['%existing%' => '/existing.txt'], $e->getTranslationParams());
        }

        // 2. Policy: reuse -> returns existing path without writing duplicate
        [$uploadReuse, $profileReuse] = $this->createUploadStack(['enabled' => true, 'policy' => 'reuse']);
        $reuseRes = $uploadReuse->handleImportedTempFile($profileReuse, $incoming, 'reused-name.txt', '', 'local');
        self::assertTrue($reuseRes['completed']);
        self::assertTrue($reuseRes['reused']);
        self::assertSame('existing.txt', $reuseRes['path']);
        self::assertFileDoesNotExist($this->storageDir . DIRECTORY_SEPARATOR . 'reused-name.txt');

        // 3. Policy: warn -> saves new file and returns duplicateOf
        [$uploadWarn, $profileWarn] = $this->createUploadStack(['enabled' => true, 'policy' => 'warn']);
        $warnRes = $uploadWarn->handleImportedTempFile($profileWarn, $incoming, 'warn-copy.txt', '', 'local');
        self::assertTrue($warnRes['completed']);
        self::assertSame('warn-copy.txt', $warnRes['path']);
        self::assertSame('existing.txt', $warnRes['duplicateOf']);
        self::assertFileExists($this->storageDir . DIRECTORY_SEPARATOR . 'warn-copy.txt');
    }

    public function testDuplicatesCliCommandReportsGroupsAndReclaimableBytes(): void
    {
        $payload = str_repeat('ABCD', 256); // 1024 bytes
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'a.txt', $payload);
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'b.txt', $payload);
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'unique.txt', 'different');

        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->storageDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->storageDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local']]],
        );
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $detector = new DuplicateDetector($registry, $normalizer, new DirectoryCacheManager());
        $scanner = new ProfileFileScanner($provider, $registry, $normalizer);

        $tester = new CommandTester(new DuplicatesCommand($scanner, $detector));
        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'default', '--json' => true]));

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['groups']);
        self::assertSame(1024, $data['reclaimableBytes']);
        self::assertCount(2, $data['duplicates'][0]['files']);
    }

    /**
     * @param array<string, mixed> $dupCfg
     *
     * @return array{0: UploadManager, 1: ProfileConfig}
     */
    private function createUploadStack(array $dupCfg): array
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->storageDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->storageDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'duplicate_detection' => $dupCfg]],
        );
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $detector = new DuplicateDetector($registry, $normalizer, new DirectoryCacheManager());
        $upload = new UploadManager(
            volumeRegistry: $registry,
            pathNormalizer: $normalizer,
            filenameNormalizer: new FilenameNormalizer(),
            eventDispatcher: new EventDispatcher(),
            duplicateDetector: $detector,
        );

        return [$upload, $provider->getProfile('default')];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
