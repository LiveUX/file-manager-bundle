<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Command;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Command\DoctorCommand;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\StorageFactoryInterface;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctorCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_doc_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempDir);
    }

    public function testDefaultRunSkipsRemoteProbingAndPasses(): void
    {
        $factory = $this->createMock(StorageFactoryInterface::class);
        $factory->expects(self::never())->method('create');

        $tester = $this->createDoctorTester($factory);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('cURL CA Bundle', $tester->getDisplay());
        self::assertStringContainsString('pass --check-remote to probe live', $tester->getDisplay());
    }

    public function testCheckRemoteSucceedsWhenCloudVolumeResponds(): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('listContents')->willReturn(new DirectoryListing([]));

        $factory = $this->createStub(StorageFactoryInterface::class);
        $factory->method('create')->willReturn($fs);

        $tester = $this->createDoctorTester($factory);
        $exit = $tester->execute(['--check-remote' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Connected (', $tester->getDisplay());
        self::assertStringContainsString('live cloud connectivity checks passed', $tester->getDisplay());
    }

    #[DataProvider('provideRemoteErrors')]
    public function testCheckRemoteDiagnosesKnownCloudErrors(string $rawExceptionMessage, string $expectedRemediation): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('listContents')->willThrowException(new \RuntimeException($rawExceptionMessage));

        $factory = $this->createStub(StorageFactoryInterface::class);
        $factory->method('create')->willReturn($fs);

        $tester = $this->createDoctorTester($factory);
        $exit = $tester->execute(['--check-remote' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString($expectedRemediation, $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideRemoteErrors(): iterable
    {
        yield 'curl ssl 60' => [
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            'SSL CA missing (cURL 60)',
        ];
        yield 'invalid key' => [
            'Error executing ListObjectsV2: InvalidAccessKeyId',
            'Invalid S3 credentials',
        ];
        yield 'missing bucket' => [
            'Error executing ListObjectsV2: NoSuchBucket',
            'Bucket not found',
        ];
        yield 'checksum mismatch' => [
            'Error executing PutObject: XAmzContentSHA256Mismatch',
            'when_required',
        ];
        yield 'access denied' => [
            'HTTP 403 Forbidden: AccessDenied',
            'Access denied (HTTP 403)',
        ];
    }

    private function createDoctorTester(StorageFactoryInterface $factory): CommandTester
    {
        $localVol = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->tempDir);
        $s3Vol = new VolumeConfig(
            name: 'supabase_s3',
            label: 'Supabase',
            group: 'network',
            driver: 'AwsS3',
            driverOptions: ['bucket' => 'baski', 'endpoint' => 'https://ref.storage.supabase.co/storage/v1/s3'],
        );

        $profile = new ProfileConfig(
            name: 'default',
            basePath: $this->tempDir,
            theme: 'shadcn',
            locale: 'en',
            volumes: ['local', 'supabase_s3'],
            driver: 'LocalFileSystem',
            path: $this->tempDir,
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: [],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: [],
            naming: [],
            imageOptimization: [],
            thumbnail: ['enabled' => false],
            trash: ['enabled' => false],
            zip: ['enabled' => false],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false],
            cache: ['enabled' => false],
            metadata: ['enabled' => false],
        );

        $volumes = ['local' => $localVol, 'supabase_s3' => $s3Vol];
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfileNames')->willReturn(['default']);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturnCallback(static fn (string $n): VolumeConfig => $volumes[$n]);

        $registry = new VolumeRegistry($configProvider, $factory);
        $thumbs = new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver());
        $zip = new ZipArchiveService($registry, new PathNormalizer());

        return new CommandTester(new DoctorCommand($configProvider, $registry, $thumbs, $zip, new ImageOptimizer()));
    }
}
