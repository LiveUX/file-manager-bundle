<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Service;

use DateTimeImmutable;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use PHPUnit\Framework\TestCase;

final class FilenameNormalizerTest extends TestCase
{
    private FilenameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FilenameNormalizer();
    }

    public function testUploadAppliesSluggerAndDateAffixes(): void
    {
        $profile = $this->createProfile([
            'slugger' => true,
            'slugger_locale' => 'tr',
            'transformer' => 'none',
            'prepend' => '{Y}_',
            'append' => '_web',
        ]);

        $now = new DateTimeImmutable('2026-11-20 12:00:00');
        $result = $this->normalizer->normalizeForUpload('Ürün Görseli Şişe.JPG', $profile, '', $now);

        self::assertSame('2026-urun-gorseli-sise-web.jpg', $result);
    }

    public function testRenameDoesNotDuplicateAffixesByDefault(): void
    {
        $profile = $this->createProfile([
            'slugger' => true,
            'slugger_locale' => 'tr',
            'prepend' => '2026_',
            'apply_on_rename' => true,
            'apply_affixes_on_rename' => false,
            'allow_extension_change' => false,
        ]);

        $result = $this->normalizer->normalizeForRename('2026_old.webp', 'Yeni Ürün Adı', $profile);

        self::assertSame('yeni-urun-adi.webp', $result);
    }

    public function testRenameBlocksExtensionChangeWhenDisallowed(): void
    {
        $profile = $this->createProfile([
            'allow_extension_change' => false,
        ]);

        $this->expectException(UploadValidationException::class);
        $this->normalizer->normalizeForRename('photo.jpg', 'shell.php', $profile);
    }

    public function testSnakeTransformerWithoutSlugger(): void
    {
        $profile = $this->createProfile([
            'slugger' => false,
            'transformer' => 'snake',
        ]);

        self::assertSame('hello_world_file.png', $this->normalizer->normalizeForUpload('Hello World-File.PNG', $profile));
    }

    /**
     * @param array<string, mixed> $naming
     */
    private function createProfile(array $naming): ProfileConfig
    {
        return new ProfileConfig(
            name: 'test',
            basePath: '/tmp',
            theme: 'bootstrap5',
            locale: null,
            volumes: [],
            driver: 'LocalFileSystem',
            path: '/tmp',
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
            naming: $naming,
            imageOptimization: [],
            thumbnail: [],
            trash: [],
            zip: [],
            ui: [],
            permissions: [],
            security: [],
            cache: [],
            metadata: [],
        );
    }
}
