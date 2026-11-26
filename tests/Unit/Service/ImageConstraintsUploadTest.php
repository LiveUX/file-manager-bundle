<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ImageConstraintsUploadTest extends TestCase
{
    private string $tempDir;
    private string $uploadDir;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            self::markTestSkipped('GD extension is required to generate test PNG fixtures.');
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_imgc_' . bin2hex(random_bytes(4));
        $this->uploadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'uploads';
        mkdir($this->uploadDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testRejectsImageBelowMinimumDimensions(): void
    {
        $profile = $this->createProfile([
            'min_width' => 400,
            'min_height' => 300,
        ]);
        $manager = $this->createUploadManager($profile);
        $tmpPng = $this->createPngFixture('too_small.png', 200, 200);

        try {
            $manager->handleImportedTempFile($profile, $tmpPng, 'too_small.png');
            self::fail('Expected UploadValidationException for small image.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.image_too_small', $e->getTranslationKey());
            self::assertSame(200, $e->getTranslationParams()['%width%']);
            self::assertSame(400, $e->getTranslationParams()['%minWidth%']);
        }
    }

    public function testRejectsImageAboveMaximumDimensions(): void
    {
        $profile = $this->createProfile([
            'max_width' => 300,
            'max_height' => 300,
        ]);
        $manager = $this->createUploadManager($profile);
        $tmpPng = $this->createPngFixture('too_large.png', 500, 250);

        try {
            $manager->handleImportedTempFile($profile, $tmpPng, 'too_large.png');
            self::fail('Expected UploadValidationException for oversized image.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.image_too_large_dim', $e->getTranslationKey());
            self::assertSame(500, $e->getTranslationParams()['%width%']);
            self::assertSame(300, $e->getTranslationParams()['%maxWidth%']);
        }
    }

    public function testEnforcesAspectRatioWithTolerance(): void
    {
        $profile = $this->createProfile([
            'aspect_ratio' => '16:9',
            'aspect_ratio_tolerance' => 0.05,
        ]);
        $manager = $this->createUploadManager($profile);

        // 300x300 (1:1) fails 16:9 check
        $squarePng = $this->createPngFixture('square.png', 300, 300);
        try {
            $manager->handleImportedTempFile($profile, $squarePng, 'square.png');
            self::fail('Expected UploadValidationException for wrong aspect ratio.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.image_aspect_ratio', $e->getTranslationKey());
            self::assertSame('16:9', $e->getTranslationParams()['%expected%']);
        }

        // 320x182 (~1.758 vs 1.777 -> 1.1% delta <= 5% tolerance) passes
        $widePng = $this->createPngFixture('banner.png', 320, 182);
        $result = $manager->handleImportedTempFile($profile, $widePng, 'banner.png');
        self::assertTrue($result['completed']);
        self::assertFileExists($this->uploadDir . DIRECTORY_SEPARATOR . $result['path']);
    }

    public function testNonImageFilesBypassImageConstraints(): void
    {
        $profile = $this->createProfile([
            'min_width' => 1200,
            'min_height' => 1200,
            'aspect_ratio' => '1:1',
        ]);
        $manager = $this->createUploadManager($profile);

        $pdfPath = $this->tempDir . DIRECTORY_SEPARATOR . 'brochure.pdf';
        file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $result = $manager->handleImportedTempFile($profile, $pdfPath, 'brochure.pdf');
        self::assertTrue($result['completed']);
        self::assertSame('brochure.pdf', $result['name']);
    }

    public function testImageOptimizationRunsBeforeMaxDimensionConstraint(): void
    {
        $profile = $this->createProfile(
            imageConstraints: [
                'min_width' => 200,
                'max_width' => 300,
                'max_height' => 300,
                'aspect_ratio' => '1:1',
            ],
            imageOptimization: [
                'enabled' => true,
                'max_width' => 280,
                'max_height' => 280,
                'convert_to_webp' => false,
                'quality' => 85,
            ],
        );
        $manager = $this->createUploadManager($profile);

        // Uploaded at 600x600 -> ImageOptimizer shrinks to 280x280 -> passes max_width: 300!
        $largeSquare = $this->createPngFixture('product.png', 600, 600);
        $result = $manager->handleImportedTempFile($profile, $largeSquare, 'product.png');
        self::assertTrue($result['completed']);

        $savedPath = $this->uploadDir . DIRECTORY_SEPARATOR . $result['path'];
        $info = @getimagesize($savedPath);
        self::assertIsArray($info);
        self::assertSame(280, $info[0]);
        self::assertSame(280, $info[1]);
    }

    /**
     * @param array<string, mixed> $imageConstraints
     * @param array<string, mixed> $imageOptimization
     */
    private function createProfile(array $imageConstraints, array $imageOptimization = ['enabled' => false]): ProfileConfig
    {
        return new ProfileConfig(
            name: 'test',
            basePath: $this->uploadDir,
            theme: 'shadcn',
            locale: 'en',
            volumes: ['local'],
            driver: 'LocalFileSystem',
            path: $this->uploadDir,
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'],
            uploadDeny: ['text/x-php'],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: ['enabled' => false],
            naming: ['slugger' => true],
            imageOptimization: $imageOptimization,
            thumbnail: ['enabled' => false],
            trash: ['enabled' => false],
            zip: ['enabled' => false],
            ui: [],
            permissions: [],
            security: ['voter_enabled' => false, 'csrf_protection' => false],
            cache: ['enabled' => false],
            metadata: ['enabled' => false],
            imageConstraints: $imageConstraints,
        );
    }

    private function createUploadManager(ProfileConfig $profile): UploadManager
    {
        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', $this->uploadDir);
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getProfile')->willReturn($profile);
        $configProvider->method('getVolume')->willReturn($volume);

        $registry = new VolumeRegistry($configProvider, new FlysystemStorageFactory(new DynamicPathResolver()));

        return new UploadManager(
            volumeRegistry: $registry,
            pathNormalizer: new PathNormalizer(),
            filenameNormalizer: new FilenameNormalizer(),
            eventDispatcher: new EventDispatcher(),
            imageOptimizer: new ImageOptimizer(),
        );
    }

    private function createPngFixture(string $filename, int $width, int $height): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);
        $color = imagecolorallocate($img, 59, 130, 246);
        imagefilledrectangle($img, 0, 0, $width, $height, (int) $color);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
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
