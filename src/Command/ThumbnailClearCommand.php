<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:thumbs:clear',
    description: 'Clears generated thumbnail cache globally, for a specific profile/volume/path, or removes orphaned thumbnails.',
)]
final class ThumbnailClearCommand extends Command
{
    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly ProfileFileScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name to clear (clears all profiles if omitted)')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name filter')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Optional subfolder path filter')
            ->addOption('orphans', null, InputOption::VALUE_NONE, 'Only remove orphaned thumbnails whose original image no longer exists')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview how many thumbnails would be deleted without removing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $pathOpt = $input->getOption('path');
        $orphansOnly = (bool) $input->getOption('orphans');
        $dryRun = (bool) $input->getOption('dry-run');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $subPath = is_string($pathOpt) ? trim($pathOpt) : '';

        try {
            $profiles = $this->scanner->resolveTargetProfiles($profileName);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($orphansOnly) {
            if ($volumeFilter !== null || $subPath !== '') {
                $io->error('Cannot combine --orphans with --volume or --path filters (orphan cleanup scans full cache directories).');

                return Command::FAILURE;
            }
            $deleted = $this->clearOrphansForProfiles($profiles, $dryRun, $io);
            $this->reportSummary($io, $deleted, $dryRun, 'orphaned thumbnail');

            return Command::SUCCESS;
        }

        try {
            $deleted = $this->clearThumbnails($profiles, $profileName, $volumeFilter, $subPath, $dryRun, $io);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->reportSummary($io, $deleted, $dryRun, 'cached thumbnail');

        return Command::SUCCESS;
    }

    /**
     * @param list<ProfileConfig> $profiles
     */
    private function clearThumbnails(
        array $profiles,
        ?string $profileName,
        ?string $volumeFilter,
        string $subPath,
        bool $dryRun,
        SymfonyStyle $io,
    ): int {
        $hasScopedFilter = $volumeFilter !== null || $subPath !== '';
        if ($subPath !== '') {
            (new PathNormalizer())->normalize($subPath);
        }

        $dirShareCounts = $this->countCacheDirUsage();
        $wholeDirCandidates = [];
        $clearedDirs = [];
        $deleted = 0;
        $matchedAnyVolume = false;

        foreach ($profiles as $profile) {
            $cacheDir = (string) ($profile->thumbnail['cache_dir'] ?? '');
            if ($cacheDir === '') {
                continue;
            }

            $normalizedDir = $this->normalizeDirKey($cacheDir);
            $isSharedDir = $this->isDirShared($normalizedDir, $dirShareCounts);
            $canWipeWholeDir = !$hasScopedFilter && ($profileName === null || !$isSharedDir);

            if ($canWipeWholeDir) {
                $matchedAnyVolume = true;
                $wholeDirCandidates[$normalizedDir] = $cacheDir;
                continue;
            }

            try {
                $deleted += $this->clearScopedProfileThumbnails($profile, $volumeFilter, $subPath, $dryRun, $io);
                $matchedAnyVolume = true;
            } catch (InvalidPathException $e) {
                throw $e;
            } catch (FileManagerException $e) {
                if ($profileName !== null) {
                    throw $e;
                }
            }
        }

        if ($wholeDirCandidates !== []) {
            uksort($wholeDirCandidates, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
            foreach ($wholeDirCandidates as $normalizedDir => $cacheDir) {
                if (!isset($clearedDirs[$normalizedDir]) && !$this->isCoveredByClearedDir($normalizedDir, $clearedDirs)) {
                    $clearedDirs[$normalizedDir] = true;
                    $deleted += $this->thumbnailManager->clearCacheDir($cacheDir, $dryRun);
                }
            }
        }

        if ($volumeFilter !== null && !$matchedAnyVolume) {
            throw new FileManagerException(sprintf('Volume "%s" is not attached to any matched profile.', $volumeFilter));
        }

        return $deleted;
    }

    private function clearScopedProfileThumbnails(
        ProfileConfig $profile,
        ?string $volumeFilter,
        string $subPath,
        bool $dryRun,
        ?SymfonyStyle $io = null,
    ): int {
        $deleted = 0;
        foreach ($this->scanner->resolveTargetVolumes($profile, $volumeFilter) as $volume) {
            if ($this->scanner->volumeUsesUserIdToken($profile, $volume->name)) {
                $io?->note(sprintf(
                    'Skipped profile "%s" volume "%s": uses {user_id} placeholder.',
                    $profile->name,
                    $volume->name,
                ));
                continue;
            }
            foreach ($this->scanner->iterateRasterImages($profile, $volume, $subPath) as $img) {
                if (!$this->thumbnailManager->hasCachedThumbnail($profile, $img['canonical'])) {
                    continue;
                }
                if (!$dryRun) {
                    $this->thumbnailManager->deleteCachedThumbnail($profile, $img['canonical']);
                }
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * @param list<ProfileConfig> $targetProfiles
     */
    private function clearOrphansForProfiles(array $targetProfiles, bool $dryRun, SymfonyStyle $io): int
    {
        $rawTargetDirs = [];
        foreach ($targetProfiles as $profile) {
            $cacheDir = (string) ($profile->thumbnail['cache_dir'] ?? '');
            if ($cacheDir !== '') {
                $rawTargetDirs[$this->normalizeDirKey($cacheDir)] = $cacheDir;
            }
        }

        if ($rawTargetDirs === []) {
            return 0;
        }

        uksort($rawTargetDirs, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
        $targetDirs = [];
        $keptTargetKeys = [];
        foreach ($rawTargetDirs as $dirKey => $cacheDir) {
            if (!$this->isCoveredByClearedDir($dirKey, $keptTargetKeys)) {
                $targetDirs[$dirKey] = $cacheDir;
                $keptTargetKeys[$dirKey] = true;
            }
        }

        // Protect cache directories shared by profiles with dynamic {user_id}/{Y}/{m}/{d} volumes
        $unsafeDirs = [];
        $validPaths = [];
        foreach ($this->configProvider->getProfileNames() as $name) {
            $profile = $this->configProvider->getProfile($name);
            $cacheDir = (string) ($profile->thumbnail['cache_dir'] ?? '');
            $dirKey = $cacheDir !== '' ? $this->normalizeDirKey($cacheDir) : '';
            if ($dirKey === '') {
                continue;
            }
            $matchedTargets = $this->findOverlappingDirKeys($dirKey, $targetDirs);
            if ($matchedTargets === []) {
                continue;
            }

            if ($this->profileUsesDynamicVolumeTokens($profile)) {
                foreach ($matchedTargets as $targetKey) {
                    $unsafeDirs[$targetKey] = $profile->name;
                }
                continue;
            }

            $configuredFormat = strtolower((string) ($profile->thumbnail['format'] ?? 'webp'));
            foreach ($this->scanner->resolveTargetVolumes($profile, null) as $volume) {
                foreach ($this->scanner->iterateRasterImages($profile, $volume) as $img) {
                    $cachedPath = $this->thumbnailManager->getCachedPath($profile, $img['canonical']);
                    $candidates = [$cachedPath];
                    // Protect both .webp and .jpeg variants when Web SAPI and CLI PHP differ in WebP/Imagick availability.
                    if ($configuredFormat === 'webp' && $cachedPath !== '') {
                        if (str_ends_with($cachedPath, '.jpeg')) {
                            $candidates[] = substr($cachedPath, 0, -5) . '.webp';
                        } elseif (str_ends_with($cachedPath, '.webp')) {
                            $candidates[] = substr($cachedPath, 0, -5) . '.jpeg';
                        }
                    }
                    foreach ($candidates as $candidatePath) {
                        $real = realpath($candidatePath);
                        $key = str_replace('\\', '/', $real !== false ? $real : $candidatePath);
                        $validPaths[$key] = true;
                    }
                }
            }
        }

        $deleted = 0;
        foreach ($targetDirs as $dirKey => $cacheDir) {
            if (isset($unsafeDirs[$dirKey])) {
                $io->warning(sprintf(
                    'Skipping orphan cleanup for "%s": profile "%s" uses dynamic path tokens ({user_id}/{Y}/{m}/{d}) and cannot be safely enumerated in CLI.',
                    $cacheDir,
                    $unsafeDirs[$dirKey],
                ));
                continue;
            }
            $deleted += $this->thumbnailManager->purgeOrphans($cacheDir, $validPaths, $dryRun);
        }

        return $deleted;
    }

    private function profileUsesDynamicVolumeTokens(ProfileConfig $profile): bool
    {
        if ($profile->volumes === []) {
            $prefix = is_string($profile->driverOptions['prefix'] ?? null) ? $profile->driverOptions['prefix'] : '';

            return DynamicPathResolver::containsDynamicTokens($profile->path)
                || DynamicPathResolver::containsDynamicTokens($prefix);
        }

        foreach ($profile->volumes as $volName) {
            $rawVol = $this->configProvider->getVolume($volName);
            $prefix = is_string($rawVol->driverOptions['prefix'] ?? null) ? $rawVol->driverOptions['prefix'] : '';
            if (
                ($rawVol->path !== null && DynamicPathResolver::containsDynamicTokens($rawVol->path))
                || DynamicPathResolver::containsDynamicTokens($prefix)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    private function countCacheDirUsage(): array
    {
        $counts = [];
        foreach ($this->configProvider->getProfileNames() as $name) {
            $profile = $this->configProvider->getProfile($name);
            $dir = (string) ($profile->thumbnail['cache_dir'] ?? '');
            if ($dir === '') {
                continue;
            }
            $key = $this->normalizeDirKey($dir);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function normalizeDirKey(string $dir): string
    {
        $real = realpath($dir);
        $normalized = str_replace('\\', '/', rtrim($real !== false ? $real : $dir, '/\\'));

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized, 'UTF-8') : $normalized;
    }

    /**
     * @param array<string, int> $dirShareCounts
     */
    private function isDirShared(string $normalizedDir, array $dirShareCounts): bool
    {
        if (($dirShareCounts[$normalizedDir] ?? 0) > 1) {
            return true;
        }

        foreach (array_keys($dirShareCounts) as $otherDir) {
            if ($otherDir !== $normalizedDir && $this->dirsOverlap($normalizedDir, $otherDir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $clearedDirs
     */
    private function isCoveredByClearedDir(string $normalizedDir, array $clearedDirs): bool
    {
        foreach (array_keys($clearedDirs) as $cleared) {
            if ($cleared !== '' && str_starts_with($normalizedDir . '/', $cleared . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $targetDirs
     *
     * @return list<string>
     */
    private function findOverlappingDirKeys(string $dirKey, array $targetDirs): array
    {
        $matches = [];
        foreach (array_keys($targetDirs) as $targetKey) {
            if ($targetKey === $dirKey || $this->dirsOverlap($dirKey, $targetKey)) {
                $matches[] = $targetKey;
            }
        }

        return $matches;
    }

    private function dirsOverlap(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/');
    }

    private function reportSummary(SymfonyStyle $io, int $count, bool $dryRun, string $label): void
    {
        if ($dryRun) {
            $io->note(sprintf('[Dry-Run] Would remove %d %s file(s).', $count, $label));

            return;
        }

        $io->success(sprintf('Removed %d %s file(s).', $count, $label));
    }
}
