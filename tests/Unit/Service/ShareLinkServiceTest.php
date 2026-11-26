<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ShareLinkServiceTest extends TestCase
{
    private const string TEST_SECRET = 'liveux-unit-test-hmac-secret-2026';

    private string $tempDir;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_share_' . bin2hex(random_bytes(4));
        $this->storageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'private_vault';
        mkdir($this->storageDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testCreateSignedUrlAndServeFileSuccessfully(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'contract.txt', 'CONFIDENTIAL-CONTRACT-2026');

        [$provider, $registry, $normalizer, $signer, $service] = $this->buildStack();
        $profile = $provider->getProfile('default');

        self::assertTrue($service->isAvailableForProfile($profile));

        $signed = $service->createSignedUrl($profile, 'contract.txt', 'vault', 3600, true);
        self::assertStringContainsString('_hash=', $signed['url']);
        self::assertStringContainsString('_expiration=', $signed['url']);
        self::assertSame(3600, $signed['ttlSeconds']);
        self::assertGreaterThan(time() + 3500, $signed['expiresAt']);

        $controller = new ShareController($provider, $registry, $normalizer, $service);
        $response = $controller(Request::create($signed['url']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        self::assertSame('CONFIDENTIAL-CONTRACT-2026', $body);
    }

    public function testTamperedOrUnsignedLinkIsRejectedWith403(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'a.txt', 'secret-a');
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'b.txt', 'secret-b');

        [$provider, $registry, $normalizer, $signer, $service] = $this->buildStack();
        $profile = $provider->getProfile('default');
        $signed = $service->createSignedUrl($profile, 'a.txt', 'vault', 3600, false);
        $controller = new ShareController($provider, $registry, $normalizer, $service);

        // 1. Tamper path parameter -> 403
        $tampered = str_replace('path=a.txt', 'path=b.txt', $signed['url']);
        try {
            $controller(Request::create($tampered));
            self::fail('Expected AccessDeniedHttpException for tampered share URL.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        // 2. Completely unsigned request -> 403
        try {
            $controller(Request::create('https://example.test/_liveux-file-manager/share?profile=default&volume=vault&path=a.txt'));
            self::fail('Expected AccessDeniedHttpException for unsigned share URL.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        // 3. Valid hash WITHOUT _expiration -> 403 (non-expiring URLs blocked)
        $nonExpiring = $signer->sign('https://example.test/_liveux-file-manager/share?profile=default&volume=vault&path=a.txt');
        try {
            $controller(Request::create($nonExpiring));
            self::fail('Expected AccessDeniedHttpException for non-expiring signed URL.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testExpiredSignedLinkIsRejectedWith410Gone(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'old.txt', 'expired-doc');

        [$provider, $registry, $normalizer, $signer, $service] = $this->buildStack();
        $baseExpiredUrl = 'https://example.test/_liveux-file-manager/share?inline=0&path=old.txt&profile=default&volume=vault';
        $expiredAt = time() - 30;
        $expiredUrl = method_exists($signer, 'verify')
            ? $signer->sign($baseExpiredUrl, $expiredAt)
            : $signer->sign($baseExpiredUrl . '&_expiration=' . $expiredAt);

        $controller = new ShareController($provider, $registry, $normalizer, $service);
        $this->expectException(GoneHttpException::class);
        $controller(Request::create($expiredUrl));
    }

    public function testDisablingShareOnProfileImmediatelyRevokesExistingLinks(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'doc.txt', 'revoked');

        [$enabledProvider, , , $signer, $enabledService] = $this->buildStack(['enabled' => true]);
        $signed = $enabledService->createSignedUrl($enabledProvider->getProfile('default'), 'doc.txt', 'vault', 3600);

        [$disabledProvider, $disabledRegistry, $disabledNorm, , $disabledService] = $this->buildStack(['enabled' => false]);
        $controller = new ShareController($disabledProvider, $disabledRegistry, $disabledNorm, $disabledService);

        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create($signed['url']));
    }

    public function testActiveMimeAlwaysForcesAttachmentEvenWhenInlineRequested(): void
    {
        file_put_contents(
            $this->storageDir . DIRECTORY_SEPARATOR . 'vector.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="5"/></svg>',
        );

        [$provider, $registry, $normalizer, , $service] = $this->buildStack(['enabled' => true, 'allow_inline_preview' => true]);
        $signed = $service->createSignedUrl($provider->getProfile('default'), 'vector.svg', 'vault', 600, true);

        $controller = new ShareController($provider, $registry, $normalizer, $service);
        $response = $controller(Request::create($signed['url']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function testTtlClampingAndGracefulDegradationWithoutUriSigner(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'file.txt', 'data');

        [$provider, $registry, $normalizer, , $service] = $this->buildStack(['enabled' => true, 'default_ttl' => 300, 'max_ttl' => 1800]);
        $profile = $provider->getProfile('default');

        // Below 60s -> clamped up to 60s
        $minSigned = $service->createSignedUrl($profile, 'file.txt', 'vault', 5);
        self::assertSame(60, $minSigned['ttlSeconds']);

        // Above max_ttl (1800s) -> clamped down to 1800s
        $maxSigned = $service->createSignedUrl($profile, 'file.txt', 'vault', 999999);
        self::assertSame(1800, $maxSigned['ttlSeconds']);

        // Without UriSigner -> gracefully unavailable
        $nullService = new ShareLinkService($registry, $normalizer, $this->createFakeUrlGenerator(), null);
        self::assertFalse($nullService->isAvailableForProfile($profile));

        try {
            $nullService->createSignedUrl($profile, 'file.txt', 'vault', 300);
            self::fail('Expected FileManagerException when UriSigner is unavailable.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.share_disabled', $e->getTranslationKey());
        }
    }

    public function testShareVoterPermissionBlocksUnauthorizedLinkGeneration(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'locked.txt', 'no-share');

        $denyAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyAuth->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $attribute !== FileManagerPermissions::SHARE,
        );

        [$provider, $registry, $normalizer, $signer] = $this->buildStack(
            shareCfg: ['enabled' => true],
            securityCfg: ['enable_voter' => true],
        );

        $service = new ShareLinkService($registry, $normalizer, $this->createFakeUrlGenerator(), $signer, $denyAuth);

        $this->expectException(AccessDeniedException::class);
        $service->createSignedUrl($provider->getProfile('default'), 'locked.txt', 'vault', 600);
    }

    /**
     * @param array<string, mixed> $shareCfg
     * @param array<string, mixed> $securityCfg
     *
     * @return array{0: YamlConfigProvider, 1: VolumeRegistry, 2: PathNormalizer, 3: UriSigner, 4: ShareLinkService}
     */
    private function buildStack(array $shareCfg = ['enabled' => true], array $securityCfg = ['enable_voter' => false]): array
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->storageDir, 'url_prefix' => '/uploads'],
            [
                'vault' => [
                    'label' => 'Private Vault',
                    'type' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->storageDir,
                    'url_prefix' => '',
                    'private' => true,
                    'options' => [],
                ],
            ],
            [
                'default' => [
                    'volumes' => ['vault'],
                    'share' => $shareCfg,
                    'security' => $securityCfg,
                ],
            ],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $signer = new UriSigner(self::TEST_SECRET);
        $service = new ShareLinkService($registry, $normalizer, $this->createFakeUrlGenerator(), $signer);

        return [$provider, $registry, $normalizer, $signer, $service];
    }

    private function createFakeUrlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext('', 'GET', 'example.test', 'https');
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $qs = http_build_query($parameters, '', '&');

                return 'https://example.test/_liveux-file-manager/share' . ($qs !== '' ? '?' . $qs : '');
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
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
