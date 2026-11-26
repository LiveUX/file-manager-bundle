<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle;

use Doctrine\DBAL\Connection;
use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\Bridge\Doctrine\DoctrineDbalMetadataStorage;
use LiveUX\FileManagerBundle\Bridge\SymfonyAI\SymfonyAiAssetEnricher;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\DetectExternalVotersPass;
use LiveUX\FileManagerBundle\DependencyInjection\Compiler\FlysystemServiceLocatorPass;
use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Metadata\NullMetadataStorage;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Usage\AssetUsageCheckerInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class FileManagerBundle extends AbstractBundle
{
    public const string VERSION = '0.1.0';

    protected string $extensionAlias = Configuration::ALIAS;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new FlysystemServiceLocatorPass());
        $container->addCompilerPass(new DetectExternalVotersPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $configuration = new Configuration();
        $rootNode = $configuration->getConfigTreeBuilder()->getRootNode();
        $definition->rootNode()->children()->end();

        foreach ($rootNode->getChildNodeDefinitions() as $childNode) {
            $definition->rootNode()->append($childNode);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('liveux_file_manager.defaults', $config['defaults']);
        $container->parameters()->set('liveux_file_manager.volumes', $config['volumes']);
        $container->parameters()->set('liveux_file_manager.profiles', $config['profiles']);

        $metadata = $config['defaults']['metadata'] ?? [];
        $metadataFile = (string) ($metadata['file'] ?? '%kernel.project_dir%/var/file-manager/metadata.json');
        $container->parameters()->set('liveux_file_manager.metadata_file', $metadataFile);
        $container->parameters()->set('liveux_file_manager.auto_inject_dialog_host', ($config['defaults']['auto_inject_dialog_host'] ?? true) === true);
        $container->parameters()->set('liveux_file_manager.dialog_host_profile', (string) ($config['defaults']['dialog_host_profile'] ?? 'default'));
        $container->parameters()->set('liveux_file_manager.default_z_index', (int) ($config['defaults']['z_index'] ?? 1050));

        $auditLog = $config['defaults']['audit_log'] ?? [];
        $container->parameters()->set('liveux_file_manager.audit_log_enabled', ($auditLog['enabled'] ?? false) === true);
        $container->parameters()->set('liveux_file_manager.audit_log_level', (string) ($auditLog['level'] ?? 'info'));

        $container->import('../config/services.php');
        $builder->registerForAutoconfiguration(AssetUsageCheckerInterface::class)
            ->addTag('liveux_file_manager.usage_checker');

        $cachePool = is_string($config['defaults']['cache']['pool'] ?? null)
            ? trim($config['defaults']['cache']['pool'])
            : 'cache.app';
        if ($cachePool !== '' && $cachePool !== 'cache.app') {
            $container->services()
                ->get(DirectoryCacheManager::class)
                ->args([
                    '$cachePool' => service($cachePool)->nullOnInvalid(),
                    '$tagAwareCache' => service($cachePool . '.taggable')->nullOnInvalid(),
                ]);
            $container->services()
                ->get(IconSetRegistry::class)
                ->arg('$cachePool', service($cachePool)->nullOnInvalid());
        }

        $container->services()
            ->get(MessageTranslator::class)
            ->arg('$allowedCatalogLocales', $this->collectAllowedCatalogLocales($config, $builder));

        $metadataDriver = (string) ($metadata['driver'] ?? 'json_file');
        if (($metadata['enabled'] ?? true) !== true || $metadataDriver === 'none') {
            $container->services()->alias(FolderMetadataStorageInterface::class, NullMetadataStorage::class);
        } elseif ($metadataDriver === 'doctrine') {
            $this->registerDoctrineMetadataStorage($metadata, $container);
        }

        $this->registerOptionalSymfonyAiBridge($config, $container);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function registerDoctrineMetadataStorage(array $metadata, ContainerConfigurator $container): void
    {
        $explicitConnection = is_string($metadata['connection'] ?? null) && trim($metadata['connection']) !== ''
            ? trim($metadata['connection'])
            : null;
        $entityManagerName = is_string($metadata['entity_manager'] ?? null) && trim($metadata['entity_manager']) !== ''
            ? trim($metadata['entity_manager'])
            : 'default';
        $connectionName = $explicitConnection ?? $entityManagerName;
        $tableName = is_string($metadata['table'] ?? null) && trim($metadata['table']) !== ''
            ? trim($metadata['table'])
            : 'liveux_fm_metadata';
        $autoCreateTable = ($metadata['auto_create_table'] ?? true) === true;

        $container->services()
            ->set(DoctrineDbalMetadataStorage::class)
                ->args([
                    '$doctrineOrConnection' => service('doctrine')->nullOnInvalid(),
                    '$connectionName' => $connectionName,
                    '$tableName' => $tableName,
                    '$autoCreateTable' => $autoCreateTable,
                    '$fallbackConnection' => service('doctrine.dbal.' . $connectionName . '_connection')->nullOnInvalid(),
                    '$defaultConnection' => $explicitConnection === null ? service(Connection::class)->nullOnInvalid() : null,
                    '$resolveViaEntityManager' => $explicitConnection === null,
                ])
                ->tag('kernel.reset', ['method' => 'reset'])
            ->alias(FolderMetadataStorageInterface::class, DoctrineDbalMetadataStorage::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerOptionalSymfonyAiBridge(array $config, ContainerConfigurator $container): void
    {
        $platformInterface = 'Symfony\\AI\\Platform\\PlatformInterface';
        if (!interface_exists($platformInterface)) {
            return;
        }

        $aiDefaults = is_array($config['defaults']['ai'] ?? null) ? $config['defaults']['ai'] : [];
        $platformServiceId = is_string($aiDefaults['platform_service'] ?? null) && trim($aiDefaults['platform_service']) !== ''
            ? trim($aiDefaults['platform_service'])
            : $platformInterface;
        $defaultModel = is_string($aiDefaults['default_model'] ?? null) && trim($aiDefaults['default_model']) !== ''
            ? trim($aiDefaults['default_model'])
            : 'gpt-4o-mini';

        $container->services()
            ->set(SymfonyAiAssetEnricher::class)
                ->args([
                    '$platform' => service($platformServiceId)->nullOnInvalid(),
                    '$defaultModel' => $defaultModel,
                ])
            ->alias(AssetEnricherInterface::class, SymfonyAiAssetEnricher::class);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->prependAssetMapper($builder);
        $this->prependTwig($builder);
    }

    private function prependAssetMapper(ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('framework') || !interface_exists(AssetMapperInterface::class)) {
            return;
        }

        foreach ($builder->getExtensionConfig('framework') as $frameworkConfig) {
            if (isset($frameworkConfig['asset_mapper']['enabled']) && $frameworkConfig['asset_mapper']['enabled'] === false) {
                return;
            }
        }

        $distPath = $this->getPath() . '/assets/dist';
        $assetsPath = is_dir($distPath) ? $distPath : ($this->getPath() . '/assets');
        if (!is_dir($assetsPath)) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $assetsPath => '@liveux/file-manager-bundle',
                ],
            ],
        ]);
    }

    private function prependTwig(ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('twig')) {
            return;
        }

        $templatesPath = $this->getPath() . '/templates';
        if (!is_dir($templatesPath)) {
            return;
        }

        $builder->prependExtensionConfig('twig', [
            'paths' => [
                $templatesPath => 'LiveUXFileManager',
            ],
            'form_themes' => [
                '@LiveUXFileManager/form/picker.html.twig',
            ],
        ]);
    }

    /**
     * Collects known catalog locales so unauthenticated requests cannot flood Symfony's
     * translation cache or OPcache with arbitrary fabricated locale strings.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function collectAllowedCatalogLocales(array $config, ContainerBuilder $builder): array
    {
        $locales = ['en', 'tr', 'fr', 'de', 'es', 'ar', 'it', 'pt', 'nl', 'pl', 'ja', 'zh_CN'];

        $candidateDirs = [$this->getPath() . '/translations'];
        if ($builder->hasParameter('translator.default_path')) {
            $rawTransPath = $builder->getParameter('translator.default_path');
            if (is_string($rawTransPath) && $rawTransPath !== '') {
                $resolvedTransPath = $builder->getParameterBag()->resolveValue($rawTransPath);
                if (is_string($resolvedTransPath) && $resolvedTransPath !== '') {
                    $candidateDirs[] = $resolvedTransPath;
                }
            }
        }
        if ($builder->hasParameter('kernel.project_dir')) {
            $projectDir = $builder->getParameter('kernel.project_dir');
            if (is_string($projectDir) && $projectDir !== '') {
                $candidateDirs[] = rtrim($projectDir, '/\\') . '/translations';
            }
        }

        foreach (array_unique($candidateDirs) as $transDir) {
            if (!is_dir($transDir)) {
                continue;
            }
            foreach (array_merge(
                glob($transDir . '/LiveUXFileManagerBundle.*.*') ?: [],
                glob($transDir . '/LiveUXFileManagerBundle+intl-icu.*.*') ?: [],
            ) as $file) {
                $parts = explode('.', basename($file));
                if (count($parts) >= 3 && MessageTranslator::isValidLocaleString($parts[1])) {
                    $locales[] = $parts[1];
                }
            }
        }

        $profiles = is_array($config['profiles'] ?? null) ? $config['profiles'] : [];
        $candidateLocales = array_merge(
            [$config['defaults']['locale'] ?? null],
            array_column($profiles, 'locale'),
        );
        foreach ($candidateLocales as $cfgLocale) {
            if (is_string($cfgLocale) && MessageTranslator::isValidLocaleString($cfgLocale)) {
                $locales[] = trim($cfgLocale);
            }
        }

        if ($builder->hasParameter('kernel.default_locale')) {
            $defaultLocale = $builder->getParameter('kernel.default_locale');
            if (is_string($defaultLocale) && MessageTranslator::isValidLocaleString($defaultLocale)) {
                $locales[] = trim($defaultLocale);
            }
        }

        if ($builder->hasParameter('kernel.enabled_locales')) {
            $enabledLocales = $builder->getParameter('kernel.enabled_locales');
            if (is_array($enabledLocales)) {
                foreach ($enabledLocales as $loc) {
                    if (is_string($loc) && MessageTranslator::isValidLocaleString($loc)) {
                        $locales[] = trim($loc);
                    }
                }
            }
        }

        return array_values(array_unique($locales));
    }
}
