<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs LiveUX FileManagerBundle AI Agent Skills (SKILL.md) into the host project's
 * coding-agent directory (.agents/skills, .claude/skills, .cursor/skills, .github/skills).
 */
#[AsCommand(
    name: 'file-manager:skills:install',
    description: 'Installs LiveUX FileManagerBundle AI Agent Skills (SKILL.md) for Antigravity, Claude Code, Cursor, Copilot, and Codex.',
)]
final class InstallSkillsCommand extends Command
{
    /** @var array<string, string> */
    private const array TARGET_MAP = [
        'agents' => '.agents/skills',
        'claude' => '.claude/skills',
        'cursor' => '.cursor/skills',
        'copilot' => '.github/skills',
    ];

    public function __construct(
        private readonly string $bundleSkillsDir,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Target AI agent root: agents (.agents/skills), claude (.claude/skills), cursor (.cursor/skills), copilot (.github/skills), or all',
                'agents',
            )
            ->addOption(
                'dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom destination directory (overrides --target)',
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated skill names or short suffixes (e.g. "setup,security")',
            )
            ->addOption(
                'symlink',
                null,
                InputOption::VALUE_NONE,
                'Create symlinks instead of copying files (falls back to copy if OS denies symlink privilege)',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing skill directories in the target location',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview skills and destinations without writing files',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $availableSkills = $this->discoverBundleSkills();
        if ($availableSkills === []) {
            $io->error(sprintf('No skills found in bundle directory "%s".', $this->bundleSkillsDir));

            return Command::FAILURE;
        }

        $onlyOption = $input->getOption('only');
        $selectedSkills = $this->filterSkills($availableSkills, is_string($onlyOption) ? $onlyOption : null);
        if ($selectedSkills === []) {
            $io->error(sprintf(
                'No bundle skills matched --only="%s". Available: %s',
                (string) $onlyOption,
                implode(', ', array_keys($availableSkills)),
            ));

            return Command::FAILURE;
        }

        $destinations = $this->resolveDestinations($input, $io);
        if ($destinations === null) {
            return Command::FAILURE;
        }

        $useSymlink = (bool) $input->getOption('symlink');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $installed = 0;
        $skipped = 0;
        $rows = [];

        foreach ($destinations as $destRoot) {
            foreach ($selectedSkills as $skillName => $sourceDir) {
                $targetPath = $destRoot . DIRECTORY_SEPARATOR . $skillName;
                $status = $this->installSingleSkill($sourceDir, $targetPath, $useSymlink, $force, $dryRun);
                if ($status === 'skipped') {
                    ++$skipped;
                } else {
                    ++$installed;
                }
                $rows[] = [$skillName, $this->formatDisplayPath($targetPath), $status];
            }
        }

        $io->title('LiveUX FileManagerBundle — AI Agent Skills');
        $io->table(['Skill', 'Destination', 'Status'], $rows);

        if ($dryRun) {
            $io->note(sprintf('Dry run complete: %d skill(s) would be installed, %d skipped.', $installed, $skipped));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Processed %d skill(s) installed, %d skipped.', $installed, $skipped));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string> skill name => absolute source directory
     */
    private function discoverBundleSkills(): array
    {
        if (!is_dir($this->bundleSkillsDir)) {
            return [];
        }

        $entries = scandir($this->bundleSkillsDir);
        if ($entries === false) {
            return [];
        }

        $skills = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^[a-z0-9-]+$/', $entry) !== 1) {
                continue;
            }
            $dir = $this->bundleSkillsDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($dir) && is_file($dir . DIRECTORY_SEPARATOR . 'SKILL.md')) {
                $skills[$entry] = $dir;
            }
        }

        ksort($skills);

