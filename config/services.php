<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\NullAssetEnricher;
use LiveUX\FileManagerBundle\Command\CacheClearCommand;
use LiveUX\FileManagerBundle\Command\DoctorCommand;
use LiveUX\FileManagerBundle\Command\DuplicatesCommand;
use LiveUX\FileManagerBundle\Command\EnrichMetadataCommand;
use LiveUX\FileManagerBundle\Command\InstallSkillsCommand;
use LiveUX\FileManagerBundle\Command\OptimizeImagesCommand;
use LiveUX\FileManagerBundle\Command\StorageStatsCommand;
use LiveUX\FileManagerBundle\Command\ThumbnailClearCommand;
use LiveUX\FileManagerBundle\Command\ThumbnailWarmupCommand;
use LiveUX\FileManagerBundle\Command\TrashPurgeCommand;
use LiveUX\FileManagerBundle\Config\ChainConfigProvider;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\DynamicPathResolver;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Controller\ApiController;
use LiveUX\FileManagerBundle\Controller\DownloadController;
use LiveUX\FileManagerBundle\Controller\ImageEditorController;
use LiveUX\FileManagerBundle\Controller\ShareController;
use LiveUX\FileManagerBundle\Controller\ThumbnailController;
use LiveUX\FileManagerBundle\Controller\UploadController;
use LiveUX\FileManagerBundle\Controller\ZipDownloadController;
use LiveUX\FileManagerBundle\DataCollector\FileManagerDataCollector;
use LiveUX\FileManagerBundle\EventListener\AuditLogListener;
use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use LiveUX\FileManagerBundle\EventListener\CacheInvalidationListener;
use LiveUX\FileManagerBundle\EventListener\MetadataSyncListener;
use LiveUX\FileManagerBundle\EventListener\ThumbnailCleanupListener;
use LiveUX\FileManagerBundle\Form\FileManagerPickerType;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Metadata\JsonFileMetadataStorage;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerVoter;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\FilenameNormalizer;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use LiveUX\FileManagerBundle\Storage\StorageFactoryInterface;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\Driver\GdThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\Driver\ImagickThumbnailDriver;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Twig\Components\BrowserComponent;
use LiveUX\FileManagerBundle\Twig\Extension\FileManagerTwigExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // Config & Storage
    $services->set(DynamicPathResolver::class);
    $services->set(PathNormalizer::class);
    $services->set(FileSecurityGuard::class);
    $services->set(SvgSanitizer::class);
    $services->set(CsrfRequestValidator::class)
        ->args([
            '$failClosedWhenUnavailable' => true,
        ]);
    $services->set(FileManagerAuthorizer::class);
    $services->set(MessageTranslator::class);
    $services->set(FilenameNormalizer::class);

    $services->set(YamlConfigProvider::class)
        ->args([
            '$defaults' => param('liveux_file_manager.defaults'),
            '$volumes' => param('liveux_file_manager.volumes'),
            '$profiles' => param('liveux_file_manager.profiles'),
        ])
        ->tag('liveux_file_manager.config_provider', ['priority' => 0]);

    $services->set(ChainConfigProvider::class)
        ->args([
            '$providers' => tagged_iterator('liveux_file_manager.config_provider'),
        ]);

    $services->alias(ConfigProviderInterface::class, ChainConfigProvider::class);

    $services->set(FlysystemStorageFactory::class);

    $services->alias(StorageFactoryInterface::class, FlysystemStorageFactory::class);
    $services->set(VolumeRegistry::class);

    // Metadata Storage & Icon Sets
    $services->set(IconSetRegistry::class)
        ->args([
            '$bundleIconsDir' => \dirname(__DIR__) . '/resources/icons',
            '$cachePool' => service('cache.app')->nullOnInvalid(),
        ]);

    $services->set(JsonFileMetadataStorage::class)
        ->args([
            '$filePath' => param('liveux_file_manager.metadata_file'),
        ]);

    $services->set(NullMetadataStorage::class);
    $services->alias(FolderMetadataStorageInterface::class, JsonFileMetadataStorage::class);

    // Cache, Listing, Trash & ZIP
    $services->set(DirectoryCacheManager::class)
        ->args([
            '$cachePool' => service('cache.app')->nullOnInvalid(),
            '$tagAwareCache' => service('cache.app.taggable')->nullOnInvalid(),
        ]);

    $services->set(DirectoryLister::class)
        ->arg('$hasExternalVoters', true);
    $services->set(FileOperationsService::class);
    $services->set(ImageOptimizer::class);
    $services->set(StorageQuotaChecker::class);
    $services->set(DuplicateDetector::class);
    $services->set(ProfileFileScanner::class);
    $services->set(UploadManager::class);
    $services->set(RemoteUrlImporter::class);
    $services->set(TrashManager::class)
        ->args([
            '$localMetaFallbackFile' => param('liveux_file_manager.metadata_file'),
        ]);
    $services->set(ZipArchiveService::class);
    $services->set(ShareLinkService::class)
        ->args([
            '$uriSigner' => service('uri_signer')->nullOnInvalid(),
        ]);
    $services->set(NullAssetEnricher::class);
    $services->alias(AssetEnricherInterface::class, NullAssetEnricher::class);
    $services->set(AssetEnrichmentService::class)
        ->args([
            '$enricher' => service(AssetEnricherInterface::class)->nullOnInvalid(),
        ]);
    $services->set(AssetUsageGuardService::class)
        ->args([
            '$checkers' => tagged_iterator('liveux_file_manager.usage_checker'),
        ]);

    // HTTP Controllers
    $services->set(ApiController::class)->tag('controller.service_arguments');
    $services->set(UploadController::class)->tag('controller.service_arguments');
    $services->set(ImageEditorController::class)->tag('controller.service_arguments');
    $services->set(DownloadController::class)->tag('controller.service_arguments');
    $services->set(ShareController::class)->tag('controller.service_arguments');
    $services->set(ZipDownloadController::class)->tag('controller.service_arguments');
    $services->set(ThumbnailController::class)->tag('controller.service_arguments');

    // Twig Components, Extension & Form Types
    $services->set(BrowserComponent::class);
    $services->set(FileManagerTwigExtension::class)->tag('twig.extension');
    $services->set(FileManagerPickerType::class)->tag('form.type');

    // Thumbnails
    $services->set(GdThumbnailDriver::class);
    $services->set(ImagickThumbnailDriver::class);
    $services->set(ThumbnailManager::class)
        ->arg('$cacheSecret', param('kernel.secret'));

    // Security & Event Listeners
    $services->set(FileManagerVoter::class)->tag('security.voter');
    $services->set(AutoInjectDialogHostSubscriber::class)
        ->args([
            '$enabled' => param('liveux_file_manager.auto_inject_dialog_host'),
            '$defaultProfile' => param('liveux_file_manager.dialog_host_profile'),
            '$defaultZIndex' => param('liveux_file_manager.default_z_index'),
        ])
        ->tag('kernel.event_subscriber');
    $services->set(AuditLogListener::class)
        ->args([
            '$enabled' => param('liveux_file_manager.audit_log_enabled'),
            '$level' => param('liveux_file_manager.audit_log_level'),
            '$logger' => service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_subscriber');
    $services->set(CacheInvalidationListener::class)->tag('kernel.event_subscriber');
    $services->set(MetadataSyncListener::class)->tag('kernel.event_subscriber');
    $services->set(ThumbnailCleanupListener::class)->tag('kernel.event_subscriber');

    // CLI Commands & Profiler DataCollector (autoconfigure registers #[AsCommand] automatically)
    $services->set(DoctorCommand::class);
    $services->set(CacheClearCommand::class);
    $services->set(ThumbnailClearCommand::class);
    $services->set(ThumbnailWarmupCommand::class);
    $services->set(OptimizeImagesCommand::class);
    $services->set(DuplicatesCommand::class);
    $services->set(EnrichMetadataCommand::class);
    $services->set(StorageStatsCommand::class);
    $services->set(TrashPurgeCommand::class);
    $services->set(InstallSkillsCommand::class)
        ->args([
            '$bundleSkillsDir' => \dirname(__DIR__) . '/skills',
            '$projectDir' => param('kernel.project_dir'),
        ]);
    $services->set(FileManagerDataCollector::class)
        ->tag('data_collector', [
            'id' => 'liveux_file_manager',
            'template' => '@LiveUXFileManager/data_collector/file_manager.html.twig',
        ]);
};
