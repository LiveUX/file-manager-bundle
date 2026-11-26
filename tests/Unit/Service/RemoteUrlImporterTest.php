<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RemoteUrlImporterTest extends TestCase
{
    public function testBlocksLoopbackAndMetadataSsrfUrls(): void
    {
        $registry = new VolumeRegistry(new YamlConfigProvider([], [], []), new FlysystemStorageFactory(new DynamicPathResolver()));
        $uploadManager = new UploadManager($registry, new PathNormalizer(), new FilenameNormalizer(), new EventDispatcher());
        $importer = new RemoteUrlImporter($uploadManager);

        $blocked = [
            'http://127.0.0.1/secret.txt',
            'http://localhost/admin',
            'http://169.254.169.254/latest/meta-data/',
            'http://192.168.1.1/router.png',
            'http://10.0.0.1/internal.pdf',
            'file:///etc/passwd',
            'ftp://example.com/file.zip',
        ];

        foreach ($blocked as $url) {
            try {
                $importer->assertSafeUrl($url);
                self::fail(sprintf('Expected SSRF URL "%s" to be blocked.', $url));
            } catch (UploadValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
