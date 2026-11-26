<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\AI;

use FilesystemIterator;
use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use LiveUX\FileManagerBundle\AI\NullAssetEnricher;
use LiveUX\FileManagerBundle\Bridge\SymfonyAI\SymfonyAiAssetEnricher;
use LiveUX\FileManagerBundle\Command\EnrichMetadataCommand;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AssetEnrichmentTest extends TestCase
{
    private string $tempDir;
    private string $storageDir;
    private string $metaFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_ai_' . bin2hex(random_bytes(4));
        $this->storageDir = $this->tempDir . DIRECTORY_SEPARATOR . 'uploads';
        $this->metaFile = $this->tempDir . DIRECTORY_SEPARATOR . 'metadata.json';
        mkdir($this->storageDir, 0775, true);
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

    public function testResultSanitizesControlCharsTruncatesAndNormalizesTags(): void
    {
        $result = new AssetEnrichmentResult(
            alt: "  Mat siyah termos\x00 görseli  ",
            title: str_repeat('A', 300),
            caption: '  Kampanya ürünü  ',
            tags: ['  Termos ', 'termos', 'Kurumsal Hediye', ''],
            provider: 'symfony/ai',
            model: 'gpt-4o-mini',
        );

        self::assertFalse($result->isEmpty());
        self::assertSame('Mat siyah termos görseli', $result->alt);
        self::assertSame(255, mb_strlen((string) $result->title, 'UTF-8'));
        self::assertSame('Kampanya ürünü', $result->caption);
        self::assertSame(['termos', 'kurumsal hediye'], $result->tags);
    }

    public function testSymfonyAiJsonParserHandlesPlainFencedAndMalformedResponses(): void
    {
        // 1. Clean JSON
        $plain = SymfonyAiAssetEnricher::parseJsonResponse(
            '{"alt":"Mat siyah çelik termos","title":"Kurumsal Termos","caption":"Sıcak ve soğuk içecekler için.","tags":["termos","siyah","promosyon"]}',
            2,
            'symfony/ai',
            'gpt-4o-mini',
        );
        self::assertSame('Mat siyah çelik termos', $plain->alt);
        self::assertSame('Kurumsal Termos', $plain->title);
        self::assertSame(['termos', 'siyah'], $plain->tags);

        // 2. Markdown ```json ... ``` fenced output
        $fenced = SymfonyAiAssetEnricher::parseJsonResponse(
            "Here is the JSON:\n```json\n{\"alt\":\"Tokyo tower at sunset\",\"title\":\"Tokyo Tower\",\"caption\":\"Evening view.\",\"tags\":\"tokyo, sunset, japan\"}\n```",
            5,
        );
        self::assertSame('Tokyo tower at sunset', $fenced->alt);
        self::assertSame(['tokyo', 'sunset', 'japan'], $fenced->tags);

        // 3. Non-JSON plain text fallback -> populates alt safely
        $fallback = SymfonyAiAssetEnricher::parseJsonResponse('A minimalist wooden desk setup');
        self::assertSame('A minimalist wooden desk setup', $fallback->alt);
        self::assertSame([], $fallback->tags);
    }

    public function testSuggestMetadataEnforcesDisabledProfilePrivateVolumeAndVoterPolicies(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'notes.txt', 'Quarterly marketing campaign overview.');

        $fakeEnricher = $this->createFakeEnricher();

        // 1. NullAssetEnricher -> gracefully unavailable even if ai.enabled=true
        [$provider, $registry, $normalizer, $metaStorage] = $this->buildStack(aiCfg: ['enabled' => true]);
        $nullSvc = new AssetEnrichmentService($registry, $normalizer, $metaStorage, new DirectoryCacheManager(), new ImageOptimizer(), new NullAssetEnricher());
        self::assertFalse($nullSvc->isAvailableForProfile($provider->getProfile('default')));

        // 2. ai.enabled=false -> unavailable
        [$offProvider, $offReg, $offNorm, $offMeta] = $this->buildStack(aiCfg: ['enabled' => false]);
        $offSvc = new AssetEnrichmentService($offReg, $offNorm, $offMeta, new DirectoryCacheManager(), new ImageOptimizer(), $fakeEnricher);
        self::assertFalse($offSvc->isAvailableForProfile($offProvider->getProfile('default')));
        try {
            $offSvc->suggestMetadata($offProvider->getProfile('default'), 'notes.txt', 'local');
            self::fail('Expected FileManagerException when AI is disabled.');
        } catch (FileManagerException $e) {
            self::assertSame('errors.ai_unavailable', $e->getTranslationKey());
        }

        // 3. block_private_volumes=true on private volume -> AccessDeniedException
        [$privProvider, $privReg, $privNorm, $privMeta] = $this->buildStack(
            aiCfg: ['enabled' => true, 'block_private_volumes' => true],
            privateVolume: true,
        );
        $privSvc = new AssetEnrichmentService($privReg, $privNorm, $privMeta, new DirectoryCacheManager(), new ImageOptimizer(), $fakeEnricher);
        try {
            $privSvc->suggestMetadata($privProvider->getProfile('default'), 'notes.txt', 'local');
            self::fail('Expected AccessDeniedException on private volume.');
        } catch (AccessDeniedException $e) {
            self::assertSame('errors.ai_private_blocked', $e->getTranslationKey());
        }

        // 4. Voter denying FILE_MANAGER_AI_ENRICH -> AccessDeniedException
        $denyAuth = $this->createStub(AuthorizationCheckerInterface::class);
        $denyAuth->method('isGranted')->willReturnCallback(
            static fn (mixed $attr): bool => $attr !== FileManagerPermissions::AI_ENRICH,
        );
        [$vProvider, $vReg, $vNorm, $vMeta] = $this->buildStack(aiCfg: ['enabled' => true], voterEnabled: true);
        $vSvc = new AssetEnrichmentService($vReg, $vNorm, $vMeta, new DirectoryCacheManager(), new ImageOptimizer(), $fakeEnricher, $denyAuth);
        $this->expectException(AccessDeniedException::class);
        $vSvc->suggestMetadata($vProvider->getProfile('default'), 'notes.txt', 'local');
    }

    public function testSuggestDoesNotMutateAndEnrichAndSaveRespectsOnlyMissingAndFocalPoint(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'brief.txt', 'Product launch brief for 2026.');

        [$provider, $registry, $normalizer, $metaStorage] = $this->buildStack(aiCfg: ['enabled' => true, 'model' => 'gemini-2.5-flash']);
        $profile = $provider->getProfile('default');
        $volume = $registry->getVolumeForProfile($profile, 'local');
        $canonical = $normalizer->buildCanonicalKey($volume, 'brief.txt', $profile->basePath);

        $svc = new AssetEnrichmentService($registry, $normalizer, $metaStorage, new DirectoryCacheManager(), new ImageOptimizer(), $this->createFakeEnricher());

        // 1. suggestMetadata returns suggestion WITHOUT saving
        $suggestion = $svc->suggestMetadata($profile, 'brief.txt', 'local', 'tr');
        self::assertSame('AI Alt [tr]: brief.txt', $suggestion->alt);
        self::assertSame(['ai-tag', '2026'], $suggestion->tags);
        self::assertTrue($metaStorage->get($canonical)->isEmpty());

        // 2. Seed existing manual alt & focal point, then run enrichAndSave(onlyMissing: true)
        $metaStorage->save($canonical, new FolderMetadata(
            alt: 'Manuel Alt Metin',
            focalPoint: ['x' => 0.25, 'y' => 0.75],
        ));
        $svc->enrichAndSave($profile, 'brief.txt', 'local', onlyMissing: true, localeOverride: 'tr');

        $saved = $metaStorage->get($canonical);
        self::assertSame('Manuel Alt Metin', $saved->alt, 'Manual alt must be preserved in onlyMissing mode.');
        self::assertSame('AI Title: brief.txt', $saved->title, 'Missing title should be filled.');
        self::assertSame(['ai-tag', '2026'], $saved->tags, 'Missing tags should be filled.');
        self::assertSame(['x' => 0.25, 'y' => 0.75], $saved->focalPoint, 'Existing focalPoint must remain intact.');

        // 3. enrichAndSave(onlyMissing: false) overwrites manual alt while keeping focalPoint
        $svc->enrichAndSave($profile, 'brief.txt', 'local', onlyMissing: false, localeOverride: 'en');
        $overwritten = $metaStorage->get($canonical);
        self::assertSame('AI Alt [en]: brief.txt', $overwritten->alt);
        self::assertSame(['x' => 0.25, 'y' => 0.75], $overwritten->focalPoint);
    }

    public function testEnrichCliCommandSupportsDryRunLimitAndGracefulSkip(): void
    {
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'one.txt', 'First file');
        file_put_contents($this->storageDir . DIRECTORY_SEPARATOR . 'two.txt', 'Second file');

        [$provider, $registry, $normalizer, $metaStorage] = $this->buildStack(aiCfg: ['enabled' => true]);
        $scanner = new ProfileFileScanner($provider, $registry, $normalizer);

        // 1. Graceful exit when no enricher is installed
        $nullSvc = new AssetEnrichmentService($registry, $normalizer, $metaStorage, new DirectoryCacheManager(), new ImageOptimizer(), null);
        $nullTester = new CommandTester(new EnrichMetadataCommand($scanner, $nullSvc, $metaStorage));
        self::assertSame(Command::SUCCESS, $nullTester->execute([]));
        self::assertStringContainsString('No AssetEnricherInterface service is configured', $nullTester->getDisplay());

        // 2. Dry-run with --limit=1 lists 1 file and saves nothing
        $svc = new AssetEnrichmentService($registry, $normalizer, $metaStorage, new DirectoryCacheManager(), new ImageOptimizer(), $this->createFakeEnricher());
        $tester = new CommandTester(new EnrichMetadataCommand($scanner, $svc, $metaStorage));
        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'default', '--limit' => '1', '--dry-run' => true]));
        self::assertStringContainsString('[Dry-Run] Done: 1 enriched', $tester->getDisplay());
        self::assertFileDoesNotExist($this->metaFile);

        // 3. Real run enriches both files, second run skips both (already have alt & tags)
        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'default', '--limit' => '10']));
        self::assertStringContainsString('Done: 2 enriched, 0 skipped, 0 error(s).', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'default', '--limit' => '10']));
        self::assertStringContainsString('Done: 0 enriched, 2 skipped, 0 error(s).', $tester->getDisplay());
    }

    private function createFakeEnricher(): AssetEnricherInterface
    {
        return new class implements AssetEnricherInterface {
            public function supports(string $mimeType): bool
            {
                return str_starts_with($mimeType, 'text/') || str_starts_with($mimeType, 'image/');
            }

            /** @param array<string, mixed> $context */
            public function enrich(string $localFilePath, string $mimeType, string $locale = 'en', array $context = []): AssetEnrichmentResult
            {
                $name = (string) ($context['filename'] ?? 'asset');

                return new AssetEnrichmentResult(
                    alt: sprintf('AI Alt [%s]: %s', $locale, $name),
                    title: 'AI Title: ' . $name,
                    caption: 'AI Caption for ' . $name,
                    tags: ['ai-tag', '2026'],
                    provider: 'fake-test',
                    model: is_string($context['model'] ?? null) ? $context['model'] : 'test-model',
                );
            }
        };
    }

    /**
     * @param array<string, mixed> $aiCfg
     *
     * @return array{0: YamlConfigProvider, 1: VolumeRegistry, 2: PathNormalizer, 3: JsonFileMetadataStorage}
     */
    private function buildStack(array $aiCfg = [], bool $privateVolume = false, bool $voterEnabled = false): array
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'tr', 'base_path' => $this->storageDir, 'url_prefix' => '/uploads'],
            [
                'local' => [
                    'label' => 'Local',
                    'type' => 'local',
                    'driver' => 'LocalFileSystem',
                    'path' => $this->storageDir,
                    'url_prefix' => $privateVolume ? '' : '/uploads',
                    'private' => $privateVolume,
                    'options' => [],
                ],
            ],
            [
                'default' => [
                    'volumes' => ['local'],
                    'ai' => $aiCfg,
                    'security' => ['voter_enabled' => $voterEnabled],
                ],
            ],
        );

        $normalizer = new PathNormalizer();
        $registry = new VolumeRegistry($provider, new FlysystemStorageFactory(new DynamicPathResolver()));
        $metaStorage = new JsonFileMetadataStorage($this->metaFile);

        return [$provider, $registry, $normalizer, $metaStorage];
    }
}
