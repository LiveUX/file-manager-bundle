<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SkillsPackageIntegrityTest extends TestCase
{
    private const array EXPECTED_SKILLS = [
        'liveux-file-manager-contributing',
        'liveux-file-manager-extensions-and-ai',
        'liveux-file-manager-forms-and-editors',
        'liveux-file-manager-security-and-rbac',
        'liveux-file-manager-setup',
        'liveux-file-manager-theming-and-icons',
    ];

    public function testAgentsMdExistsAndRespectsSizeBudget(): void
    {
        $bundleRoot = dirname(__DIR__, 3);
        $agentsMd = $bundleRoot . DIRECTORY_SEPARATOR . 'AGENTS.md';
        self::assertFileExists($agentsMd);

        $content = (string) file_get_contents($agentsMd);
        self::assertNotSame('', trim($content));
        self::assertLessThanOrEqual(24000, strlen($content), 'AGENTS.md must stay under the 24 KB context budget.');
        self::assertStringStartsNotWith('---', ltrim($content), 'Standalone AGENTS.md must not include YAML frontmatter.');
    }

    public function testComposerJsonDeclaresAiMateSkillsDiscovery(): void
    {
        $composerFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'composer.json';
        $decoded = json_decode((string) file_get_contents($composerFile), true);

        self::assertIsArray($decoded);
        self::assertSame(['skills'], $decoded['extra']['ai-mate']['skills'] ?? null);
    }

    public function testAllSkillsConformToOpenAgentSkillsSpecification(): void
    {
        $skillsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'skills';
        self::assertDirectoryExists($skillsRoot);

        $discovered = [];
        foreach (scandir($skillsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $skillsRoot . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($dir)) {
                $discovered[] = $entry;
            }
        }
        sort($discovered);

        self::assertSame(self::EXPECTED_SKILLS, $discovered);

        foreach ($discovered as $skillFolder) {
            $skillDir = $skillsRoot . DIRECTORY_SEPARATOR . $skillFolder;
            $skillFile = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';
            self::assertFileExists($skillFile, "Missing SKILL.md in {$skillFolder}");

            $raw = (string) file_get_contents($skillFile);
            $lines = preg_split('/\R/', $raw) ?: [];
            self::assertLessThanOrEqual(500, count($lines), "{$skillFolder}/SKILL.md should stay concise (<= 500 lines).");

            $parsed = preg_match('/\A---\R(.*?)\R---\R/s', $raw, $matches);
            self::assertSame(1, $parsed, "{$skillFolder}/SKILL.md must begin with valid YAML frontmatter.");

            $frontmatter = Yaml::parse($matches[1]);
            self::assertIsArray($frontmatter, "{$skillFolder}/SKILL.md frontmatter must parse as an array.");
            self::assertSame($skillFolder, $frontmatter['name'] ?? null, 'Frontmatter name must strictly match folder name.');
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string) ($frontmatter['name'] ?? ''));
            self::assertIsString($frontmatter['description'] ?? null);
            self::assertGreaterThanOrEqual(40, strlen(trim($frontmatter['description'])));
            self::assertSame('MIT', $frontmatter['license'] ?? null);

            // Verify every relative reference link resolves to an actual file on disk
            preg_match_all('/\[[^\]]+\]\((\.?\/?(?:references|scripts|examples|resources|assets)\/[^)#\s]+)\)/', $raw, $linkMatches);
            foreach ($linkMatches[1] as $relativeLink) {
                $cleanRel = ltrim($relativeLink, './');
                $targetFile = $skillDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRel);
                self::assertFileExists($targetFile, "Broken relative link '{$relativeLink}' inside {$skillFolder}/SKILL.md");
            }
        }
    }
}
