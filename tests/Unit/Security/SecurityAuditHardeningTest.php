<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\Controller\UploadController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Components\BrowserComponent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SecurityAuditHardeningTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_sec_audit_' . bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testQuotaVisibilityConfigDefaultsAndServerSideEnforcement(): void
    {
        $processor = new Processor();
        $processed = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [],
                'hidden_quota' => [
                    'storage_quota' => '100M',
                    'ui' => ['show_quota' => false],
                ],
                'bar_only' => [
                    'storage_quota' => '100M',
                    'ui' => ['show_quota' => true, 'show_quota_details' => false],
                ],
            ],
        ]]);

        self::assertTrue($processed['profiles']['default']['ui']['show_quota']);
        self::assertTrue($processed['profiles']['default']['ui']['show_quota_details']);
        self::assertFalse($processed['profiles']['hidden_quota']['ui']['show_quota']);
        self::assertTrue($processed['profiles']['hidden_quota']['ui']['show_quota_details']);
        self::assertTrue($processed['profiles']['bar_only']['ui']['show_quota']);
        self::assertFalse($processed['profiles']['bar_only']['ui']['show_quota_details']);

        $provider = $this->createProvider([
            'hidden_quota' => [
                'volumes' => ['local'],
                'storage_quota' => '100M',
                'ui' => ['show_quota' => false, 'show_quota_details' => true],
            ],
            'bar_only' => [
                'volumes' => ['local'],
                'storage_quota' => '100M',
                'ui' => ['show_quota' => true, 'show_quota_details' => false],
            ],
        ]);

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $quotaChecker = new StorageQuotaChecker($registry, $normalizer, new DirectoryCacheManager());

        $hiddenComp = $this->createBrowserComponent($provider, $registry, $quotaChecker);
        $hiddenComp->mount(profile: 'hidden_quota');
        $hiddenCaps = $hiddenComp->getCapabilities();
        self::assertFalse($hiddenCaps['showQuota']);
        self::assertFalse($hiddenCaps['showQuotaDetails']);
        self::assertFalse($hiddenComp->getQuotaSummary()['enabled']);

        $barOnlyComp = $this->createBrowserComponent($provider, $registry, $quotaChecker);
        $barOnlyComp->mount(profile: 'bar_only');
        $barOnlyCaps = $barOnlyComp->getCapabilities();
        self::assertTrue($barOnlyCaps['showQuota']);
        self::assertFalse($barOnlyCaps['showQuotaDetails']);
        self::assertTrue($barOnlyComp->getQuotaSummary()['enabled']);

        $api = $this->createApiController($provider, $registry, $quotaChecker);

        $listResp = $api->list(Request::create('/api/list', 'GET', ['profile' => 'hidden_quota']));
        $listData = json_decode((string) $listResp->getContent(), true);
        self::assertSame(Response::HTTP_OK, $listResp->getStatusCode());
        self::assertNull($listData['quota']);

        foreach (['hidden_quota', 'bar_only'] as $profileName) {
            $req = Request::create(
                '/api/action',
                'POST',
                server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
                content: (string) json_encode(['action' => 'quotaDetails', 'profile' => $profileName]),
            );
            $resp = $api->action($req);
            self::assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode(), "quotaDetails must return 403 for profile {$profileName}");
        }
    }

    public function testThumbnailControllerPreventsPublicCdnCachingOnPrivateOrVoterProfiles(): void
    {
        $pngBinary = $this->createMinimalPngBinary();
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'secret.png', $pngBinary);
        $thumbCache = $this->tempDir . DIRECTORY_SEPARATOR . 'thumbs';

        $provider = $this->createProvider(
            [
                'public_open' => [
                    'volumes' => ['public_vol'],
                    'security' => ['voter_enabled' => false],
                    'thumbnail' => ['cache_dir' => $thumbCache],
                ],
                'private_vol_profile' => [
                    'volumes' => ['private_vol'],
                    'security' => ['voter_enabled' => false],
                    'thumbnail' => ['cache_dir' => $thumbCache],
                ],
                'voter_protected' => [
                    'volumes' => ['public_vol'],
                    'security' => ['voter_enabled' => true],
                    'thumbnail' => ['cache_dir' => $thumbCache],
                ],
            ],
            [
                'public_vol' => $this->localVolume($this->tempDir, false),
                'private_vol' => $this->localVolume($this->tempDir, true),
            ],
        );

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $allowAll = $this->createStub(AuthorizationCheckerInterface::class);
        $allowAll->method('isGranted')->willReturn(true);

        $controller = new ThumbnailController(
            configProvider: $provider,
            volumeRegistry: $registry,
            pathNormalizer: new PathNormalizer(),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            authChecker: $allowAll,
        );

        $openResp = $controller(Request::create('/thumb', 'GET', ['profile' => 'public_open', 'path' => 'secret.png']));
        self::assertStringContainsString('public', (string) $openResp->headers->get('Cache-Control'));

        foreach (['private_vol_profile', 'voter_protected'] as $protectedProfile) {
            $resp = $controller(Request::create('/thumb', 'GET', ['profile' => $protectedProfile, 'path' => 'secret.png']));
            $cacheControl = (string) $resp->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl, "{$protectedProfile} thumbnail must be private");
            self::assertStringNotContainsString('public', $cacheControl, "{$protectedProfile} thumbnail must NOT be public");
            self::assertStringNotContainsString('s-maxage', $cacheControl, "{$protectedProfile} thumbnail must NOT emit s-maxage");
            self::assertSame('no-referrer', $resp->headers->get('Referrer-Policy'));
        }
    }

    public function testDownloadControllerBlocksDotfilesAndProtectsPrivateHeaders(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . '.env', 'APP_SECRET=topsecret');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'report.txt', 'hello');

        $provider = $this->createProvider(
            [
                'open_dotfiles' => [
                    'volumes' => ['private_vol'],
                    'ignore_dot_files' => false,
                    'security' => ['voter_enabled' => false],
                ],
            ],
            [
                'private_vol' => $this->localVolume($this->tempDir, true),
            ],
        );

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $controller = new DownloadController($provider, $registry, new PathNormalizer());

        $safeResp = $controller(Request::create('/download', 'GET', ['profile' => 'open_dotfiles', 'path' => 'report.txt']));
        self::assertSame('no-referrer', $safeResp->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $safeResp->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $safeResp->headers->get('Cache-Control'));

        $this->expectException(BadRequestHttpException::class);
        $controller(Request::create('/download', 'GET', ['profile' => 'open_dotfiles', 'path' => '.env']));
    }

    public function testApiTrashEndpointDeniesReadOnlyViewer(): void
    {
        $provider = $this->createProvider([
            'readonly' => [
                'volumes' => ['local'],
                'security' => ['voter_enabled' => true],
                'permissions' => [
                    'view' => true,
                    'delete' => false,
                    'trash_restore' => false,
                    'trash_purge' => false,
                ],
            ],
            'editor' => [
                'volumes' => ['local'],
                'security' => ['voter_enabled' => true],
                'permissions' => [
                    'view' => true,
                    'delete' => true,
                    'trash_restore' => false,
                    'trash_purge' => false,
                ],
            ],
        ]);

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

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $api = $this->createApiController($provider, $registry, null, $authChecker);

        $roResp = $api->trash(Request::create('/api/trash', 'GET', ['profile' => 'readonly']));
        self::assertSame(Response::HTTP_FORBIDDEN, $roResp->getStatusCode());

        $editorResp = $api->trash(Request::create('/api/trash', 'GET', ['profile' => 'editor']));
        self::assertSame(Response::HTTP_OK, $editorResp->getStatusCode());
    }

    public function testImageEditorEnforcesCsrfFirstAndRespectsProfileMimePolicy(): void
    {
        $provider = $this->createProvider([
            'jpeg_only' => [
                'volumes' => ['local'],
                'upload_allow' => ['image/jpeg'],
                'security' => ['voter_enabled' => false, 'csrf_protection' => true],
            ],
        ]);

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $token->getValue() === 'valid-token',
        );
        $csrfValidator = new CsrfRequestValidator($tokenManager);

        $controller = new ImageEditorController(
            configProvider: $provider,
            volumeRegistry: $registry,
            pathNormalizer: new PathNormalizer(),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            csrfValidator: $csrfValidator,
            eventDispatcher: new EventDispatcher(),
        );

        // Missing file + missing CSRF must return 403 (CSRF validated before file check)
        $noCsrfReq = Request::create('/api/image-edit', 'POST', ['profile' => 'jpeg_only']);
        $noCsrfResp = $controller($noCsrfReq);
        self::assertSame(Response::HTTP_FORBIDDEN, $noCsrfResp->getStatusCode());

        // Valid CSRF + PNG upload on jpeg_only profile must be rejected (422)
        $tmpPng = $this->tempDir . DIRECTORY_SEPARATOR . 'edited.png';
        file_put_contents($tmpPng, $this->createMinimalPngBinary());
        $uploaded = new UploadedFile($tmpPng, 'edited.png', 'image/png', null, true);

        $pngReq = Request::create(
            '/api/image-edit',
            'POST',
            ['profile' => 'jpeg_only', 'path' => 'photo.jpg', 'saveMode' => 'copy'],
            files: ['file' => $uploaded],
            server: ['HTTP_X_LIVEUX_FM_CSRF' => 'valid-token'],
        );
        $pngResp = $controller($pngReq);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $pngResp->getStatusCode());
    }

    public function testUploadControllerEnforcesCsrfBeforeMissingFileValidation(): void
    {
        $provider = $this->createProvider([
            'strict_csrf' => [
                'volumes' => ['local'],
                'security' => ['voter_enabled' => false, 'csrf_protection' => true],
            ],
        ]);

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('isTokenValid')->willReturn(false);

        $controller = new UploadController(
            configProvider: $provider,
            uploadManager: new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher()),
            csrfValidator: new CsrfRequestValidator($tokenManager),
        );

        $resp = $controller(Request::create('/api/upload', 'POST', ['profile' => 'strict_csrf']));
        self::assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }

    public function testUploadControllerHandlesPhpIniUploadErrorsGracefullyAndAssemblesChunks(): void
    {
        $provider = $this->createProvider([
            'chunked' => [
                'volumes' => ['local'],
                'upload_max_size' => '50M',
                'security' => ['voter_enabled' => false, 'csrf_protection' => false],
            ],
        ]);

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $controller = new UploadController(
            configProvider: $provider,
            uploadManager: new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher()),
            csrfValidator: new CsrfRequestValidator(),
        );

        // 1. Invalid UploadedFile (UPLOAD_ERR_INI_SIZE) returns clean 422 JSON instead of crashing with 500
        $dummy = $this->tempDir . DIRECTORY_SEPARATOR . 'oversize.bin';
        file_put_contents($dummy, 'x');
        $invalidUpload = new UploadedFile($dummy, 'oversize.mp4', 'video/mp4', UPLOAD_ERR_INI_SIZE, true);
        $errResp = $controller(Request::create(
            '/api/upload',
            'POST',
            ['profile' => 'chunked'],
            files: ['file' => $invalidUpload],
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        ));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $errResp->getStatusCode());
        $errBody = json_decode((string) $errResp->getContent(), true);
        self::assertFalse($errBody['ok']);
        self::assertStringContainsString('upload_max_filesize', (string) $errBody['error']);

        // 2. Real 3-chunk upload assembles byte-for-byte identically
        $chunks = ['AAA-PART-1-', 'BBB-PART-2-', 'CCC-PART-3'];
        $uploadId = 'up_test_chunk_' . bin2hex(random_bytes(4));
        $lastResp = null;
        foreach ($chunks as $idx => $bytes) {
            $tmpPart = $this->tempDir . DIRECTORY_SEPARATOR . 'chunk_' . $idx . '.tmp';
            file_put_contents($tmpPart, $bytes);
            $uploaded = new UploadedFile($tmpPart, 'demo.txt', 'text/plain', null, true);
            $lastResp = $controller(Request::create(
                '/api/upload',
                'POST',
                [
                    'profile' => 'chunked',
                    'uploadId' => $uploadId,
                    'chunkIndex' => (string) $idx,
                    'totalChunks' => (string) count($chunks),
                    'filename' => 'demo.txt',
                ],
                files: ['file' => $uploaded],
                server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            ));
            self::assertSame(Response::HTTP_OK, $lastResp->getStatusCode());
        }

        $finalData = json_decode((string) $lastResp?->getContent(), true);
        self::assertTrue($finalData['ok']);
        self::assertTrue($finalData['completed']);
        self::assertSame(implode('', $chunks), file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . $finalData['path']));

        // 3. Aborting an in-progress chunked upload cleans up server temp chunks via abortUpload action
        $abortedUploadId = 'up_abort_test_' . bin2hex(random_bytes(4));
        $tmpPart = $this->tempDir . DIRECTORY_SEPARATOR . 'abort_part0.tmp';
        file_put_contents($tmpPart, 'PARTIAL-DATA');
        $partResp = $controller(Request::create(
            '/api/upload',
            'POST',
            [
                'profile' => 'chunked',
                'uploadId' => $abortedUploadId,
                'chunkIndex' => '0',
                'totalChunks' => '3',
                'filename' => 'cancelled.mp4',
            ],
            files: ['file' => new UploadedFile($tmpPart, 'cancelled.mp4', 'video/mp4', null, true)],
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        ));
        self::assertSame(Response::HTTP_OK, $partResp->getStatusCode());

        $uploadManager = new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher());
        $profile = $provider->getProfile('chunked');
        self::assertTrue($uploadManager->abortChunkedUpload($profile, $abortedUploadId, null));
        self::assertFalse($uploadManager->abortChunkedUpload($profile, $abortedUploadId, null), 'Second abort returns false once directory is cleaned');
        self::assertFalse($uploadManager->abortChunkedUpload($profile, 'bad', null), 'Short/invalid uploadId is rejected');
    }

    public function testDownloadControllerSupportsHttpByteRangesAndInlinePdfCsp(): void
    {
        $payload = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'clip.mp4', $payload);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'doc.pdf', "%PDF-1.4\n%EOF\n");

        $provider = $this->createProvider([
            'media' => [
                'volumes' => ['local'],
                'security' => ['voter_enabled' => false],
            ],
        ]);

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $controller = new DownloadController($provider, $registry, new PathNormalizer());

        // Byte range request returns 206 Partial Content with exact byte slice
        $rangeReq = Request::create(
            '/download',
            'GET',
            ['profile' => 'media', 'path' => 'clip.mp4', 'inline' => '1'],
            server: ['HTTP_RANGE' => 'bytes=4-13'],
        );
        $rangeResp = $controller($rangeReq);
        self::assertSame(Response::HTTP_PARTIAL_CONTENT, $rangeResp->getStatusCode());
        self::assertSame('bytes', $rangeResp->headers->get('Accept-Ranges'));
        self::assertSame('bytes 4-13/' . strlen($payload), $rangeResp->headers->get('Content-Range'));
        self::assertSame('10', $rangeResp->headers->get('Content-Length'));

        ob_start();
        $rangeResp->sendContent();
        $streamed = (string) ob_get_clean();
        self::assertSame('456789ABCD', $streamed);

        // Inline PDF emits script-none CSP compatible with Chromium/Firefox built-in PDF viewers
        $pdfResp = $controller(Request::create('/download', 'GET', ['profile' => 'media', 'path' => 'doc.pdf', 'inline' => '1']));
        self::assertSame(Response::HTTP_OK, $pdfResp->getStatusCode());
        $csp = (string) $pdfResp->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'none'", $csp);
        self::assertStringContainsString("object-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function testPerProfilePdfAndVideoPreviewConfigDisablesCapabilitiesAndForcesAttachment(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'contract.pdf', "%PDF-1.4\n%EOF\n");

        $processor = new Processor();
        $processed = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [],
                'no_preview' => [
                    'ui' => ['allow_pdf_preview' => false, 'allow_video_preview' => false],
                ],
            ],
        ]]);

        self::assertTrue($processed['profiles']['default']['ui']['allow_pdf_preview']);
        self::assertTrue($processed['profiles']['default']['ui']['allow_video_preview']);
        self::assertFalse($processed['profiles']['no_preview']['ui']['allow_pdf_preview']);
        self::assertFalse($processed['profiles']['no_preview']['ui']['allow_video_preview']);

        $provider = $this->createProvider([
            'no_preview' => [
                'volumes' => ['local'],
                'security' => ['voter_enabled' => false],
                'ui' => ['allow_pdf_preview' => false, 'allow_video_preview' => false],
            ],
        ]);

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $quotaChecker = new StorageQuotaChecker($registry, new PathNormalizer(), new DirectoryCacheManager());
        $comp = $this->createBrowserComponent($provider, $registry, $quotaChecker);
        $comp->mount(profile: 'no_preview');
        $caps = $comp->getCapabilities();
        self::assertFalse($caps['allowPdfPreview']);
        self::assertFalse($caps['allowVideoPreview']);

        // DownloadController forces Content-Disposition: attachment when allow_pdf_preview=false
        $controller = new DownloadController($provider, $registry, new PathNormalizer());
        $pdfResp = $controller(Request::create('/download', 'GET', ['profile' => 'no_preview', 'path' => 'contract.pdf', 'inline' => '1']));
        self::assertStringStartsWith('attachment;', (string) $pdfResp->headers->get('Content-Disposition'));
    }

    public function testZipDownloadChecksPerPathVoterAndSetsSecurityHeaders(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required for ZIP download test.');
        }

        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'public', 0775, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'secret', 0775, true);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'ok.txt', 'ok');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'secret' . DIRECTORY_SEPARATOR . 'hidden.txt', 'nope');

        $provider = $this->createProvider([
            'scoped' => [
                'volumes' => ['local'],
                'security' => ['voter_enabled' => true, 'csrf_protection' => false],
            ],
        ]);

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject = null): bool {
                if (!in_array($attribute, [FileManagerPermissions::VIEW, FileManagerPermissions::DOWNLOAD_ZIP], true) || !$subject instanceof FileManagerSubject) {
                    return false;
                }

                return !str_starts_with($subject->path, 'secret');
            },
        );

        $controller = new ZipDownloadController(
            configProvider: $provider,
            zipArchiveService: new ZipArchiveService($registry, new PathNormalizer()),
            csrfValidator: new CsrfRequestValidator(),
            authChecker: $authChecker,
        );

        $deniedReq = Request::create(
            '/api/zip',
            'POST',
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            content: (string) json_encode(['profile' => 'scoped', 'paths' => ['public/ok.txt', 'secret/hidden.txt']]),
        );
        $deniedResp = $controller($deniedReq);
        self::assertSame(Response::HTTP_FORBIDDEN, $deniedResp->getStatusCode());

        $okReq = Request::create(
            '/api/zip',
            'POST',
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            content: (string) json_encode(['profile' => 'scoped', 'paths' => ['public/ok.txt']]),
        );
        $okResp = $controller($okReq);
        self::assertSame(Response::HTTP_OK, $okResp->getStatusCode());
        self::assertSame('nosniff', $okResp->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $okResp->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $okResp->headers->get('Cache-Control'));

        // Clean up the generated temp zip file since send() wasn't invoked
        if ($okResp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            @unlink($okResp->getFile()->getPathname());
        }
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @param array<string, array<string, mixed>>|null $volumes
     */
    private function createProvider(array $profiles, ?array $volumes = null): YamlConfigProvider
    {
        return new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            $volumes ?? ['local' => $this->localVolume($this->tempDir, false)],
            $profiles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function localVolume(string $dir, bool $private): array
    {
        return [
            'label' => $private ? 'Private' : 'Local',
            'type' => 'local',
            'driver' => 'LocalFileSystem',
            'path' => $dir,
            'url_prefix' => $private ? null : '/uploads',
            'private' => $private,
            'options' => [],
        ];
    }

    private function createBrowserComponent(
        YamlConfigProvider $provider,
        VolumeRegistry $registry,
        StorageQuotaChecker $quotaChecker,
    ): BrowserComponent {
        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $iconsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'icons';

        return new BrowserComponent(
            configProvider: $provider,
            volumeRegistry: $registry,
            directoryLister: new DirectoryLister($registry, $normalizer, new DirectoryCacheManager(), new NullMetadataStorage()),
            trashManager: new TrashManager($registry, $normalizer, $dispatcher),
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            zipArchiveService: new ZipArchiveService($registry, $normalizer),
            pathNormalizer: $normalizer,
            urlGenerator: $this->createFakeUrlGenerator(),
            iconSetRegistry: new IconSetRegistry($iconsDir),
            csrfValidator: new CsrfRequestValidator(),
            translator: null,
            authChecker: null,
            quotaChecker: $quotaChecker,
        );
    }

    private function createApiController(
        YamlConfigProvider $provider,
        VolumeRegistry $registry,
        ?StorageQuotaChecker $quotaChecker = null,
        ?AuthorizationCheckerInterface $authChecker = null,
    ): ApiController {
        $normalizer = new PathNormalizer();
        $dispatcher = new EventDispatcher();
        $cacheManager = new DirectoryCacheManager();
        $metadata = new NullMetadataStorage();
        $filenameNormalizer = new FilenameNormalizer();
        $trashManager = new TrashManager($registry, $normalizer, $dispatcher);
        $uploadManager = new UploadManager($registry, $normalizer, $filenameNormalizer, $dispatcher);

        return new ApiController(
            configProvider: $provider,
            volumeRegistry: $registry,
            directoryLister: new DirectoryLister($registry, $normalizer, $cacheManager, $metadata),
            operations: new FileOperationsService($registry, $normalizer, $filenameNormalizer, $trashManager, $metadata, $dispatcher),
            remoteUrlImporter: new RemoteUrlImporter($uploadManager),
            trashManager: $trashManager,
            cacheManager: $cacheManager,
            thumbnailManager: new ThumbnailManager(new ImagickThumbnailDriver(), new GdThumbnailDriver()),
            pathNormalizer: $normalizer,
            urlGenerator: $this->createFakeUrlGenerator(),
            twig: new Environment(new ArrayLoader([])),
            csrfValidator: new CsrfRequestValidator(),
            authChecker: $authChecker,
            quotaChecker: $quotaChecker,
        );
    }

    private function createFakeUrlGenerator(): UrlGeneratorInterface
    {
        return new class () implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void {}

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name;
            }
        };
    }

    private function createMinimalPngBinary(): string
    {
        // Valid 1x1 transparent PNG
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