        return $skills;
    }

    /**
     * @param array<string, string> $availableSkills
     *
     * @return array<string, string>
     */
    private function filterSkills(array $availableSkills, ?string $onlyOption): array
    {
        if ($onlyOption === null || trim($onlyOption) === '') {
            return $availableSkills;
        }

        $tokens = array_values(array_filter(
            array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $onlyOption)),
            static fn (string $v): bool => $v !== '',
        ));

        $matched = [];
        foreach ($availableSkills as $name => $dir) {
            foreach ($tokens as $token) {
                if ($name === $token || str_ends_with($name, '-' . $token) || str_contains($name, $token)) {
                    $matched[$name] = $dir;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @return list<string>|null
     */
    private function resolveDestinations(InputInterface $input, SymfonyStyle $io): ?array
    {
        $customDir = $input->getOption('dir');
        if (is_string($customDir) && trim($customDir) !== '') {
            $resolved = $this->resolveCustomDirectory(trim($customDir));
            if ($resolved === null) {
                $io->error('Invalid --dir option: path must resolve to a subdirectory inside the project directory without ".." traversal.');

                return null;
            }

            return [$resolved];
        }

        $targetOpt = strtolower(trim((string) $input->getOption('target')));
        if ($targetOpt === 'all') {
            $all = [];
            foreach (self::TARGET_MAP as $relPath) {
                $all[] = $this->joinPath($this->projectDir, $relPath);
            }

            return $all;
        }

        if (!array_key_exists($targetOpt, self::TARGET_MAP)) {
            $io->error(sprintf(
                'Unknown --target "%s". Allowed values: %s, all.',
                $targetOpt,
                implode(', ', array_keys(self::TARGET_MAP)),
            ));

            return null;
        }

        return [$this->joinPath($this->projectDir, self::TARGET_MAP[$targetOpt])];
    }

    private function resolveCustomDirectory(string $rawDir): ?string
    {
        $normalized = str_replace('\\', '/', $rawDir);
        if (preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1 || str_contains($normalized, "\0")) {
            return null;
        }

        $isAbsolute = str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalized) === 1;

        $rawCombined = $isAbsolute
            ? $normalized
            : rtrim(str_replace('\\', '/', $this->projectDir), '/') . '/' . ltrim($normalized, '/');
        $canonicalSlashes = $this->collapseSingleDotSegments($rawCombined);
        $candidate = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $canonicalSlashes), DIRECTORY_SEPARATOR);

        $projectPrefix = $this->normalizeComparablePath(rtrim($this->projectDir, '/\\')) . '/';
        $candidateComparable = $this->normalizeComparablePath($candidate);
        if (!str_starts_with($candidateComparable, $projectPrefix) || $candidateComparable === rtrim($projectPrefix, '/')) {
            return null;
        }

        return $candidate;
    }

    private function collapseSingleDotSegments(string $slashPath): string
    {
        $prefix = '';
        $rest = $slashPath;
        if (preg_match('/^([a-zA-Z]:\/|\/)/', $slashPath, $m) === 1) {
            $prefix = $m[1];
            $rest = substr($slashPath, strlen($prefix));
        }

        $clean = [];
        foreach (explode('/', $rest) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $clean[] = $segment;
        }

        return $prefix . implode('/', $clean);
    }

    private function normalizeComparablePath(string $path): string
    {
        $clean = rtrim(str_replace('\\', '/', $path), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            return mb_strtolower($clean, 'UTF-8');
        }

        return $clean;
    }

    private function installSingleSkill(
        string $sourceDir,
        string $targetPath,
        bool $useSymlink,
        bool $force,
        bool $dryRun,
    ): string {
        $exists = is_dir($targetPath) || is_file($targetPath) || is_link($targetPath);
        if ($exists && !$force) {
            return 'skipped';
        }

        if ($dryRun) {
            return $exists ? 'would overwrite' : ($useSymlink ? 'would symlink' : 'would copy');
        }

        $parentDir = dirname($targetPath);
        $this->assertNoSymlinkedAncestors($parentDir);
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
            throw new RuntimeException(sprintf('Unable to create destination directory "%s".', $parentDir));
        }

        if ($exists) {
            $this->removePath($targetPath);
        }

        if ($useSymlink && @symlink($sourceDir, $targetPath)) {
            return 'symlinked';
        }

        $this->copyDirectory($sourceDir, $targetPath);

        return $useSymlink ? 'copied (symlink fallback)' : ($exists ? 'overwritten' : 'copied');
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $destination));
        }

        $entries = scandir($source);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $source . DIRECTORY_SEPARATOR . $entry;
            if (is_link($srcPath)) {
                continue;
            }
            $dstPath = $destination . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } elseif (!copy($srcPath, $dstPath)) {
                throw new RuntimeException(sprintf('Unable to copy "%s" to "%s".', $srcPath, $dstPath));
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path)) {
            // On Windows, directory symlinks require rmdir() rather than unlink().
            if (!@unlink($path) && is_dir($path)) {
                @rmdir($path);
            }

            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @rmdir($path);
    }

    private function assertNoSymlinkedAncestors(string $directory): void
    {
        $projectComparable = $this->normalizeComparablePath($this->projectDir);
        $cursor = $directory;
        $reachedProjectRoot = false;

        for ($depth = 0; $depth < 64; ++$depth) {
            $cursorComparable = $this->normalizeComparablePath($cursor);
            if ($cursorComparable === $projectComparable) {
                $reachedProjectRoot = true;
                break;
            }
            if ($cursorComparable === '' || !str_starts_with($cursorComparable, $projectComparable . '/')) {
                break;
            }
            if (is_link($cursor)) {
                throw new RuntimeException(sprintf('Refusing to write through symlinked ancestor directory "%s".', $cursor));
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        if (!$reachedProjectRoot) {
            throw new RuntimeException(sprintf('Refusing to write to directory "%s": ancestor validation did not reach project root.', $directory));
        }
    }

    private function joinPath(string $base, string $relative): string
    {
        $cleanBase = rtrim($base, '/\\');
        $cleanRel = str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/\\'));

        return $cleanBase . DIRECTORY_SEPARATOR . $cleanRel;
    }

    private function formatDisplayPath(string $absolutePath): string
    {
        $prefix = rtrim($this->projectDir, '/\\') . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $prefix)) {
            return str_replace('\\', '/', substr($absolutePath, strlen($prefix)));
        }

        return str_replace('\\', '/', $absolutePath);
    }
}
