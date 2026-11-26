<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\DependencyInjection\Compiler;

use LiveUX\FileManagerBundle\Security\FileManagerVoter;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/**
 * Detects whether any custom security voters or custom authorization checkers outside
 * Symfony core and FileManagerVoter are registered, allowing DirectoryLister to evaluate
 * profile-only permissions O(1) instead of per-folder only when safe.
 */
final class DetectExternalVotersPass implements CompilerPassInterface
{
    private const string ARG_NAME = 'hasExternalVoters';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DirectoryLister::class)) {
            return;
        }

        $hasExternalVoters = $this->hasCustomAuthorizationChecker($container);

        if (!$hasExternalVoters) {
            foreach (array_keys($container->findTaggedServiceIds('security.voter')) as $serviceId) {
                $definition = $container->getDefinition($serviceId);
                $visited = [$serviceId => true];
                if ($this->isCustomVoterOrDecorator($container, $definition, null, $serviceId, $visited)) {
                    $hasExternalVoters = true;
                    break;
                }
            }
        }

        $this->applyHasExternalVotersArgument(
            $container->getDefinition(DirectoryLister::class),
            $hasExternalVoters,
        );
    }

    /**
     * Writes the boolean argument using a PhpDumper-safe key because this pass runs at
     * TYPE_BEFORE_REMOVING (after ResolveNamedArgumentsPass has already executed).
     * Never writes a leading '$' key, which PhpDumper would emit as invalid PHP `$arg: true`.
     */
    private function applyHasExternalVotersArgument(Definition $definition, bool $hasExternalVoters): void
    {
        $arguments = $definition->getArguments();
        $dollarKey = '$' . self::ARG_NAME;
        if (array_key_exists($dollarKey, $arguments)) {
            unset($arguments[$dollarKey]);
            $definition->setArguments($arguments);
        }

        $paramIndex = $this->resolveHasExternalVotersParameterIndex();
        if (
            array_key_exists($paramIndex, $arguments)
            || $this->hasAllPrecedingPositionalArguments($arguments, $paramIndex)
        ) {
            unset($arguments[self::ARG_NAME]);
            $arguments[$paramIndex] = $hasExternalVoters;
            $definition->setArguments($arguments);

            return;
        }

        $definition->setArgument(self::ARG_NAME, $hasExternalVoters);
    }

    private function resolveHasExternalVotersParameterIndex(): int
    {
        $ctor = new ReflectionMethod(DirectoryLister::class, '__construct');
        foreach ($ctor->getParameters() as $index => $parameter) {
            if ($parameter->getName() === self::ARG_NAME) {
                return $index;
            }
        }

        return 6;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function hasAllPrecedingPositionalArguments(array $arguments, int $paramIndex): bool
    {
        for ($i = 0; $i < $paramIndex; ++$i) {
            if (!array_key_exists($i, $arguments)) {
                return false;
            }
        }

        return true;
    }

    private function hasCustomAuthorizationChecker(ContainerBuilder $container): bool
    {
        $targets = [
            'security.authorization_checker' => true,
            AuthorizationCheckerInterface::class => true,
            'security.access.decision_manager' => true,
            AccessDecisionManagerInterface::class => true,
        ];

        $wiredAuthChecker = $this->resolveWiredAuthCheckerArgument($container->getDefinition(DirectoryLister::class));
        if ($wiredAuthChecker instanceof Reference) {
            $targets[(string) $wiredAuthChecker] = true;
        } elseif ($wiredAuthChecker instanceof Definition) {
            $visited = [];
            if (
                $this->isCustomVoterOrDecorator($container, $wiredAuthChecker, 'Symfony\\Component\\Security\\', '', $visited)
                || $this->hasCustomDecisionManagerOnAuthChecker($container, $wiredAuthChecker)
            ) {
                return true;
            }
        } elseif ($wiredAuthChecker !== null) {
            return true;
        }

        $this->expandTargetAliases($container, $targets);

        foreach (array_keys($targets) as $serviceId) {
            if (!$container->hasAlias($serviceId) && !$container->hasDefinition($serviceId)) {
                continue;
            }
            try {
                $definition = $container->findDefinition($serviceId);
            } catch (Throwable) {
                return true;
            }
            $visited = [$serviceId => true];
            if ($this->isCustomVoterOrDecorator($container, $definition, 'Symfony\\Component\\Security\\', $serviceId, $visited)) {
                return true;
            }
            if ($this->hasCustomDecisionManagerOnAuthChecker($container, $definition)) {
                return true;
            }
            if (
                $this->isDecisionManagerTarget($serviceId, $container, $definition)
                && $this->hasUntaggedCustomVotersInDecisionManager($container, $definition)
            ) {
                return true;
            }
        }

        foreach ($container->getDefinitions() as $defId => $def) {
            if ($this->decoratesAnyTarget($def, $targets)) {
                $visited = [$defId => true];
                if ($this->isCustomVoterOrDecorator($container, $def, 'Symfony\\Component\\Security\\', $defId, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Expands target service IDs through alias chains so decorators attached to
     * an aliased target (e.g. security.authorization_checker -> app.real_checker)
     * are detected reliably.
     *
     * @param array<string, bool> $targets
     */
    private function expandTargetAliases(ContainerBuilder $container, array &$targets): void
    {
        $queue = array_keys($targets);
        while ($queue !== []) {
            $current = array_shift($queue);
            if (!$container->hasAlias($current)) {
                continue;
            }
            $aliasTarget = (string) $container->getAlias($current);
            if ($aliasTarget !== '' && !isset($targets[$aliasTarget])) {
                $targets[$aliasTarget] = true;
                $queue[] = $aliasTarget;
            }
        }
    }

    private function resolveWiredAuthCheckerArgument(Definition $listerDefinition): mixed
    {
        $arguments = $listerDefinition->getArguments();
        $paramIndex = $this->resolveParameterIndex('authChecker', 5);
        foreach (['$authChecker', 'authChecker', $paramIndex] as $key) {
            if (array_key_exists($key, $arguments)) {
                return $arguments[$key];
            }
        }

        return null;
    }

    private function resolveParameterIndex(string $paramName, int $fallback): int
    {
        $ctor = new ReflectionMethod(DirectoryLister::class, '__construct');
        foreach ($ctor->getParameters() as $index => $parameter) {
            if ($parameter->getName() === $paramName) {
                return $index;
            }
        }

        return $fallback;
    }

    private function isDecisionManagerTarget(string $serviceId, ContainerBuilder $container, Definition $definition): bool
    {
        if ($serviceId === 'security.access.decision_manager' || $serviceId === AccessDecisionManagerInterface::class) {
            return true;
        }

        $resolvedClass = $this->resolveDefinitionClass($container, $definition, $serviceId);
        if ($resolvedClass === null) {
            return false;
        }

        return str_contains($resolvedClass, 'AccessDecisionManager');
    }

    /**
     * Inspects Symfony AuthorizationChecker's `$accessDecisionManager` argument (arg 1) so custom
     * or untagged AccessDecisionManager services wired under non-default IDs or inline Definitions
     * cannot bypass external voter detection.
     */
    private function hasCustomDecisionManagerOnAuthChecker(ContainerBuilder $container, Definition $checkerDef): bool
    {
        $resolvedClass = $this->resolveDefinitionClass($container, $checkerDef, '');
        if ($resolvedClass === null || !str_contains($resolvedClass, 'AuthorizationChecker')) {
            return false;
        }

        $args = $checkerDef->getArguments();
        $dmArg = $args[1] ?? $args['$accessDecisionManager'] ?? $args['accessDecisionManager'] ?? null;
        if ($dmArg === null) {
            return false;
        }

        if ($dmArg instanceof Reference) {
            $dmId = (string) $dmArg;
            if (!$container->hasDefinition($dmId) && !$container->hasAlias($dmId)) {
                return true;
            }
            try {
                $dmDef = $container->findDefinition($dmId);
            } catch (Throwable) {
                return true;
            }
            $visited = [$dmId => true];
            if ($this->isCustomVoterOrDecorator($container, $dmDef, 'Symfony\\Component\\Security\\', $dmId, $visited)) {
                return true;
            }
            $visitedManagers = [$dmId => true];

            return $this->hasUntaggedCustomVotersInDecisionManager($container, $dmDef, $visitedManagers);
        }

        if ($dmArg instanceof Definition) {
            $visited = [];
            if ($this->isCustomVoterOrDecorator($container, $dmArg, 'Symfony\\Component\\Security\\', '', $visited)) {
                return true;
            }
            $visitedManagers = [];

            return $this->hasUntaggedCustomVotersInDecisionManager($container, $dmArg, $visitedManagers);
        }

        return true;
    }

    /**
     * Recursively inspects an AccessDecisionManager (and any inner decorated instances such as
     * Symfony debug TraceableAccessDecisionManager) for untagged custom voters or inline definitions.
     *
     * @param array<string, bool> $visitedManagers
     */
    private function hasUntaggedCustomVotersInDecisionManager(
        ContainerBuilder $container,
        Definition $decisionManagerDef,
        array &$visitedManagers = [],
    ): bool {
        if ($decisionManagerDef->getFactory() !== null || $decisionManagerDef->isSynthetic() || $decisionManagerDef->getMethodCalls() !== []) {
            return true;
        }

        $args = $decisionManagerDef->getArguments();
        $votersArg = $args[0] ?? $args['$voters'] ?? $args['voters'] ?? null;
        $values = [];
        if ($votersArg instanceof TaggedIteratorArgument) {
            if ($votersArg->getTag() !== 'security.voter') {
                return true;
            }
        } elseif ($votersArg instanceof IteratorArgument) {
            $values = $votersArg->getValues();
        } elseif (is_array($votersArg)) {
            $values = $votersArg;
        } elseif ($votersArg instanceof Reference) {
            $innerRefId = (string) $votersArg;
            if (!isset($visitedManagers[$innerRefId])) {
                $visitedManagers[$innerRefId] = true;
                if ($this->inspectInnerDecisionManagerById($container, $innerRefId, $visitedManagers)) {
                    return true;
                }
            }
        } elseif ($votersArg !== null) {
            // Fail closed on inline voter iterator Definitions (e.g. RewindableGenerator) or unknown argument types.
            return true;
        }

        foreach ($values as $entry) {
            if ($this->isCustomVoterEntry($container, $entry)) {
                return true;
            }
        }

        foreach ($this->collectInnerServiceIds($decisionManagerDef) as $innerId) {
            if (isset($visitedManagers[$innerId])) {
                continue;
            }
            $visitedManagers[$innerId] = true;
            if ($this->inspectInnerDecisionManagerById($container, $innerId, $visitedManagers)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $visitedManagers
     */
    private function inspectInnerDecisionManagerById(
        ContainerBuilder $container,
        string $serviceId,
        array &$visitedManagers,
    ): bool {
        if (!$container->hasDefinition($serviceId) && !$container->hasAlias($serviceId)) {
            return false;
        }
        try {
            $innerDef = $container->findDefinition($serviceId);
        } catch (Throwable) {
            return true;
        }

        $visited = [$serviceId => true];
        if ($this->isCustomVoterOrDecorator($container, $innerDef, 'Symfony\\Component\\Security\\', $serviceId, $visited)) {
            return true;
        }

        return $this->hasUntaggedCustomVotersInDecisionManager($container, $innerDef, $visitedManagers);
    }

    private function isCustomVoterEntry(ContainerBuilder $container, mixed $entry): bool
    {
        if ($entry instanceof Reference) {
            $voterId = (string) $entry;
            if (!$container->hasDefinition($voterId) && !$container->hasAlias($voterId)) {
                return false;
            }
            try {
                $voterDef = $container->findDefinition($voterId);
            } catch (Throwable) {
                return true;
            }
            $visited = [$voterId => true];

            return $this->isCustomVoterOrDecorator($container, $voterDef, null, $voterId, $visited);
        }

        if ($entry instanceof Definition) {
            $visited = [];

            return $this->isCustomVoterOrDecorator($container, $entry, null, '', $visited);
        }

        // Fail closed on unrecognized voter entry types.
        return true;
    }

    /**
     * @param array<string, bool> $targets
     */
    private function decoratesAnyTarget(Definition $definition, array $targets): bool
    {
        $decorated = $definition->getDecoratedService();
        if (is_array($decorated) && is_string($decorated[0] ?? null) && isset($targets[$decorated[0]])) {
            return true;
        }

        foreach ($definition->getTag('container.decorator') as $tagAttrs) {
            if (is_array($tagAttrs) && is_string($tagAttrs['id'] ?? null) && isset($targets[$tagAttrs['id']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $visited
     */
    private function isCustomVoterOrDecorator(
        ContainerBuilder $container,
        Definition $definition,
        ?string $expectedCorePrefix,
        string $serviceId,
        array &$visited,
    ): bool {
        if ($definition->getFactory() !== null || $definition->isSynthetic() || $definition->getMethodCalls() !== []) {
            return true;
        }

        $resolvedClass = $this->resolveDefinitionClass($container, $definition, $serviceId);
        if ($resolvedClass === null) {
            return true;
        }

        $isCore = $expectedCorePrefix !== null
            ? (str_starts_with($resolvedClass, $expectedCorePrefix) || str_starts_with($resolvedClass, 'Symfony\\Bundle\\SecurityBundle\\'))
            : $this->isBuiltinVoterClass($resolvedClass);

        if (!$isCore) {
            return true;
        }

        if (
            $expectedCorePrefix === null
            && $resolvedClass === 'Symfony\\Component\\Security\\Core\\Authorization\\Voter\\TraceableVoter'
            && $this->isWrappedTraceableVoterCustom($container, $definition, $visited)
        ) {
            return true;
        }

        foreach ($this->collectInnerServiceIds($definition) as $innerId) {
            if (isset($visited[$innerId])) {
                continue;
            }
            $visited[$innerId] = true;
            if (!$container->hasDefinition($innerId) && !$container->hasAlias($innerId)) {
                continue;
            }
            try {
                $innerDefinition = $container->findDefinition($innerId);
            } catch (Throwable) {
                return true;
            }
            if ($this->isCustomVoterOrDecorator($container, $innerDefinition, $expectedCorePrefix, $innerId, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unwraps Symfony's TraceableVoter to inspect the underlying voter Reference or inline Definition.
     *
     * @param array<string, bool> $visited
     */
    private function isWrappedTraceableVoterCustom(ContainerBuilder $container, Definition $definition, array &$visited): bool
    {
        $args = $definition->getArguments();
        $wrapped = $args[0] ?? $args['$voter'] ?? $args['voter'] ?? null;
        if ($wrapped instanceof Reference) {
            $wrappedId = (string) $wrapped;
            if (isset($visited[$wrappedId])) {
                return false;
            }
            $visited[$wrappedId] = true;
            if (!$container->hasDefinition($wrappedId) && !$container->hasAlias($wrappedId)) {
                return false;
            }
            try {
                $wrappedDef = $container->findDefinition($wrappedId);
            } catch (Throwable) {
                return true;
            }

            return $this->isCustomVoterOrDecorator($container, $wrappedDef, null, $wrappedId, $visited);
        }

        if ($wrapped instanceof Definition) {
            return $this->isCustomVoterOrDecorator($container, $wrapped, null, '', $visited);
        }

        return true;
    }

    private function resolveDefinitionClass(ContainerBuilder $container, Definition $definition, string $serviceId): ?string
    {
        $rawClass = $definition->getClass();
        $resolvedClass = is_string($rawClass) ? $container->getParameterBag()->resolveValue($rawClass) : null;
        if ((!is_string($resolvedClass) || $resolvedClass === '') && class_exists($serviceId)) {
            $resolvedClass = $serviceId;
        }

        if (!is_string($resolvedClass) || $resolvedClass === '') {
            return null;
        }

        return $resolvedClass;
    }

    /**
     * Collects inner service IDs both pre-DecoratorServicePass (getDecoratedService)
     * and post-DecoratorServicePass (innerServiceId / container.decorator tag).
     *
     * @return list<string>
     */
    private function collectInnerServiceIds(Definition $definition): array
    {
        $innerIds = [];

        $decorated = $definition->getDecoratedService();
        if (is_array($decorated) && is_string($decorated[0] ?? null) && $decorated[0] !== '') {
            $innerIds[$decorated[0]] = true;
        }

        if (is_string($definition->innerServiceId) && $definition->innerServiceId !== '') {
            $innerIds[$definition->innerServiceId] = true;
        }

        foreach ($definition->getTag('container.decorator') as $tagAttrs) {
            if (is_array($tagAttrs) && is_string($tagAttrs['inner'] ?? null) && $tagAttrs['inner'] !== '') {
                $innerIds[$tagAttrs['inner']] = true;
            }
        }

        return array_keys($innerIds);
    }

    private function isBuiltinVoterClass(string $className): bool
    {
        return $className === FileManagerVoter::class
            || str_starts_with($className, 'Symfony\\Component\\Security\\')
            || str_starts_with($className, 'Symfony\\Bundle\\SecurityBundle\\');
    }
}
