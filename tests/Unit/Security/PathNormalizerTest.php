<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase
{
    private PathNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PathNormalizer();
    }

    public function testNormalizeCleansSlashesAndDots(): void
    {
        self::assertSame('', $this->normalizer->normalize(''));
        self::assertSame('', $this->normalizer->normalize(' / /// . '));
        self::assertSame('products/promo/pens', $this->normalizer->normalize('/products\\promo//./pens/'));
    }

    public function testNormalizeRejectsPathTraversal(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->normalizer->normalize('products/../../etc/passwd');
    }

    public function testNormalizeRejectsNullBytes(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->normalizer->normalize("products/file\0.jpg");
    }

    public function testNormalizeRejectsStreamWrappers(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->normalizer->normalize('phar://evil.phar/test');
    }

    public function testCanonicalKeyMatchesNestedProfiles(): void
    {
        $basePath = '/var/www/public/uploads';
        $volProducts = new VolumeConfig('products', 'Products', 'local', 'LocalFileSystem', '/var/www/public/uploads/products');
        $volPromo = new VolumeConfig('promo', 'Promo', 'local', 'LocalFileSystem', '/var/www/public/uploads/products/promo');

        $keyA = $this->normalizer->buildCanonicalKey($volProducts, 'promo/pens', $basePath);
        $keyB = $this->normalizer->buildCanonicalKey($volPromo, 'pens', $basePath);

        self::assertSame('local::products/promo/pens', $keyA);
        self::assertSame($keyA, $keyB);
    }

    public function testCanonicalKeyForS3Volume(): void
    {
        $volS3 = new VolumeConfig('s3_cdn', 'S3 CDN', 'network', 'AwsS3', null, null, false, [
            'bucket' => 'my-cdn-bucket',
            'prefix' => 'media/',
        ]);

        self::assertSame(
            's3::my-cdn-bucket/media/banners/hero.webp',
            $this->normalizer->buildCanonicalKey($volS3, 'banners/hero.webp'),
        );
    }
}
