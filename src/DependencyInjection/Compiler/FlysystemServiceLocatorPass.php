<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\DependencyInjection\Compiler;

use LiveUX\FileManagerBundle\Storage\FlysystemStorageFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds a dedicated ServiceLocator for FlysystemStorageFactory containing all configured
 * `FlysystemService` service IDs (plus any services tagged `flysystem.storage` or
 * `liveux_file_manager.storage`) so private Flysystem storage services work without
 * injecting the deprecated `service_container` anti-pattern.
 */
final class FlysystemServiceLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FlysystemStorageFactory::class)) {
            return;
        }

        $serviceMap = [];

        foreach ($this->collectConfiguredServiceIds($container) as $serviceId) {
            $serviceMap[$serviceId] = new Reference($serviceId, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        }

        foreach (['flysystem.storage', 'liveux_file_manager.storage'] as $tag) {
            foreach (array_keys($container->findTaggedServiceIds($tag)) as $serviceId) {
                if (!isset($serviceMap[$serviceId])) {
                    $serviceMap[$serviceId] = new Reference($serviceId);
                }
            }
        }

        $locatorRef = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->getDefinition(FlysystemStorageFactory::class)
            ->setArgument('$serviceLocator', $locatorRef);
    }

    /**
     * @return list<string>
     */
    private function collectConfiguredServiceIds(ContainerBuilder $container): array
    {
        $ids = [];

        foreach (['liveux_file_manager.volumes', 'liveux_file_manager.profiles'] as $paramName) {
            if (!$container->hasParameter($paramName)) {
                continue;
            }
            $entries = $container->getParameter($paramName);
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $driverOptions = $entry['driver_options'] ?? null;
                if (!is_array($driverOptions)) {
                    continue;
                }
                $serviceId = $driverOptions['service'] ?? null;
                if (is_string($serviceId) && trim($serviceId) !== '') {
                    $resolved = $container->getParameterBag()->resolveValue(trim($serviceId));
                    if (is_string($resolved) && trim($resolved) !== '') {
                        $resolvedId = trim($resolved);
                        $ids[$resolvedId] = $resolvedId;
                    }
                }
            }
        }

        return array_values($ids);
    }
}
