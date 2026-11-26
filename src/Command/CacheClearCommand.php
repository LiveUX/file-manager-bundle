<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:cache:clear',
    description: 'Clears cached directory listings and quota caches globally, per profile, or for a specific volume/path.',
)]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly DirectoryCacheManager $cacheManager,
        private readonly ?ConfigProviderInterface $configProvider = null,
        private readonly ?VolumeRegistry $volumeRegistry = null,
        private readonly PathNormalizer $pathNormalizer = new PathNormalizer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name to invalidate')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name (used with profile & --path)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Optional specific directory path to invalidate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $pathOpt = $input->getOption('path');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $hasPathOption = is_string($pathOpt);
        $subPath = $hasPathOption ? trim($pathOpt) : null;

        if ($profileName === null) {
            if ($volumeFilter !== null || $hasPathOption) {
                $io->error('You must pass a profile argument when using --volume or --path.');

                return Command::FAILURE;
            }
            $this->cacheManager->invalidateAll();
            $io->success('FileManager cache cleared for all profiles.');

            return Command::SUCCESS;
        }

        if ($this->configProvider !== null) {
            try {
                $profile = $this->configProvider->getProfile($profileName);
            } catch (FileManagerException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            if ($this->volumeRegistry !== null && ($hasPathOption || $volumeFilter !== null)) {
                try {
                    $rawVolume = $this->volumeRegistry->getRawVolumeForProfile($profile, $volumeFilter);
                    if (ShareLinkService::volumeUsesUserIdToken($rawVolume) || ShareLinkService::volumeUsesDateTokens($rawVolume)) {
                        $this->cacheManager->invalidateProfile($profileName);
                        $io->success(sprintf('FileManager profile cache cleared for "%s" (volume "%s" uses runtime dynamic path tokens).', $profileName, $rawVolume->name));

                        return Command::SUCCESS;
                    }
                    $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeFilter);
                    if ($hasPathOption) {
                        $cleanPath = $this->pathNormalizer->normalize($subPath);
                        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
                        $this->cacheManager->invalidateDirectory($canonical);
                        foreach ([
                            DirectoryLister::TREE_CACHE_KEY,
                            StorageQuotaChecker::QUOTA_CACHE_KEY,
                            StorageQuotaChecker::QUOTA_DETAIL_CACHE_KEY,
                            '__sha256__',
                            '__sha256_hashes__',
                        ] as $volumeMetaKey) {
                            $this->cacheManager->invalidateDirectory(
                                $this->pathNormalizer->buildCanonicalKey($volume, $volumeMetaKey, $profile->basePath),
                            );
                        }
                        $io->success(sprintf('FileManager directory cache cleared for "%s" (%s:/%s).', $profileName, $volume->name, $cleanPath));

                        return Command::SUCCESS;
                    }

                    $rootCanonical = $this->pathNormalizer->buildCanonicalKey($volume, '', $profile->basePath);
                    $this->cacheManager->invalidateVolumeScope($this->cacheManager->resolveVolumeScopeForRoot($rootCanonical));
                    $io->success(sprintf('FileManager volume cache cleared for "%s" (volume "%s").', $profileName, $volume->name));

                    return Command::SUCCESS;
                } catch (FileManagerException $e) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
            }
        }

        $this->cacheManager->invalidateProfile($profileName);
        $io->success(sprintf('FileManager cache cleared for profile "%s".', $profileName));

        return Command::SUCCESS;
    }
}
