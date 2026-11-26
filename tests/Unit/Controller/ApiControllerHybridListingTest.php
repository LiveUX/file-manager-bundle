<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Controller;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ApiControllerHybridListingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_hybrid_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testCompactJsonModeShrinksPayloadAndEmitsSharedUrlBases(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'photo.webp', 'webpdata');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'manual.pdf', '%PDF-1.4');

        $controller = $this->createController();

        $fullResponse = $controller->list(new Request(['profile' => 'default', 'all' => '1']));
        $compactResponse = $controller->list(new Request(['profile' => 'default', 'all' => '1', 'compact' => '1']));

        $fullRaw = (string) $fullResponse->getContent();
        $compactRaw = (string) $compactResponse->getContent();
        $compactData = json_decode($compactRaw, true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($compactData['ok']);
        self::assertTrue($compactData['compact']);
        self::assertFalse($compactData['serverPaginated']);
        self::assertSame('/_liveux-file-manager/download', $compactData['urlBases']['download']);
        self::assertSame('/_liveux-file-manager/thumb', $compactData['urlBases']['thumb']);
        self::assertLessThan(strlen($fullRaw), strlen($compactRaw));

        $firstItem = $compactData['items'][0];
        self::assertArrayNotHasKey('downloadUrl', $firstItem);
        self::assertArrayNotHasKey('sizeFormatted', $firstItem);
    }

    public function testHybridLimitSwitchesLargeDirectoriesToServerPagination(): void
    {
        for ($i = 1; $i <= 55; ++$i) {
            file_put_contents(
                $this->tempDir . DIRECTORY_SEPARATOR . sprintf('file-%03d.txt', $i),
                'content-' . $i,
            );
        }

        $controller = $this->createController();

        $response = $controller->list(new Request([
            'profile' => 'default',
            'all' => '1',
            'compact' => '1',
            'hybridLimit' => '50',
            'perPage' => '12',
        ]));

        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($data['ok']);
        self::assertTrue($data['serverPaginated']);
        self::assertSame(55, $data['total']);
        self::assertSame(1, $data['page']);
        self::assertSame(12, $data['perPage']);
        self::assertSame(5, $data['totalPages']);
        self::assertCount(12, $data['items']);
    }

    private function createController(): ApiController
    {
        $configProvider = new YamlConfigProvider(
            defaults: [
                'theme' => 'bootstrap5',
                'locale' => 'en',
            ],
            volumes: [
                'media' => [
                    'label' => 'Media',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->tempDir,
                    'url_prefix' => '/uploads/media',
                    'private' => false,
                    'cache_ttl' => 0,
                ],
            ],
            profiles: [
                'default' => [
                    'volumes' => ['media'],
                    'security' => [
                        'voter_enabled' => false,
                        'csrf_protection' => false,
                    ],
                ],
            ],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $dispatcher = new EventDispatcher();
        $uploadManager = new UploadManager($registry, $normalizer, new FilenameNormalizer(), $dispatcher);
        $trashManager = new TrashManager($registry, $normalizer, $dispatcher);
        $cacheManager = new DirectoryCacheManager();

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static function (string $name, array $params = []): string {
                $base = match ($name) {
                    'liveux_file_manager_download' => '/_liveux-file-manager/download',
                    'liveux_file_manager_thumb' => '/_liveux-file-manager/thumb',
                    default => '/_liveux-file-manager/' . $name,
                };

                return $params === [] ? $base : $base . '?' . http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
            },
        );

        return new ApiController(
            configProvider: $configProvider,
            volumeRegistry: $registry,
            directoryLister: new DirectoryLister($registry, $normalizer, $cacheManager, new NullMetadataStorage()),
            operations: new FileOperationsService($registry, $normalizer, new FilenameNormalizer(), $trashManager, new NullMetadataStorage(), $dispatcher),
            remoteUrlImporter: new RemoteUrlImporter($uploadManager, $registry, $normalizer),
            trashManager: $trashManager,
            cacheManager: $cacheManager,
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            pathNormalizer: $normalizer,
            urlGenerator: $urlGenerator,
            twig: new Environment(new ArrayLoader([])),
            csrfValidator: new CsrfRequestValidator(null),
        );
    }
}
