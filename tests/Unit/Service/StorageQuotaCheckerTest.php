<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;

final class StorageQuotaCheckerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_quota_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testUnlimitedQuotaAllowsAnyUploadAndDisablesSummary(): void
    {
        [$checker, $profile] = $this->createChecker(null);

        $summary = $checker->getQuotaSummary($profile, 'local');
        self::assertFalse($summary['enabled']);
        self::assertSame(0, $summary['maxBytes']);

        // Must not throw
        $checker->assertHasQuota($profile, 'local', 100_000_000);
        $this->addToAssertionCount(1);
    }

    public function testEnforcesQuotaAndReportsAccurateSummary(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'existing.bin', str_repeat('A', 600));

        // 1KB quota (= 1024 bytes), supports both "1K" and "1KB" notation
        [$checker, $profile] = $this->createChecker('1KB');

        $summary = $checker->getQuotaSummary($profile, 'local');
        self::assertTrue($summary['enabled']);
        self::assertSame(600, $summary['usedBytes']);
        self::assertSame(1024, $summary['maxBytes']);
        self::assertGreaterThan(50.0, $summary['percent']);

        // 400 bytes fits within 1024 - 600 = 424 remaining
        $checker->assertHasQuota($profile, 'local', 400);

        // 500 bytes exceeds quota -> throws UploadValidationException with translation key
        try {
            $checker->assertHasQuota($profile, 'local', 500);
            self::fail('Expected UploadValidationException when storage quota is exceeded.');
        } catch (UploadValidationException $e) {
            self::assertSame('errors.storage_quota_exceeded', $e->getTranslationKey());
            self::assertSame(['%limit%' => '1KB'], $e->getTranslationParams());
        }
    }

    /**
     * @return array{0: StorageQuotaChecker, 1: ProfileConfig}
     */
    private function createChecker(?string $storageQuota): array
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => $this->tempDir, 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => $this->tempDir, 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'storage_quota' => $storageQuota]],
        );

        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $checker = new StorageQuotaChecker($registry, new PathNormalizer(), new DirectoryCacheManager());

        return [$checker, $provider->getProfile('default')];
    }
}
