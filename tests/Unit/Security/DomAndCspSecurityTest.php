<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use FilesystemIterator;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
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
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class DomAndCspSecurityTest extends TestCase
{
    private string $bundleRoot;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->bundleRoot = dirname(__DIR__, 3);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_domsec_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
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

    public function testAllTwigTemplatesHaveZeroInlineEventHandlersZeroRawFilterAndSafeExternalLinks(): void
    {
        $templatesDir = $this->bundleRoot . DIRECTORY_SEPARATOR . 'templates';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesDir, FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            ++$checked;
            $content = (string) file_get_contents($file->getPathname());
            $rel = str_replace($this->bundleRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());

            // 1. Strict CSP: zero inline event handlers (onclick, onerror, onload, etc.)
            self::assertDoesNotMatchRegularExpression(
                '/\s+on[a-z]+\s*=/i',
                $content,
                sprintf('Template "%s" must not contain inline event handlers (breaks strict CSP).', $rel),
            );

            // 2. Zero |raw filter in bundle templates (all output auto-escaped by Twig)
            self::assertDoesNotMatchRegularExpression(
                '/\|\s*raw\b/i',
                $content,
                sprintf('Template "%s" must not use |raw filter.', $rel),
            );

            // 3. Zero <script> tags or javascript: URLs inside bundle templates
            self::assertDoesNotMatchRegularExpression(
                '/<script\b|javascript\s*:/i',
                $content,
                sprintf('Template "%s" must not contain <script> or javascript: URLs.', $rel),
            );

            // 4. Every target="_blank" link must include rel="noopener noreferrer"
            if (preg_match_all('/<a\b[^>]*target="_blank"[^>]*>/i', $content, $matches)) {
                foreach ($matches[0] as $anchorTag) {
                    self::assertStringContainsString(
                        'noopener',
                        $anchorTag,
                        sprintf('External link in "%s" must set rel="noopener noreferrer".', $rel),
                    );
                }
            }
        }

        self::assertGreaterThanOrEqual(25, $checked);
    }

    public function testDialogHostTemplateCastsUntrustedZIndexToSafeInteger(): void
    {
        $twig = $this->createMinimalTwig();
        $html = $twig->render('@LiveUXFileManager/components/DialogHost.html.twig', [
            'profile' => 'default"><script>alert(1)</script>',
            'locale' => 'en',
            'zIndex' => '1050; background: url(javascript:alert(1))',
        ]);

        self::assertStringContainsString('data-liveux--file-manager-bundle--dialog-z-index-value="1050"', $html);
        self::assertStringNotContainsString('style=', $html, 'DialogHost must avoid inline style attributes for strict CSP.');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('background:', $html);
    }

    public function testPickerTemplateBlocksInlineEventHandlerAttributes(): void
    {
        $twig = $this->createMinimalTwig();
        $wrapper = $twig->createTemplate(
            "{% use '@LiveUXFileManager/form/picker.html.twig' %}{{ block('liveux_file_manager_picker_widget') }}",
        );

        $html = $wrapper->render([
            'id' => 'f_1',
            'full_name' => 'form[image]',
            'value' => '/uploads/pic.jpg',
            'profile' => 'default',
            'locale' => 'en',
            'volume' => '',
            'theme' => 'bootstrap5',
            'variant' => 'input',
            'multiple' => false,
            'max_files' => 0,
            'mode' => 'modal',
            'position' => 'end',
            'return_type' => 'url',
            'preview' => true,
            'button_label' => null,
            'placeholder' => null,
            'attr' => [
                'data-safe' => 'ok',
                'onclick' => 'alert(1)',
                'ONFOCUS' => 'alert(2)',
            ],
        ]);

        self::assertStringContainsString('data-safe="ok"', $html);
        self::assertDoesNotMatchRegularExpression('/\sonclick\s*=/i', $html);
        self::assertDoesNotMatchRegularExpression('/\sonfocus\s*=/i', $html);
    }

    public function testDownloadAndShareControllersHardenPolyglotPdfAndIsolateValidInlinePdf(): void
    {
        // Minimal valid PDF header bytes so finfo detects application/pdf
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'real.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        // HTML payload disguised with .pdf extension
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'polyglot.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');

        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            [
                'local' => [
                    'label' => 'Local',
                    'type' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->tempDir,
                    'url_prefix' => '/uploads',
                    'private' => false,
                    'options' => [],
                ],
            ],
            ['default' => ['volumes' => ['local'], 'share' => ['enabled' => true, 'allow_inline_preview' => true]]],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $downloadController = new DownloadController($provider, $registry, $normalizer);

        // 1. Genuine PDF inline -> allowed with strict PDF CSP + nosniff
        $realPdfRes = $downloadController(Request::create('/_liveux-file-manager/download?profile=default&volume=local&path=real.pdf&inline=1'));
        self::assertSame(Response::HTTP_OK, $realPdfRes->getStatusCode());
        self::assertSame('nosniff', $realPdfRes->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('inline;', (string) $realPdfRes->headers->get('Content-Disposition'));
        self::assertSame("default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; object-src 'self'; frame-ancestors 'self'", $realPdfRes->headers->get('Content-Security-Policy'));

        // 2. HTML disguised as .pdf -> forced attachment + sandbox CSP!
        $fakePdfRes = $downloadController(Request::create('/_liveux-file-manager/download?profile=default&volume=local&path=polyglot.pdf&inline=1'));
        self::assertSame(Response::HTTP_OK, $fakePdfRes->getStatusCode());
        self::assertSame('nosniff', $fakePdfRes->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment;', (string) $fakePdfRes->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $fakePdfRes->headers->get('Content-Security-Policy'));

        // 3. Same protection on ShareController
        $signer = new UriSigner('test-secret-pdf-dom-2026');
        $fakeUrlGen = new class implements \Symfony\Component\Routing\Generator\UrlGeneratorInterface {
            private \Symfony\Component\Routing\RequestContext $ctx;

            public function __construct()
            {
                $this->ctx = new \Symfony\Component\Routing\RequestContext();
            }

            public function setContext(\Symfony\Component\Routing\RequestContext $context): void
            {
                $this->ctx = $context;
            }

            public function getContext(): \Symfony\Component\Routing\RequestContext
            {
                return $this->ctx;
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'https://example.test/_liveux-file-manager/share?' . http_build_query($parameters, '', '&');
            }
        };
        $shareService = new ShareLinkService($registry, $normalizer, $fakeUrlGen, $signer);
        $shareController = new ShareController($provider, $registry, $normalizer, $shareService);

        $signedFake = $shareService->createSignedUrl($provider->getProfile('default'), 'polyglot.pdf', 'local', 600, true);
        $shareFakeRes = $shareController(Request::create($signedFake['url']));
        self::assertStringStartsWith('attachment;', (string) $shareFakeRes->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $shareFakeRes->headers->get('Content-Security-Policy'));
    }

    public function testAutoInjectDialogHostSkipsAttachmentHtmlResponses(): void
    {
        $twig = $this->createMinimalTwig();
        $subscriber = new AutoInjectDialogHostSubscriber($twig, true, 'default', 1050);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/export.html');
        $response = new Response('<html><body>Hello</body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="export.html"',
        ]);

        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('<html><body>Hello</body></html>', $response->getContent());
    }

    private function createMinimalTwig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->bundleRoot . DIRECTORY_SEPARATOR . 'templates', 'LiveUXFileManager');
        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => '/_liveux-file-manager/' . $name));
        $twig->addFunction(new TwigFunction('liveux_fm_trans', static fn (string $id): string => $id));
        $twig->addFunction(new TwigFunction('liveux_fm_bcp47_locale', static fn (?string $locale = null): string => \LiveUX\FileManagerBundle\Service\MessageTranslator::toBcp47($locale)));
        $twig->addFunction(new TwigFunction('liveux_fm_is_rtl', static fn (?string $locale = null): bool => \LiveUX\FileManagerBundle\Service\MessageTranslator::isRtlLocale($locale)));
        $twig->addFilter(new TwigFilter('trans', static fn (string $id): string => $id));
        $twig->addFilter(new TwigFilter('liveux_fm_trans', static fn (string $id): string => $id));

        return $twig;
    }
}
