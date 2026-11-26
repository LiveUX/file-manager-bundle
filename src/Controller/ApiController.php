<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Controller;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Model\FileItem;
use LiveUX\FileManagerBundle\Model\FolderItem;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\FileOperationsService;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\RemoteUrlImporter;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\UploadManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Theme\FileManagerThemes;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;
use Twig\Environment;

final readonly class ApiController
{
    private const int MAX_BULK_DELETE_ITEMS = 200;
    private const int MAX_TEXT_PREVIEW_BYTES = 65536;
    private const int MAX_JSON_BODY_BYTES = 1_048_576;
    private const int MIN_HYBRID_CLIENT_LIMIT = 50;
    private const int MAX_HYBRID_CLIENT_LIMIT = 5000;
    private const array ALLOWED_DIALOG_MODES = ['modal', 'offcanvas', 'inline'];
    private const array ALLOWED_TYPE_FILTERS = ['all', 'image', 'video', 'doc', 'folder'];
    private const array SAFE_TEXT_PREVIEW_EXTENSIONS = [
        'txt', 'md', 'markdown', 'json', 'yaml', 'yml',
        'csv', 'css', 'scss', 'ts', 'sql', 'log', 'toml',
    ];

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private DirectoryLister $directoryLister,
        private FileOperationsService $operations,
        private RemoteUrlImporter $remoteUrlImporter,
        private TrashManager $trashManager,
        private DirectoryCacheManager $cacheManager,
        private ThumbnailManager $thumbnailManager,
        private PathNormalizer $pathNormalizer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private CsrfRequestValidator $csrfValidator,
        ?AuthorizationCheckerInterface $authChecker = null,
        private ?Security $security = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private ?IconSetRegistry $iconSetRegistry = null,
        private MessageTranslator $messageTranslator = new MessageTranslator(),
        private ?StorageQuotaChecker $quotaChecker = null,
        private ?ZipArchiveService $zipArchiveService = null,
        private ?ShareLinkService $shareLinkService = null,
        private ?AssetEnrichmentService $aiEnrichmentService = null,
        private ?AssetUsageGuardService $usageGuardService = null,
        private ?UploadManager $uploadManager = null,
        private ?ProfileFileScanner $profileFileScanner = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function dialog(Request $request): Response
    {
        $profile = null;
        try {
            $q = $request->query->all();
            $profileName = $this->scalarString($q, 'profile', 'default');
            $rawVol = $this->scalarString($q, 'volume', '');
            $volStr = $rawVol !== '' ? $rawVol : null;
            $rawPath = $this->scalarString($q, 'path', '');
            $multiple = $this->scalarBool($q, 'multiple', false);
            $rawMode = $this->scalarString($q, 'mode', 'modal');
            $mode = in_array($rawMode, self::ALLOWED_DIALOG_MODES, true) ? $rawMode : 'modal';
            $rawIconSetStr = $this->scalarString($q, 'iconSet', '');
            $rawIconSet = $rawIconSetStr !== '' ? $rawIconSetStr : null;
            $rawLocaleStr = $this->scalarString($q, 'locale', '');
            $rawLocale = $rawLocaleStr !== '' ? $rawLocaleStr : null;
            $rawThemeStr = $this->scalarString($q, 'theme', '');
            $rawTheme = $rawThemeStr !== '' ? $rawThemeStr : null;
            $rawType = strtolower(trim($this->scalarString($q, 'type', 'all')));
            $safeType = in_array($rawType, self::ALLOWED_TYPE_FILTERS, true) ? $rawType : 'all';

            $profile = $this->configProvider->getProfile($profileName);
            $resolvedVol = $this->volumeRegistry->getVolumeForProfile($profile, $volStr);
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        } catch (FileManagerException $e) {
            $locale = $this->messageTranslator->resolveLocale($request, $profile);

            return $this->plainTextErrorResponse(
                $this->messageTranslator->translateException($e, $locale),
                Response::HTTP_BAD_REQUEST,
            );
        }

        $locale = $this->messageTranslator->resolveLocale($request, $profile);
        if (!$this->isGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $resolvedVol->name)) {
            return $this->plainTextErrorResponse(
                $this->messageTranslator->trans('errors.access_denied', [], $locale, 'Access denied.'),
                Response::HTTP_FORBIDDEN,
            );
        }

        $safeIconSet = $this->sanitizeDynamicIconSet($rawIconSet);
        $safeLocale = is_string($rawLocale) && MessageTranslator::isValidLocaleString($rawLocale)
            ? trim($rawLocale)
            : null;
        $safeTheme = FileManagerThemes::sanitizeDynamic($rawTheme);

        $html = $this->twig->render('@LiveUXFileManager/components/DialogFrame.html.twig', [
            'profile' => $profileName,
            'volume' => $volStr,
            'path' => $cleanPath,
            'multiple' => $multiple,
            'mode' => $mode,
            'iconSet' => $safeIconSet,
            'locale' => $safeLocale,
            'theme' => $safeTheme,
            'typeFilter' => $safeType,
        ]);

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('X-LiveUX-FM-Dialog', '1');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public function list(Request $request): JsonResponse
    {
        $profile = null;
        try {
            $q = $request->query->all();
            $profileName = $this->scalarString($q, 'profile', 'default');
            $volumeName = $this->scalarString($q, 'volume', '');
            $path = $this->scalarString($q, 'path', '');
            $all = $this->scalarBool($q, 'all', false);
            $compact = $this->scalarBool($q, 'compact', false);
            $recursive = $this->scalarBool($q, 'recursive', false);
            $forceRefresh = $this->scalarBool($q, 'refresh', false);
            $allowFallback = $this->scalarBool($q, 'fallback', false);
            $page = $all ? 1 : max(1, (int) $this->scalarString($q, 'page', '1'));
            $rawPerPage = $q['perPage'] ?? null;
            if (is_array($rawPerPage)) {
                throw new FileManagerException('Parameter "perPage" must be a scalar integer.', 'errors.invalid_json');
            }
            $requestedPerPage = (is_int($rawPerPage) || is_string($rawPerPage)) && is_numeric($rawPerPage)
                ? max(1, min(500, (int) $rawPerPage))
                : null;
            $hybridLimit = $this->resolveHybridClientLimit($q, $all, $recursive);
            $perPage = $all
                ? ($hybridLimit ?? 10000)
                : $requestedPerPage;
            $typeFilter = ($all && !$recursive) ? 'all' : $this->scalarString($q, 'type', 'all');
            $search = ($all && !$recursive) ? '' : $this->scalarString($q, 'search', '');
            $sortBy = $this->scalarString($q, 'sortBy', 'name');
            $sortDir = $this->scalarString($q, 'sortDir', 'asc');

            $profile = $this->configProvider->getProfile($profileName);
            $vol = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName !== '' ? $volumeName : null);
            $cleanPath = $this->pathNormalizer->normalize($path);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $vol->name);
            $forceRefresh = $forceRefresh && $this->isGranted($profile, FileManagerPermissions::CLEAR_CACHE, $cleanPath, $vol->name);

            $fallbackApplied = false;
            $requestedCleanPath = $cleanPath;
            if ($cleanPath !== '' && ($allowFallback || $forceRefresh)) {
                $resolved = $this->directoryLister->resolveExistingPath($profile, $vol, $cleanPath, false);
                if ($resolved['path'] !== $cleanPath) {
                    $cleanPath = $resolved['path'];
                    $fallbackApplied = $resolved['fallbackApplied'];
                    $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
                    $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $vol->name);
                    if ($this->isGranted($profile, FileManagerPermissions::CLEAR_CACHE, $cleanPath, $vol->name)) {
                        $canViewRootForFallback = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $vol->name);
                        $this->directoryLister->invalidateFallbackCaches($profile, $vol, $requestedCleanPath, $cleanPath, $canViewRootForFallback);
                    }
                }
            }

            $listing = ($recursive && trim($search) !== '')
                ? $this->directoryLister->searchRecursive($profile, $vol->name, $cleanPath, $search, $typeFilter, 300, $sortBy, $sortDir)
                : $this->directoryLister->list(
                    profile: $profile,
                    volumeName: $vol->name,
                    path: $cleanPath,
                    page: $page,
                    perPage: $perPage,
                    typeFilter: $typeFilter,
                    search: $search,
                    sortBy: $sortBy,
                    sortDirection: $sortDir,
                    forceRefresh: $forceRefresh,
                );

            $serverPaginated = !$all && !$recursive;
            $responseItems = $listing->items;
            $responsePage = $listing->page;
            $responsePerPage = $listing->perPage;
            $responseTotalPages = $listing->totalPages;
            if ($hybridLimit !== null && $listing->total > $hybridLimit) {
                $serverPaginated = true;
                $pageSize = max(1, $requestedPerPage ?? (int) ($profile->ui['per_page'] ?? 24));
                $responseItems = array_slice($listing->items, 0, $pageSize);
                $responsePage = 1;
                $responsePerPage = $pageSize;
                $responseTotalPages = max(1, (int) ceil($listing->total / $pageSize));
            }

            $urlTemplates = $this->resolveFastItemUrlBases();
            $serialized = [];
            foreach ($responseItems as $item) {
                $serialized[] = $this->serializeItem(
                    $item,
                    $profile->name,
                    $vol->name,
                    $urlTemplates['download'],
                    $urlTemplates['thumb'],
                    $compact,
                );
            }

            $canViewRoot = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $vol->name);
            $quota = ($canViewRoot && ($profile->ui['show_quota'] ?? true) !== false)
                ? $this->quotaChecker?->getQuotaSummary($profile, $vol->name)
                : null;

            $payload = [
                'ok' => true,
                'volume' => $vol->name,
                'volumeLabel' => $vol->label,
                'path' => $cleanPath,
                'fallbackApplied' => $fallbackApplied,
                'recursive' => $recursive && trim($search) !== '',
                'serverPaginated' => $serverPaginated,
                'breadcrumbs' => $this->buildBreadcrumbs($cleanPath),
                'items' => $serialized,
                'total' => $listing->total,
                'page' => $responsePage,
                'perPage' => $responsePerPage,
                'totalPages' => $responseTotalPages,
                'fromCache' => $listing->fromCache,
                'durationMs' => $listing->durationMs,
                'quota' => $quota,
            ];
            if ($compact) {
                $payload['compact'] = true;
                $payload['urlBases'] = $urlTemplates;
            }

            return $this->privateJsonResponse($payload);
        } catch (AccessDeniedException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_FORBIDDEN, $request, $profile);
        } catch (FileManagerException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_BAD_REQUEST, $request, $profile);
        } catch (FilesystemException $e) {
            return $this->filesystemErrorJsonResponse($e, Response::HTTP_BAD_GATEWAY, $request, $profile);
        }
    }

    public function tree(Request $request): JsonResponse
    {
        $profile = null;
        try {
            $q = $request->query->all();
            $profileName = $this->scalarString($q, 'profile', 'default');
            $volumeName = $this->scalarString($q, 'volume', '');
            $rawPath = $this->scalarString($q, 'path', '');
            $full = $this->scalarBool($q, 'full', false);
            $forceRefresh = $this->scalarBool($q, 'refresh', false);

            $profile = $this->configProvider->getProfile($profileName);
            $vol = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName !== '' ? $volumeName : null);
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $vol->name);

            if ($full) {
                $allowTreeRefresh = $forceRefresh
                    && $this->isGranted($profile, FileManagerPermissions::VIEW, '', $vol->name)
                    && $this->isGranted($profile, FileManagerPermissions::CLEAR_CACHE, '', $vol->name);

                return $this->privateJsonResponse([
                    'ok' => true,
                    'volume' => $vol->name,
                    'folders' => $this->directoryLister->listFullTree($profile, $vol->name, 6, $allowTreeRefresh),
                ]);
            }

            $allowDirRefresh = $forceRefresh && $this->isGranted($profile, FileManagerPermissions::CLEAR_CACHE, $cleanPath, $vol->name);
            $folders = $this->directoryLister->listSubfolders($profile, $vol->name, $cleanPath, $allowDirRefresh);
            $result = [];
            foreach ($folders as $folder) {
                $result[] = [
                    'name' => $folder->name,
                    'path' => $folder->path,
                    'parent' => $cleanPath,
                    'color' => $folder->metadata->color,
                    'icon' => $folder->metadata->icon,
                    'pinned' => $folder->metadata->pinned,
                    'itemCount' => $folder->itemCount,
                ];
            }

            return $this->privateJsonResponse(['ok' => true, 'volume' => $vol->name, 'folders' => $result]);
        } catch (AccessDeniedException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_FORBIDDEN, $request, $profile);
        } catch (FileManagerException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_BAD_REQUEST, $request, $profile);
        } catch (FilesystemException $e) {
            return $this->filesystemErrorJsonResponse($e, Response::HTTP_BAD_GATEWAY, $request, $profile);
        }
    }

    public function trash(Request $request): JsonResponse
    {
        $profile = null;
        try {
            $q = $request->query->all();
            $profileName = $this->scalarString($q, 'profile', 'default');
            $volumeName = $this->scalarString($q, 'volume', '');
            $profile = $this->configProvider->getProfile($profileName);
            $vol = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName !== '' ? $volumeName : null);
            if (!$this->trashManager->isEnabled($profile)) {
                throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
            }
            $canAccessRootTrash = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $vol->name)
                && (
                    $this->isGranted($profile, FileManagerPermissions::DELETE, '', $vol->name)
                    || $this->isGranted($profile, FileManagerPermissions::TRASH_RESTORE, '', $vol->name)
                    || $this->isGranted($profile, FileManagerPermissions::TRASH_PURGE, '', $vol->name)
                );

            if (!$canAccessRootTrash && !$this->canAttemptTrashScan($profile)) {
                throw new AccessDeniedException('Access denied.', 'errors.access_denied');
            }

            $visibleEntries = $this->trashManager->listTrash($profile, $vol->name, true);

            if (!$canAccessRootTrash && $visibleEntries === []) {
                throw new AccessDeniedException('Access denied.', 'errors.access_denied');
            }

            $entries = array_map(static fn ($e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'originalPath' => $e->originalPath,
                'isFolder' => $e->isFolder,
                'size' => $e->size,
                'deletedAt' => date('Y-m-d H:i', $e->deletedAt),
                'deletedAtTs' => $e->deletedAt,
            ], $visibleEntries);

            $canViewRoot = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $vol->name);
            $quota = ($canViewRoot && ($profile->ui['show_quota'] ?? true) !== false)
                ? $this->quotaChecker?->getQuotaSummary($profile, $vol->name)
                : null;

            return $this->privateJsonResponse([
                'ok' => true,
                'entries' => $entries,
                'quota' => $quota,
            ]);
        } catch (AccessDeniedException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_FORBIDDEN, $request, $profile);
        } catch (FileManagerException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_BAD_REQUEST, $request, $profile);
        } catch (FilesystemException $e) {
            return $this->filesystemErrorJsonResponse($e, Response::HTTP_BAD_GATEWAY, $request, $profile);
        }
    }

    public function previewText(Request $request): JsonResponse
    {
        $profile = null;
        try {
            $q = $request->query->all();
            $profileName = $this->scalarString($q, 'profile', 'default');
            $volumeName = $this->scalarString($q, 'volume', '');
            $rawPath = $this->scalarString($q, 'path', '');

            $profile = $this->configProvider->getProfile($profileName);
            $vol = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName !== '' ? $volumeName : null);
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            $this->fileSecurityGuard->assertSafeFilename(PathNormalizer::basenameUtf8($cleanPath));
            $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $vol->name);

            $ext = PathNormalizer::extensionUtf8($cleanPath);
            if ($cleanPath === '' || !in_array($ext, self::SAFE_TEXT_PREVIEW_EXTENSIONS, true)) {
                throw new FileManagerException('Text preview is not supported for this file type.', 'errors.preview_unsupported');
            }

            $filesystem = $this->volumeRegistry->getFilesystem($profile, $vol->name);
            if (!$filesystem->fileExists($cleanPath)) {
                throw new FileManagerException('File not found.', 'errors.file_not_found');
            }

            $size = $filesystem->fileSize($cleanPath);
            $rawContent = $this->readBoundedText($filesystem, $cleanPath);
            $decodedProbe = \LiveUX\FileManagerBundle\Util\UnicodeHelper::decodeTextPreview($rawContent, self::MAX_TEXT_PREVIEW_BYTES + 1);
            if ($decodedProbe === null) {
                throw new FileManagerException('Binary files cannot be previewed as text.', 'errors.preview_unsupported');
            }
            $expandedPastLimit = strlen($decodedProbe) > self::MAX_TEXT_PREVIEW_BYTES;
            $decodedText = $expandedPastLimit
                ? (\LiveUX\FileManagerBundle\Util\UnicodeHelper::decodeTextPreview($rawContent, self::MAX_TEXT_PREVIEW_BYTES) ?? '')
                : $decodedProbe;

            return $this->privateJsonResponse([
                'ok' => true,
                'name' => PathNormalizer::basenameUtf8($cleanPath),
                'extension' => $ext,
                'content' => $decodedText,
                'truncated' => $size > self::MAX_TEXT_PREVIEW_BYTES || strlen($rawContent) > self::MAX_TEXT_PREVIEW_BYTES || $expandedPastLimit,
                'size' => $size,
            ]);
        } catch (AccessDeniedException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_FORBIDDEN, $request, $profile);
        } catch (FileManagerException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_BAD_REQUEST, $request, $profile);
        } catch (FilesystemException $e) {
            return $this->filesystemErrorJsonResponse($e, Response::HTTP_BAD_GATEWAY, $request, $profile);
        }
    }

    public function action(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_JSON_BODY_BYTES) {
            $locale = $this->messageTranslator->resolveLocale($request);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.invalid_json', [], $locale, 'Invalid JSON payload.'),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $locale = $this->messageTranslator->resolveLocale($request);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.invalid_json', [], $locale, 'Invalid JSON payload.'),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (
            (array_key_exists('action', $payload) && !is_string($payload['action']))
            || (array_key_exists('profile', $payload) && !is_string($payload['profile']) && $payload['profile'] !== null)
            || (array_key_exists('volume', $payload) && !is_string($payload['volume']) && $payload['volume'] !== null)
        ) {
            $locale = $this->messageTranslator->resolveLocale($request);

            return $this->privateJsonResponse([
                'ok' => false,
                'error' => $this->messageTranslator->trans('errors.invalid_json', [], $locale, 'Invalid JSON payload.'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
        $profileName = is_string($payload['profile'] ?? null) && $payload['profile'] !== '' ? $payload['profile'] : 'default';
        $volumeName = is_string($payload['volume'] ?? null) && $payload['volume'] !== '' ? $payload['volume'] : null;

        $profile = null;
        try {
            try {
                $profile = $this->configProvider->getProfile($profileName);
            } catch (FileManagerException $e) {
                // Validate CSRF even when profile does not exist to prevent unauthenticated profile enumeration.
                $this->csrfValidator->assertValid($request, null);

                throw $e;
            }
            $locale = $this->messageTranslator->resolveLocale($request, $profile, $payload);
            $this->csrfValidator->assertValid($request, $profile);
            $result = $this->dispatchAction($action, $profile, $volumeName, $payload, $locale);
            if (is_array($result)) {
                return $this->privateJsonResponse(['ok' => true, ...$result]);
            }

            return $this->privateJsonResponse(['ok' => true, 'message' => $result]);
        } catch (AccessDeniedException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_FORBIDDEN, $request, $profile, $payload);
        } catch (FileManagerException $e) {
            return $this->errorJsonResponse($e, Response::HTTP_UNPROCESSABLE_ENTITY, $request, $profile, $payload);
        } catch (FilesystemException $e) {
            return $this->filesystemErrorJsonResponse($e, Response::HTTP_UNPROCESSABLE_ENTITY, $request, $profile, $payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string|array<string, mixed>
     */
    private function dispatchAction(string $action, ProfileConfig $profile, ?string $volumeName, array $payload, string $locale): string|array
    {
        return match ($action) {
            'createFolder' => $this->handleCreateFolder($profile, $volumeName, $payload, $locale),
            'rename' => $this->handleRename($profile, $volumeName, $payload, $locale),
            'clone' => $this->handleClone($profile, $volumeName, $payload, $locale),
            'move' => $this->handleMove($profile, $volumeName, $payload, $locale),
            'bulkMove' => $this->handleBulkMove($profile, $volumeName, $payload, $locale),
            'checkUsage' => $this->handleCheckUsage($profile, $volumeName, $payload),
            'delete' => $this->handleDelete($profile, $volumeName, $payload, $locale),
            'restoreTrash' => $this->handleRestoreTrash($profile, $volumeName, $payload, $locale),
            'purgeTrash' => $this->handlePurgeTrash($profile, $volumeName, $payload, $locale),
            'emptyTrash' => $this->handleEmptyTrash($profile, $volumeName, $locale),
            'saveMetadata' => $this->handleSaveMetadata($profile, $volumeName, $payload, $locale),
            'saveFileMetadata' => $this->handleSaveFileMetadata($profile, $volumeName, $payload, $locale),
            'togglePin' => $this->handleTogglePin($profile, $volumeName, $payload, $locale),
            'extractZip' => $this->handleExtractZip($profile, $volumeName, $payload, $locale),
            'createShareLink' => $this->handleCreateShareLink($profile, $volumeName, $payload, $locale),
            'suggestFileMetadata' => $this->handleSuggestFileMetadata($profile, $volumeName, $payload, $locale),
            'quotaDetails' => $this->handleQuotaDetails($profile, $volumeName),
            'clearCache' => $this->handleClearCache($profile, $volumeName, $payload, $locale),
            'importUrl' => $this->handleImportUrl($profile, $volumeName, $payload, $locale),
            'abortUpload' => $this->handleAbortUpload($profile, $volumeName, $payload, $locale),
            default => throw new FileManagerException(sprintf('Unknown action "%s".', $action), 'errors.unknown_action', ['%action%' => $action]),
        };
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{path: string, message: string}
     */
    private function handleCreateFolder(ProfileConfig $profile, ?string $vol, array $p, string $locale): array
    {
        $ifNotExists = filter_var($p['ifNotExists'] ?? false, FILTER_VALIDATE_BOOL);
        $created = $this->operations->createFolder(
            $profile,
            $this->requireString($p, 'parentPath', ''),
            $this->requireString($p, 'name', ''),
            $vol,
            $ifNotExists,
        );

        return [
            'path' => $created,
            'message' => $this->messageTranslator->trans('messages.folder_created', ['%name%' => PathNormalizer::basenameUtf8($created)], $locale, 'Folder created: %name%'),
        ];
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function scalarString(array $bag, string $key, string $default = ''): string
    {
        $val = $bag[$key] ?? null;
        if (is_string($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }
        if (is_array($val)) {
            throw new FileManagerException(sprintf('Parameter "%s" must be a scalar string.', $key), 'errors.invalid_json');
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function scalarBool(array $bag, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $bag) || $bag[$key] === null) {
            return $default;
        }
        if (is_array($bag[$key])) {
            throw new FileManagerException(sprintf('Parameter "%s" must be a boolean.', $key), 'errors.invalid_json');
        }

        return filter_var($bag[$key], FILTER_VALIDATE_BOOL);
    }

    /** @param array<string, mixed> $p */
    private function handleRename(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $renamed = $this->operations->rename($profile, $this->requireString($p, 'path', ''), $this->requireString($p, 'newName', ''), $vol);

        return $this->messageTranslator->trans('messages.renamed', ['%name%' => PathNormalizer::basenameUtf8($renamed)], $locale, 'Renamed to: %name%');
    }

    /** @param array<string, mixed> $p */
    private function handleClone(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $cloned = $this->operations->cloneItem($profile, $this->requireString($p, 'path', ''), $this->requireString($p, 'newName', ''), $vol);

        return $this->messageTranslator->trans('messages.cloned', ['%name%' => PathNormalizer::basenameUtf8($cloned)], $locale, 'Cloned: %name%');
    }

    /** @param array<string, mixed> $p */
    private function handleMove(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $isCopy = filter_var($p['isCopy'] ?? false, FILTER_VALIDATE_BOOL);
        $targetVol = is_string($p['targetVolume'] ?? null) && $p['targetVolume'] !== '' ? $p['targetVolume'] : null;
        $this->operations->moveOrCopy($profile, $this->requireString($p, 'sourcePath', ''), $this->requireString($p, 'targetDir', ''), $isCopy, $vol, $targetVol);

        return $isCopy
            ? $this->messageTranslator->trans('messages.copied', [], $locale, 'Copied successfully.')
            : $this->messageTranslator->trans('messages.moved', [], $locale, 'Moved successfully.');
    }

    /** @param array<string, mixed> $p */
    private function handleBulkMove(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $rawPaths = is_array($p['paths'] ?? null) ? array_values(array_filter($p['paths'], 'is_string')) : [];
        if (count($rawPaths) > self::MAX_BULK_DELETE_ITEMS) {
            throw new FileManagerException(sprintf('Maximum %d items can be moved in a single request.', self::MAX_BULK_DELETE_ITEMS), 'errors.bulk_move_limit', ['%max%' => self::MAX_BULK_DELETE_ITEMS]);
        }

        $isCopy = filter_var($p['isCopy'] ?? false, FILTER_VALIDATE_BOOL);
        $targetDir = $this->requireString($p, 'targetDir', '');
        $targetVol = is_string($p['targetVolume'] ?? null) && $p['targetVolume'] !== '' ? $p['targetVolume'] : null;

        $sourceVolume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $paths = $this->pruneNestedPaths($rawPaths, $sourceVolume->isLocalFilesystem() && (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin'));
        if ($paths === []) {
            throw new FileManagerException('No valid items selected.', 'errors.zip_no_selection');
        }

        $targetVolume = $this->volumeRegistry->getVolumeForProfile($profile, $targetVol ?? $sourceVolume->name);
        $perm = $isCopy ? FileManagerPermissions::COPY : FileManagerPermissions::MOVE;
        $cleanTargetDir = $this->pathNormalizer->normalize($targetDir);
        $this->fileSecurityGuard->assertAccessiblePath($cleanTargetDir, $profile);
        $this->authorizer->assertGranted($profile, FileManagerPermissions::VIEW, $cleanTargetDir, $targetVolume->name);
        $this->authorizer->assertGranted($profile, $perm, $cleanTargetDir, $targetVolume->name);
        if ($isCopy || $targetVolume->name !== $sourceVolume->name) {
            $this->authorizer->assertGranted($profile, FileManagerPermissions::UPLOAD, $cleanTargetDir, $targetVolume->name);
        }
        foreach ($paths as $sourcePath) {
            $this->authorizer->assertGranted($profile, FileManagerPermissions::VIEW, $sourcePath, $sourceVolume->name);
            $this->authorizer->assertGranted($profile, $perm, $sourcePath, $sourceVolume->name, $cleanTargetDir);
        }

        // Pre-validate all selected paths (including recursive directory contents and cumulative copy quota) before mutating any item.
        $this->operations->preValidateBulkMoveItems($profile, $paths, $targetDir, $isCopy, $vol, $targetVol, $this->usageGuardService);

        foreach ($paths as $sourcePath) {
            $this->operations->moveOrCopy($profile, $sourcePath, $targetDir, $isCopy, $vol, $targetVol);
        }

        $count = count($paths);

        return $isCopy
            ? $this->messageTranslator->trans('messages.bulk_copied', ['%count%' => $count], $locale, '%count% item(s) copied.')
            : $this->messageTranslator->trans('messages.bulk_moved', ['%count%' => $count], $locale, '%count% item(s) moved.');
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private function handleCheckUsage(ProfileConfig $profile, ?string $vol, array $p): array
    {
        $paths = is_array($p['paths'] ?? null) ? array_values(array_filter($p['paths'], 'is_string')) : [];
        if (count($paths) > self::MAX_BULK_DELETE_ITEMS) {
            throw new FileManagerException(sprintf('Maximum %d items can be deleted in a single request.', self::MAX_BULK_DELETE_ITEMS), 'errors.bulk_delete_limit', ['%max%' => self::MAX_BULK_DELETE_ITEMS]);
        }

        $resolvedVol = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        if ($paths === []) {
            $this->assertGranted($profile, FileManagerPermissions::VIEW, '', $resolvedVol->name);
            $this->assertGranted($profile, FileManagerPermissions::DELETE, '', $resolvedVol->name);
        }
        $normalizedPaths = [];
        foreach ($paths as $rawPath) {
            $cleanPath = $this->pathNormalizer->normalize($rawPath);
            $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
            $this->fileSecurityGuard->assertNotTrashAncestor($cleanPath, $profile);
            $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $resolvedVol->name);
            $this->assertGranted($profile, FileManagerPermissions::DELETE, $cleanPath, $resolvedVol->name);
            $normalizedPaths[] = $cleanPath;
        }
        if ($normalizedPaths !== []) {
            $this->operations->preValidateDeleteItems($profile, $normalizedPaths, $resolvedVol->name, false, null, false);
        }

        if ($this->usageGuardService === null) {
            return [
                'enabled' => false,
                'policy' => AssetUsageGuardService::POLICY_WARN,
                'blocked' => false,
                'totalUsages' => 0,
                'truncated' => false,
                'items' => [],
            ];
        }

        return $this->usageGuardService->inspectPaths($profile, $normalizedPaths, $resolvedVol->name);
    }

    /** @param array<string, mixed> $p */
    private function handleDelete(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $rawPaths = is_array($p['paths'] ?? null) ? array_values(array_filter($p['paths'], 'is_string')) : [];
        if (count($rawPaths) > self::MAX_BULK_DELETE_ITEMS) {
            throw new FileManagerException(sprintf('Maximum %d items can be deleted in a single request.', self::MAX_BULK_DELETE_ITEMS), 'errors.bulk_delete_limit', ['%max%' => self::MAX_BULK_DELETE_ITEMS]);
        }

        $user = $this->security?->getUser()?->getUserIdentifier();
        $forcePermanent = filter_var($p['force'] ?? false, FILTER_VALIDATE_BOOL);

        $resolvedVol = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $paths = $this->pruneNestedPaths($rawPaths, $resolvedVol->isLocalFilesystem() && (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin'));
        if ($paths === []) {
            throw new FileManagerException('No valid items selected.', 'errors.zip_no_selection');
        }

        $needsPurgePerm = $forcePermanent && $this->trashManager->isEnabled($profile);
        foreach ($paths as $path) {
            $this->assertGranted($profile, FileManagerPermissions::VIEW, $path, $resolvedVol->name);
            $this->assertGranted($profile, FileManagerPermissions::DELETE, $path, $resolvedVol->name);
            if ($needsPurgePerm) {
                $this->assertGranted($profile, FileManagerPermissions::TRASH_PURGE, $path, $resolvedVol->name);
            }
        }

        // Pre-validate all selected paths (including recursive child folders and TRASH_PURGE checks) before deleting any item.
        $trashed = $this->trashManager->withBulkTrashBatch(function () use ($profile, $paths, $vol, $forcePermanent, $user): bool {
            $this->operations->preValidateDeleteItems($profile, $paths, $vol, $forcePermanent, $this->usageGuardService);

            $moved = false;
            foreach ($paths as $path) {
                $moved = $this->operations->delete($profile, $path, $vol, $forcePermanent, $user);
            }

            return $moved;
        });

        $count = count($paths);

        return $trashed
            ? $this->messageTranslator->trans('messages.deleted_to_trash', ['%count%' => $count], $locale, '%count% item(s) moved to Trash.')
            : $this->messageTranslator->trans('messages.deleted_permanently', ['%count%' => $count], $locale, '%count% item(s) permanently deleted.');
    }

    /**
     * Removes duplicate paths and any child path whose ancestor folder is already queued for deletion.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function pruneNestedPaths(array $paths, bool $caseInsensitive = false): array
    {
        $normalized = [];
        foreach ($paths as $raw) {
            $clean = $this->pathNormalizer->normalize($raw);
            if ($clean === '') {
                continue;
            }
            $dedupKey = $caseInsensitive ? mb_strtolower($clean, 'UTF-8') : $clean;
            $normalized[$dedupKey] ??= $clean;
        }

        $sorted = array_values($normalized);
        usort($sorted, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        $kept = [];
        $keptPrefixes = [];
        foreach ($sorted as $candidate) {
            $candidateKey = $caseInsensitive ? mb_strtolower($candidate, 'UTF-8') : $candidate;
            $covered = false;
            foreach ($keptPrefixes as $ancestorPrefix) {
                if (str_starts_with($candidateKey, $ancestorPrefix)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $kept[] = $candidate;
                $keptPrefixes[] = $candidateKey . '/';
            }
        }

        return $kept;
    }

    /** @param array<string, mixed> $p */
    private function handleRestoreTrash(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        if (!$this->trashManager->isEnabled($profile)) {
            throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
        }
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $canRoot = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $volume->name)
            && $this->isGranted($profile, FileManagerPermissions::TRASH_RESTORE, '', $volume->name);
        if (!$canRoot && !$this->hasAnyVisibleTrashPermission($profile, $volume->name, FileManagerPermissions::TRASH_RESTORE)) {
            throw new AccessDeniedException('Access denied.', 'errors.access_denied');
        }
        $trashId = trim($this->requireString($p, 'trashId', ''));
        $existing = $this->trashManager->findEntry($profile, $trashId, $volume->name);
        if ($existing !== null) {
            $targetAuthPath = $this->pathNormalizer->normalize($existing->originalPath);
            if (!$this->isGranted($profile, FileManagerPermissions::VIEW, $targetAuthPath, $volume->name)) {
                throw new FileManagerException(sprintf('Trash entry "%s" was not found.', $trashId), 'errors.trash_entry_not_found', ['%id%' => $trashId]);
            }
            $this->assertGranted($profile, FileManagerPermissions::TRASH_RESTORE, $targetAuthPath, $volume->name);
        }
        $entry = $this->trashManager->restore($profile, $trashId, $volume->name, true);

        return $this->messageTranslator->trans('messages.restored', ['%name%' => $entry->name], $locale, 'Restored: %name%');
    }

    /** @param array<string, mixed> $p */
    private function handlePurgeTrash(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        if (!$this->trashManager->isEnabled($profile)) {
            throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
        }
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $canRoot = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $volume->name)
            && $this->isGranted($profile, FileManagerPermissions::TRASH_PURGE, '', $volume->name);
        if (!$canRoot && !$this->hasAnyVisibleTrashPermission($profile, $volume->name, FileManagerPermissions::TRASH_PURGE)) {
            throw new AccessDeniedException('Access denied.', 'errors.access_denied');
        }
        $trashId = trim($this->requireString($p, 'trashId', ''));
        $existing = $this->trashManager->findEntry($profile, $trashId, $volume->name);
        if ($existing === null) {
            throw new FileManagerException(sprintf('Trash entry "%s" was not found.', $trashId), 'errors.trash_entry_not_found', ['%id%' => $trashId]);
        }
        $targetAuthPath = $this->pathNormalizer->normalize($existing->originalPath);
        if (!$this->isGranted($profile, FileManagerPermissions::VIEW, $targetAuthPath, $volume->name)) {
            throw new FileManagerException(sprintf('Trash entry "%s" was not found.', $trashId), 'errors.trash_entry_not_found', ['%id%' => $trashId]);
        }
        $this->assertGranted($profile, FileManagerPermissions::TRASH_PURGE, $targetAuthPath, $volume->name);
        $this->trashManager->deletePermanently($profile, $trashId, $volume->name, true);

        return $this->messageTranslator->trans('messages.purged', [], $locale, 'Item permanently deleted.');
    }

    private function handleEmptyTrash(ProfileConfig $profile, ?string $vol, string $locale): string
    {
        if (!$this->trashManager->isEnabled($profile)) {
            throw new AccessDeniedException('Trash is disabled for this profile.', 'errors.access_denied');
        }
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $canRoot = $this->isGranted($profile, FileManagerPermissions::VIEW, '', $volume->name)
            && $this->isGranted($profile, FileManagerPermissions::TRASH_PURGE, '', $volume->name);
        if (!$canRoot && !$this->hasAnyVisibleTrashPermission($profile, $volume->name, FileManagerPermissions::TRASH_PURGE)) {
            throw new AccessDeniedException('Access denied.', 'errors.access_denied');
        }
        $count = $this->trashManager->purgeExpired($profile, $volume->name, 0, true);

        return $this->messageTranslator->trans('messages.trash_emptied', ['%count%' => $count], $locale, 'Trash emptied (%count% item(s)).');
    }

    private function hasAnyVisibleTrashPermission(ProfileConfig $profile, string $volumeName, string $permission): bool
    {
        if (!$this->canAttemptTrashScan($profile)) {
            return false;
        }

        foreach ($this->trashManager->listTrash($profile, $volumeName, true) as $entry) {
            try {
                $cleanOrig = $this->pathNormalizer->normalize($entry->originalPath);
            } catch (Throwable) {
                continue;
            }
            if (
                $this->isGranted($profile, FileManagerPermissions::VIEW, $cleanOrig, $volumeName)
                && $this->isGranted($profile, $permission, $cleanOrig, $volumeName)
            ) {
                return true;
            }
        }

        return false;
    }

    private function canAttemptTrashScan(ProfileConfig $profile): bool
    {
        if (!$profile->isVoterEnabled()) {
            return false;
        }
        if ($profile->getPermissionRule('view') === false) {
            return false;
        }
        if ($this->security !== null && $this->security->getUser() === null) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $p */
    private function handleSaveMetadata(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $rawColor = is_string($p['color'] ?? null) ? trim($p['color']) : '';
        $safeColor = $rawColor !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $rawColor) === 1 ? $rawColor : null;
        $rawIcon = is_string($p['icon'] ?? null) ? trim($p['icon']) : '';
        $safeIcon = $rawIcon !== '' && preg_match('/^[a-zA-Z0-9:_-]{1,40}$/', $rawIcon) === 1 ? $rawIcon : null;
        $iconExplicit = array_key_exists('icon', $p);
        $pinned = (array_key_exists('pinned', $p) && $p['pinned'] !== null) ? filter_var($p['pinned'], FILTER_VALIDATE_BOOL) : null;

        $this->operations->updateFolderAppearance(
            $profile,
            $this->requireString($p, 'path', ''),
            $safeColor,
            $safeIcon,
            $pinned,
            $vol,
            $iconExplicit,
        );

        return $this->messageTranslator->trans('messages.metadata_saved', [], $locale, 'Folder appearance updated.');
    }

    /** @param array<string, mixed> $p */
    private function handleSaveFileMetadata(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $alt = is_string($p['alt'] ?? null) ? $p['alt'] : null;
        $title = is_string($p['title'] ?? null) ? $p['title'] : null;
        $caption = is_string($p['caption'] ?? null) ? $p['caption'] : null;
        $tags = \LiveUX\FileManagerBundle\Metadata\FolderMetadata::sanitizeTags($p['tags'] ?? []);
        $focalPoint = \LiveUX\FileManagerBundle\Metadata\FolderMetadata::sanitizeFocalPoint($p['focalPoint'] ?? null);

        $this->operations->updateFileMetadata(
            $profile,
            $this->requireString($p, 'path', ''),
            $alt,
            $title,
            $caption,
            $tags,
            $focalPoint,
            $vol,
        );

        return $this->messageTranslator->trans('messages.file_metadata_saved', [], $locale, 'File metadata updated.');
    }

    /** @param array<string, mixed> $p */
    private function handleExtractZip(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        if ($this->zipArchiveService === null) {
            throw new FileManagerException('ZIP extraction service is unavailable.', 'errors.zip_extract_disabled');
        }

        $targetDir = is_string($p['targetDir'] ?? null) && trim($p['targetDir']) !== '' ? $p['targetDir'] : null;
        $result = $this->zipArchiveService->extractArchive($profile, $this->requireString($p, 'path', ''), $targetDir, $vol);

        return $this->messageTranslator->trans(
            'messages.zip_extracted',
            ['%count%' => $result['extracted'], '%dir%' => '/' . $result['targetDir']],
            $locale,
            'Extracted %count% file(s) to %dir%.',
        );
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{url: string, expiresAt: int, ttlSeconds: int, message: string}
     */
    private function handleCreateShareLink(ProfileConfig $profile, ?string $vol, array $p, string $locale): array
    {
        if ($this->shareLinkService === null) {
            throw new FileManagerException('Signed share links are disabled or unavailable.', 'errors.share_disabled');
        }

        $ttl = is_numeric($p['ttl'] ?? null) ? (int) $p['ttl'] : null;
        $inline = filter_var($p['inline'] ?? false, FILTER_VALIDATE_BOOL);
        $signed = $this->shareLinkService->createSignedUrl(
            $profile,
            $this->requireString($p, 'path', ''),
            $vol,
            $ttl,
            $inline,
        );

        return [
            'url' => $signed['url'],
            'expiresAt' => $signed['expiresAt'],
            'ttlSeconds' => $signed['ttlSeconds'],
            'message' => $this->messageTranslator->trans('messages.share_link_created', [], $locale, 'Signed share link generated.'),
        ];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{suggestion: array<string, mixed>, message: string}
     */
    private function handleSuggestFileMetadata(ProfileConfig $profile, ?string $vol, array $p, string $locale): array
    {
        if ($this->aiEnrichmentService === null) {
            throw new FileManagerException('AI enrichment is disabled or unavailable.', 'errors.ai_unavailable');
        }

        // Respect profile-configured `ai.locale` unless an explicit `aiLocale` override was sent.
        $explicitAiLocale = is_string($p['aiLocale'] ?? null) && trim($p['aiLocale']) !== ''
            ? trim($p['aiLocale'])
            : null;
        $hasProfileAiLocale = is_string($profile->ai['locale'] ?? null) && trim($profile->ai['locale']) !== '';
        $aiLocaleOverride = $explicitAiLocale ?? ($hasProfileAiLocale ? null : $locale);

        $suggestion = $this->aiEnrichmentService->suggestMetadata(
            $profile,
            $this->requireString($p, 'path', ''),
            $vol,
            $aiLocaleOverride,
        );

        return [
            'suggestion' => $suggestion->toArray(),
            'message' => $this->messageTranslator->trans('messages.ai_suggested', [], $locale, 'AI metadata suggestions ready.'),
        ];
    }

    /** @param array<string, mixed> $p */
    private function handleTogglePin(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $pinned = $this->operations->toggleFolderPin($profile, $this->requireString($p, 'path', ''), $vol);

        return $pinned
            ? $this->messageTranslator->trans('messages.folder_pinned', [], $locale, 'Folder pinned to Quick Access.')
            : $this->messageTranslator->trans('messages.folder_unpinned', [], $locale, 'Folder removed from Quick Access.');
    }

    /**
     * @return array<string, mixed>
     */
    private function handleQuotaDetails(ProfileConfig $profile, ?string $vol): array
    {
        if (($profile->ui['show_quota'] ?? true) !== true || ($profile->ui['show_quota_details'] ?? true) !== true) {
            throw new AccessDeniedException('Storage quota details are disabled for this profile.', 'errors.access_denied');
        }

        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, '', $volume->name);

        if ($this->quotaChecker === null) {
            return [
                'volume' => $volume->name,
                'volumeLabel' => $volume->label,
                'summary' => [
                    'enabled' => false,
                    'usedBytes' => 0,
                    'maxBytes' => 0,
                    'remainingBytes' => 0,
                    'percent' => 0.0,
                    'usedFormatted' => '0 B',
                    'maxFormatted' => '',
                    'remainingFormatted' => '',
                ],
                'counts' => ['files' => 0, 'folders' => 0, 'trashFiles' => 0, 'trashBytes' => 0, 'trashFormatted' => '0 B', 'trashPercent' => 0.0],
                'categories' => [],
                'topFolders' => [],
                'largestFiles' => [],
                'capped' => false,
            ];
        }

        return $this->quotaChecker->getDetailedBreakdown($profile, $volume->name);
    }

    /** @param array<string, mixed> $p */
    private function handleClearCache(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $cleanPath = $this->pathNormalizer->normalize($this->requireString($p, 'path', ''));
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);

        $scope = $this->requireString($p, 'scope', 'dir');
        if (!in_array($scope, ['dir', 'profile', 'thumbs'], true)) {
            throw new FileManagerException('Invalid cache scope.', 'errors.action_failed');
        }
        $authPath = ($scope === 'thumbs' || $scope === 'profile') ? '' : $cleanPath;
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $authPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::CLEAR_CACHE, $authPath, $volume->name);

        if ($scope === 'thumbs' || $scope === 'profile') {
            foreach ($profile->volumes as $profVol) {
                if ($profVol !== $volume->name) {
                    $this->assertGranted($profile, FileManagerPermissions::VIEW, '', $profVol);
                    $this->assertGranted($profile, FileManagerPermissions::CLEAR_CACHE, '', $profVol);
                }
            }
        }

        if ($scope === 'thumbs') {
            $this->clearProfileThumbnailsSafely($profile);

            return $this->messageTranslator->trans('messages.cache_thumbs_cleared', [], $locale, 'Thumbnail cache cleared.');
        }

        if ($scope === 'profile') {
            $usesUserId = false;
            foreach ($this->volumeRegistry->getRawVolumesForProfile($profile) as $rawVol) {
                if (ShareLinkService::volumeUsesUserIdToken($rawVol)) {
                    $usesUserId = true;
                    break;
                }
            }
            if ($usesUserId) {
                $volNames = $profile->volumes !== [] ? $profile->volumes : [$volume->name];
                foreach ($volNames as $profVolName) {
                    $resolvedProfVol = $this->volumeRegistry->getVolumeForProfile($profile, $profVolName);
                    $rootCanon = $this->pathNormalizer->buildCanonicalKey($resolvedProfVol, '', $profile->basePath);
                    $this->cacheManager->invalidateVolumeScope($this->cacheManager->resolveVolumeScopeForRoot($rootCanon));
                }
            } else {
                $this->cacheManager->invalidateProfile($profile->name);
            }

            return $this->messageTranslator->trans('messages.cache_profile_cleared', [], $locale, 'Profile cache cleared.');
        }

        $canonical = $this->pathNormalizer->buildCanonicalKey($volume, $cleanPath, $profile->basePath);
        $this->cacheManager->invalidateDirectory($canonical);
        if ($this->isGranted($profile, FileManagerPermissions::VIEW, '', $volume->name)) {
            $treeCanonical = $this->pathNormalizer->buildCanonicalKey($volume, DirectoryLister::TREE_CACHE_KEY, $profile->basePath);
            $this->cacheManager->invalidateDirectory($treeCanonical);
        }

        return $this->messageTranslator->trans('messages.cache_dir_cleared', [], $locale, 'Directory cache cleared.');
    }

    private function clearProfileThumbnailsSafely(ProfileConfig $profile): void
    {
        $cacheDir = (string) ($profile->thumbnail['cache_dir'] ?? '');
        if ($cacheDir === '') {
            return;
        }

        if ($this->isThumbnailCacheDirShared($profile, $cacheDir)) {
            if ($this->profileFileScanner === null) {
                return;
            }
            foreach ($this->profileFileScanner->resolveTargetVolumes($profile, null) as $vol) {
                $deletedCount = 0;
                foreach ($this->profileFileScanner->iterateRasterImages($profile, $vol) as $img) {
                    if (++$deletedCount > 5000) {
                        break;
                    }
                    if (!$this->isGranted($profile, FileManagerPermissions::VIEW, $img['path'], $vol->name)) {
                        continue;
                    }
                    $this->thumbnailManager->deleteCachedThumbnail($profile, $img['canonical']);
                }
            }

            return;
        }

        $this->thumbnailManager->clearCacheDir($cacheDir);
    }

    private function isThumbnailCacheDirShared(ProfileConfig $profile, string $cacheDir): bool
    {
        foreach ($this->volumeRegistry->getRawVolumesForProfile($profile) as $rawVol) {
            if (ShareLinkService::volumeUsesUserIdToken($rawVol)) {
                return true;
            }
        }

        $real = realpath($cacheDir);
        $targetKey = mb_strtolower(str_replace('\\', '/', rtrim($real !== false ? $real : $cacheDir, '/\\')), 'UTF-8');

        foreach ($this->configProvider->getProfileNames() as $name) {
            if ($name === $profile->name) {
                continue;
            }
            $dir = (string) ($this->configProvider->getProfile($name)->thumbnail['cache_dir'] ?? '');
            if ($dir === '') {
                continue;
            }
            $otherReal = realpath($dir);
            $otherKey = mb_strtolower(str_replace('\\', '/', rtrim($otherReal !== false ? $otherReal : $dir, '/\\')), 'UTF-8');
            if ($otherKey === $targetKey || $this->cacheDirsOverlap($targetKey, $otherKey)) {
                return true;
            }
        }

        return false;
    }

    private function cacheDirsOverlap(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/');
    }

    /** @param array<string, mixed> $p */
    private function handleImportUrl(ProfileConfig $profile, ?string $vol, array $p, string $locale): string
    {
        $result = $this->remoteUrlImporter->import(
            $profile,
            $this->requireString($p, 'url', ''),
            $this->requireString($p, 'targetDir', ''),
            $vol,
        );

        return $this->messageTranslator->trans('messages.url_imported', ['%name%' => $result['name']], $locale, 'Imported: %name%');
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{aborted: bool}
     */
    private function handleAbortUpload(ProfileConfig $profile, ?string $vol, array $p, string $locale): array
    {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $vol);
        $cleanPath = $this->pathNormalizer->normalize($this->requireString($p, 'path', ''));
        $this->fileSecurityGuard->assertAccessiblePath($cleanPath, $profile);
        $this->assertGranted($profile, FileManagerPermissions::VIEW, $cleanPath, $volume->name);
        $this->assertGranted($profile, FileManagerPermissions::UPLOAD, $cleanPath, $volume->name);
        $uploadId = $this->requireString($p, 'uploadId', '');
        $aborted = $uploadId !== '' && ($this->uploadManager?->abortChunkedUpload($profile, $uploadId, $volume->name, $cleanPath) ?? false);

        return ['aborted' => $aborted];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return $default;
        }

        $val = $payload[$key];
        if (is_string($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        throw new FileManagerException(sprintf('Expected string parameter for "%s".', $key), 'errors.invalid_json');
    }

    /**
     * Resolves static route prefixes once per listing so serializing large folders does not
     * invoke Symfony's UrlGenerator up to 3x per file item.
     *
     * @return array{download: ?string, thumb: ?string}
     */
    private function resolveFastItemUrlBases(): array
    {
        return [
            'download' => $this->probeStaticQueryRouteBase('liveux_file_manager_download'),
            'thumb' => $this->probeStaticQueryRouteBase('liveux_file_manager_thumb'),
        ];
    }

    private function probeStaticQueryRouteBase(string $routeName): ?string
    {
        try {
            $base = $this->urlGenerator->generate($routeName);
            if ($base === '' || str_contains($base, '?')) {
                return null;
            }
            $probe = $this->urlGenerator->generate($routeName, [
                'profile' => '__p__',
                'volume' => '__v__',
                'path' => '__k__',
            ]);

            return $probe === ($base . '?profile=__p__&volume=__v__&path=__k__') ? $base : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveHybridClientLimit(array $query, bool $all, bool $recursive): ?int
    {
        if (!$all || $recursive || !array_key_exists('hybridLimit', $query)) {
            return null;
        }

        $raw = $query['hybridLimit'];
        if (!is_int($raw) && !is_string($raw)) {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }

        return max(self::MIN_HYBRID_CLIENT_LIMIT, min(self::MAX_HYBRID_CLIENT_LIMIT, (int) $raw));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(
        FolderItem|FileItem $item,
        string $profileName,
        string $volumeName,
        ?string $downloadBase = null,
        ?string $thumbBase = null,
        bool $compact = false,
    ): array {
        if ($item instanceof FolderItem) {
            return $this->serializeFolderItem($item, $compact);
        }

        if ($compact && $downloadBase !== null) {
            return $this->serializeCompactFileItem($item, $thumbBase !== null);
        }

        return $this->serializeFullFileItem($item, $profileName, $volumeName, $downloadBase, $thumbBase);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFolderItem(FolderItem $item, bool $compact): array
    {
        if (!$compact) {
            return [
                'kind' => 'folder',
                'name' => $item->name,
                'path' => $item->path,
                'color' => $item->metadata->color,
                'icon' => $item->metadata->icon,
                'pinned' => $item->metadata->pinned,
                'lastModified' => $item->lastModified,
                'itemCount' => $item->itemCount,
            ];
        }

        $data = [
            'kind' => 'folder',
            'name' => $item->name,
            'path' => $item->path,
            'lastModified' => $item->lastModified,
        ];
        if ($item->metadata->color !== null) {
            $data['color'] = $item->metadata->color;
        }
        if ($item->metadata->icon !== null) {
            $data['icon'] = $item->metadata->icon;
        }
        if ($item->metadata->pinned) {
            $data['pinned'] = true;
        }
        if ($item->itemCount !== null) {
            $data['itemCount'] = $item->itemCount;
        }

        return $data;
    }

    /**
     * Omits derivable downloadUrl / thumbUrl / sizeFormatted and null defaults for ~60% smaller JSON wire payloads.
     *
     * @return array<string, mixed>
     */
    private function serializeCompactFileItem(FileItem $item, bool $hasThumbBase): array
    {
        $data = [
            'kind' => 'file',
            'name' => $item->name,
            'path' => $item->path,
            'size' => $item->size,
            'extension' => $item->extension,
            'category' => $item->category,
            'lastModified' => $item->lastModified,
        ];
        if ($item->createdAt !== null && $item->createdAt !== $item->lastModified) {
            $data['createdAt'] = $item->createdAt;
        }
        if ($item->isImage()) {
            $data['isImage'] = true;
            if (!$hasThumbBase) {
                $data['thumbUrl'] = null;
            }
        }
        if ($item->publicUrl !== null) {
            $data['url'] = $item->publicUrl;
        }
        $meta = $item->metadata?->toFileMetaArray();
        if ($meta !== null) {
            $data['meta'] = $meta;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFullFileItem(
        FileItem $item,
        string $profileName,
        string $volumeName,
        ?string $downloadBase,
        ?string $thumbBase,
    ): array {
        $inlineUrl = $item->publicUrl ?? ($downloadBase !== null
            ? $downloadBase . '?' . http_build_query([
                'profile' => $profileName,
                'volume' => $volumeName,
                'path' => $item->path,
                'inline' => 1,
            ], '', '&', PHP_QUERY_RFC3986)
            : $this->urlGenerator->generate('liveux_file_manager_download', [
                'profile' => $profileName,
                'volume' => $volumeName,
                'path' => $item->path,
                'inline' => 1,
            ]));

        $downloadUrl = $downloadBase !== null
            ? $downloadBase . '?' . http_build_query([
                'profile' => $profileName,
                'volume' => $volumeName,
                'path' => $item->path,
            ], '', '&', PHP_QUERY_RFC3986)
            : $this->urlGenerator->generate('liveux_file_manager_download', [
                'profile' => $profileName,
                'volume' => $volumeName,
                'path' => $item->path,
            ]);

        $thumbUrl = null;
        if ($item->isImage()) {
            $thumbUrl = $thumbBase !== null
                ? $thumbBase . '?' . http_build_query([
                    'profile' => $profileName,
                    'volume' => $volumeName,
                    'path' => $item->path,
                    'v' => $item->lastModified,
                ], '', '&', PHP_QUERY_RFC3986)
                : $this->urlGenerator->generate('liveux_file_manager_thumb', [
                    'profile' => $profileName,
                    'volume' => $volumeName,
                    'path' => $item->path,
                    'v' => $item->lastModified,
                ]);
        }

        return [
            'kind' => 'file',
            'name' => $item->name,
            'path' => $item->path,
            'size' => $item->size,
            'sizeFormatted' => $item->formatSize(),
            'extension' => $item->extension,
            'category' => $item->category,
            'lastModified' => $item->lastModified,
            'createdAt' => $item->createdAt,
            'isImage' => $item->isImage(),
            'url' => $inlineUrl,
            'downloadUrl' => $downloadUrl,
            'thumbUrl' => $thumbUrl,
            'meta' => $item->metadata?->toFileMetaArray(),
        ];
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    private function buildBreadcrumbs(string $cleanPath): array
    {
        if ($cleanPath === '') {
            return [];
        }

        $crumbs = [];
        $acc = '';
        foreach (explode('/', $cleanPath) as $seg) {
            if ($seg === '') {
                continue;
            }
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $crumbs[] = ['label' => $seg, 'path' => $acc];
        }

        return $crumbs;
    }

    private function isGranted(ProfileConfig $profile, string $attribute, string $path, ?string $volume): bool
    {
        return $this->authorizer->isPathAndAncestorsGranted($profile, $attribute, $path, $volume);
    }

    private function assertGranted(ProfileConfig $profile, string $attribute, string $path, ?string $volume): void
    {
        $this->authorizer->assertPathAndAncestorsGranted($profile, $attribute, $path, $volume);
    }

    private function plainTextErrorResponse(string $message, int $statusCode): Response
    {
        $response = new Response($message, $statusCode);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function readBoundedText(FilesystemOperator $filesystem, string $cleanPath): string
    {
        $stream = $filesystem->readStream($cleanPath);
        if (!is_resource($stream)) {
            return '';
        }

        try {
            $limit = self::MAX_TEXT_PREVIEW_BYTES + 4;
            $buffer = '';
            while (!feof($stream) && strlen($buffer) < $limit) {
                $remaining = $limit - strlen($buffer);
                $chunk = fread($stream, $remaining);
                if ($chunk === false) {
                    throw new FileManagerException('Unable to read text preview stream.', 'errors.action_failed');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }
                    throw new FileManagerException('Unexpected empty read from text preview stream.', 'errors.action_failed');
                }
                $buffer .= $chunk;
            }

            return $buffer;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function filesystemErrorJsonResponse(
        FilesystemException $exception,
        int $statusCode,
        Request $request,
        ?ProfileConfig $profile = null,
        array $payload = [],
    ): JsonResponse {
        $locale = $this->messageTranslator->resolveLocale($request, $profile, $payload);

        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->trans('errors.action_failed', [], $locale, 'Storage operation failed.'),
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function errorJsonResponse(
        FileManagerException $exception,
        int $statusCode,
        Request $request,
        ?ProfileConfig $profile = null,
        array $payload = [],
    ): JsonResponse {
        $locale = $this->messageTranslator->resolveLocale($request, $profile, $payload);

        return $this->privateJsonResponse([
            'ok' => false,
            'error' => $this->messageTranslator->translateException($exception, $locale),
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJsonResponse(array $data, int $statusCode = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $statusCode);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function sanitizeDynamicIconSet(mixed $rawIconSet): ?string
    {
        if (!is_string($rawIconSet) || trim($rawIconSet) === '') {
            return null;
        }

        $trimmed = trim($rawIconSet);
        if ($this->iconSetRegistry !== null) {
            return $this->iconSetRegistry->isBuiltinSet($trimmed) ? $trimmed : null;
        }

        return preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
