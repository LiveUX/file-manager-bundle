<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Components\BrowserComponent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class CapabilitiesPermissionTest extends TestCase
{
    public function testReadOnlyProfileHidesMutationCacheTrashDragAndDropzoneCapabilities(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_caps_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0775, true);

        try {
            $provider = new YamlConfigProvider(
                ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $tempDir, 'url_prefix' => '/uploads'],
                [
                    'local' => [
                        'label' => 'Local',
                        'type' => 'local',
                        'driver' => 'LocalFileSystem',
                        'path' => $tempDir,
                        'url_prefix' => '/uploads',
                        'private' => false,
                        'options' => [],
                    ],
                ],
                [
                    'readonly' => [
                        'volumes' => ['local'],
                        'permissions' => [
                            'view' => true,
                            'upload' => false,
                            'create_folder' => false,
                            'rename' => false,
                            'clone' => false,
                            'move' => false,
                            'copy' => false,
                            'delete' => false,
                            'trash_restore' => false,
                            'trash_purge' => false,
                            'download' => true,
                            'download_zip' => false,
                            'extract_zip' => false,
                            'share' => false,
                            'clear_cache' => false,
                            'edit_metadata' => false,
                            'ai_enrich' => false,
                        ],
                    ],
                    'admin' => [
                        'volumes' => ['local'],
                        'permissions' => [
                            'view' => true,
                            'upload' => true,
                            'create_folder' => true,
                            'rename' => true,
                            'clone' => true,
                            'move' => true,
                            'copy' => true,
                            'delete' => true,
                            'trash_restore' => true,
                            'trash_purge' => true,
                            'download' => true,
                            'download_zip' => true,
                            'extract_zip' => false,
                            'share' => true,
                            'clear_cache' => true,
                            'edit_metadata' => true,
                            'ai_enrich' => true,
                        ],
                    ],
                ],
            );

            $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
            $authChecker->method('isGranted')->willReturnCallback(
                static function (mixed $attribute, mixed $subject = null): bool {
                    if (!is_string($attribute) || !$subject instanceof FileManagerSubject) {
                        return false;
                    }
                    $key = FileManagerPermissions::toConfigKey($attribute);

                    return $key !== null && $subject->profile->getPermissionRule($key) === true;
                },
            );

            $component = $this->createBrowserComponent($provider, $authChecker);

            $component->mount(profile: 'readonly');
            $roCaps = $component->getCapabilities();
            self::assertFalse($roCaps['upload']);
            self::assertFalse($roCaps['editImage']);
            self::assertFalse($roCaps['remoteImport']);
            self::assertFalse($roCaps['createFolder']);
            self::assertFalse($roCaps['rename']);
            self::assertFalse($roCaps['move']);
            self::assertFalse($roCaps['copy']);
            self::assertFalse($roCaps['delete']);
            self::assertFalse($roCaps['clearCache']);
            self::assertFalse($roCaps['editMetadata']);
            self::assertFalse($roCaps['trashEnabled']);
            self::assertFalse($roCaps['allowDragMove']);
            self::assertFalse($roCaps['allowDropzoneUpload']);
            self::assertTrue($roCaps['download']);

            $adminComponent = $this->createBrowserComponent($provider, $authChecker);
            $adminComponent->mount(profile: 'admin');
            $adminCaps = $adminComponent->getCapabilities();
            self::assertTrue($adminCaps['clearCache']);
            self::assertTrue($adminCaps['upload']);
            self::assertTrue($adminCaps['trashEnabled']);
            self::assertTrue($adminCaps['allowDragMove']);
            self::assertTrue($adminCaps['allowDropzoneUpload']);
        } finally {
            @rmdir($tempDir);
        }
    }

    private function createBrowserComponent(YamlConfigProvider $provider, AuthorizationCheckerInterface $authChecker): BrowserComponent
    {
        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $dispatcher = new EventDispatcher();
        $iconsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'icons';

        $urlGenerator = new class implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name;
            }
        };

        return new BrowserComponent(
            configProvider: $provider,
            volumeRegistry: $registry,
            directoryLister: new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage()),
            trashManager: new TrashManager($registry, $normalizer, $dispatcher),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            zipArchiveService: new ZipArchiveService($registry, $normalizer),
            pathNormalizer: $normalizer,
            urlGenerator: $urlGenerator,
            iconSetRegistry: new IconSetRegistry($iconsDir),
            csrfValidator: new CsrfRequestValidator(),
            translator: null,
            authChecker: $authChecker,
        );
    }
}
