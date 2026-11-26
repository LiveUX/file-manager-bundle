<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Command;

use LiveUX\FileManagerBundle\Command\InstallSkillsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallSkillsCommandTest extends TestCase
{
    private string $tempProjectDir;
    private string $bundleSkillsDir;

    protected function setUp(): void
    {
        $this->tempProjectDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liveux_fm_skills_test_' . bin2hex(random_bytes(5));
        mkdir($this->tempProjectDir, 0775, true);
        $this->bundleSkillsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'skills';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempProjectDir);
    }

    public function testDryRunPreviewsWithoutWritingFiles(): void
    {
        $tester = new CommandTester(new InstallSkillsCommand($this->bundleSkillsDir, $this->tempProjectDir));
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('liveux-file-manager-setup', $tester->getDisplay());
        self::assertStringContainsString('would copy', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->tempProjectDir . DIRECTORY_SEPARATOR . '.agents');
    }

    public function testDefaultInstallCopiesSkillsAndReferencesAndSkipsOnRepeatWithoutForce(): void
    {
        $tester = new CommandTester(new InstallSkillsCommand($this->bundleSkillsDir, $this->tempProjectDir));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $setupSkill = $this->tempProjectDir . DIRECTORY_SEPARATOR . '.agents' . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'liveux-file-manager-setup';
        self::assertFileExists($setupSkill . DIRECTORY_SEPARATOR . 'SKILL.md');
        self::assertFileExists($setupSkill . DIRECTORY_SEPARATOR . 'references' . DIRECTORY_SEPARATOR . 'config-schema.md');

        // Re-running without --force skips existing directories
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('skipped', $tester->getDisplay());

        // Re-running with --force overwrites cleanly
        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertStringContainsString('overwritten', $tester->getDisplay());
    }

    public function testOnlyFilterAndTargetAllInstallExpectedDirectories(): void
    {
        $tester = new CommandTester(new InstallSkillsCommand($this->bundleSkillsDir, $this->tempProjectDir));
        $exitCode = $tester->execute([
            '--target' => 'all',
            '--only' => 'setup,security',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        foreach (['.agents/skills', '.claude/skills', '.cursor/skills', '.github/skills'] as $relRoot) {
            $root = $this->tempProjectDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relRoot);
            self::assertFileExists($root . DIRECTORY_SEPARATOR . 'liveux-file-manager-setup' . DIRECTORY_SEPARATOR . 'SKILL.md');
            self::assertFileExists($root . DIRECTORY_SEPARATOR . 'liveux-file-manager-security-and-rbac' . DIRECTORY_SEPARATOR . 'SKILL.md');
            self::assertDirectoryDoesNotExist($root . DIRECTORY_SEPARATOR . 'liveux-file-manager-theming-and-icons');
        }
    }

    public function testRejectsUnknownTargetAndTraversalDir(): void
    {
        $tester = new CommandTester(new InstallSkillsCommand($this->bundleSkillsDir, $this->tempProjectDir));

        self::assertSame(Command::FAILURE, $tester->execute(['--target' => 'invalid-agent']));
        self::assertSame(Command::FAILURE, $tester->execute(['--dir' => '../escape/skills']));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full) && !is_link($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
