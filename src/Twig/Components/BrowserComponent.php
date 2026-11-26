<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Twig\Components;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\FileManagerBundle;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Model\DirectoryListing;
use LiveUX\FileManagerBundle\Model\FileItem;
use LiveUX\FileManagerBundle\Model\FolderItem;
use LiveUX\FileManagerBundle\Security\CsrfRequestValidator;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use LiveUX\FileManagerBundle\Service\AssetUsageGuardService;
use LiveUX\FileManagerBundle\Service\DirectoryLister;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ShareLinkService;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Theme\FileManagerThemes;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Throwable;

#[AsTwigComponent('LiveUX:FileManager:Browser', template: '@LiveUXFileManager/components/Browser.html.twig')]
final class BrowserComponent
{
    private const array ALLOWED_TYPE_FILTERS = ['all', 'folder', 'image', 'doc', 'video'];

    public string $profile = 'default';
    public ?string $volume = null;
    public string $path = '';
    public int $page = 1;
    public int $perPage = 24;
    public string $viewMode = 'grid';
    public string $typeFilter = 'all';
    public string $search = '';
    public string $sortBy = 'name';
    public string $sortDirection = 'asc';
    public bool $multiple = false;
    public string $mode = 'inline';
    public bool $thumbnailsEnabled = true;
    public string $thumbFit = 'contain';
    public bool $treeLines = true;
    public ?string $iconSet = null;
    public ?string $locale = null;
    public ?string $theme = null;

    public const int HYBRID_CLIENT_THRESHOLD = 500;

    private ?ProfileConfig $cachedProfile = null;
    private ?DirectoryListing $cachedListing = null;
    private ?DirectoryListing $cachedFullListing = null;

    /**
     * @var list<array{name: string, path: string, parent: string, color: ?string, icon: ?string, pinned: bool, itemCount: ?int}>|null
     */
    private ?array $cachedInitialTree = null;

    /**
     * @var array<string, array<string, bool>>
     */
    private array $capabilitiesCache = [];

    /**
     * @var array<string, array{enabled: bool, usedBytes: int, maxBytes: int, remainingBytes: int, percent: float, usedFormatted: string, maxFormatted: string, remainingFormatted: string}>
     */
    private array $quotaSummaryCache = [];

    /**
     * @var array<string, array{local: list<VolumeConfig>, network: list<VolumeConfig>}>
     */
    private array $groupedVolumesCache = [];

    private readonly FileManagerAuthorizer $authorizer;

    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
        private readonly VolumeRegistry $volumeRegistry,
        private readonly DirectoryLister $directoryLister,
        private readonly TrashManager $trashManager,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly ZipArchiveService $zipArchiveService,
        private readonly PathNormalizer $pathNormalizer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly IconSetRegistry $iconSetRegistry,
        private readonly CsrfRequestValidator $csrfValidator,
        private readonly ?TranslatorInterface $translator = null,
        ?AuthorizationCheckerInterface $authChecker = null,
        private readonly FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
        private readonly ?StorageQuotaChecker $quotaChecker = null,
        private readonly ?ShareLinkService $shareLinkService = null,
        private readonly ?AssetEnrichmentService $aiEnrichmentService = null,
        private readonly ?AssetUsageGuardService $usageGuardService = null,
        private readonly ?MessageTranslator $messageTranslator = null,
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    public function mount(
        string $profile = 'default',
        ?string $volume = null,
        string $path = '',
        bool $multiple = false,
        string $mode = 'inline',
        ?string $iconSet = null,
        ?string $locale = null,
        ?string $theme = null,
        ?string $typeFilter = null,
    ): void {
        $this->profile = $profile;
        $this->multiple = $multiple;
        $this->mode = $mode;
        $this->iconSet = ($iconSet !== null && $this->iconSetRegistry->isBuiltinSet($iconSet)) ? trim($iconSet) : null;
        $this->locale = ($locale !== null && MessageTranslator::isValidLocaleString($locale)) ? trim($locale) : null;
        $this->theme = FileManagerThemes::sanitizeDynamic($theme);
        $config = $this->getProfileConfig();

        $activeVol = $this->volumeRegistry->getVolumeForProfile($config, $volume);
        $this->volume = $activeVol->name;
        $this->path = $this->pathNormalizer->normalize($path);
        $this->fileSecurityGuard->assertAccessiblePath($this->path, $config);
        $this->authorizer->assertPathAndAncestorsGranted($config, FileManagerPermissions::VIEW, $this->path, $this->volume);

        $cleanType = is_string($typeFilter) ? strtolower(trim($typeFilter)) : 'all';
        $this->typeFilter = in_array($cleanType, self::ALLOWED_TYPE_FILTERS, true) ? $cleanType : 'all';
        $this->perPage = (int) ($config->ui['per_page'] ?? 24);
        $this->viewMode = (string) ($config->ui['default_view'] ?? 'grid');
        $this->sortBy = $config->sortBy;
        $this->sortDirection = $config->sortDirection;
        $this->thumbnailsEnabled = ($config->thumbnail['enabled'] ?? true) === true;
        $this->thumbFit = (string) ($config->thumbnail['fit'] ?? 'contain');
        $this->treeLines = ($config->ui['tree_lines'] ?? true) === true;
    }

    public function getProfileConfig(): ProfileConfig
    {
        if ($this->cachedProfile === null) {
            $this->cachedProfile = $this->configProvider->getProfile($this->profile);
        }

        return $this->cachedProfile;
    }

    public function getActiveVolume(): VolumeConfig
    {
        return $this->volumeRegistry->getVolumeForProfile($this->getProfileConfig(), $this->volume);
    }

    /**
     * @return array{local: list<VolumeConfig>, network: list<VolumeConfig>}
     */
    public function getGroupedVolumes(): array
    {
        $cacheKey = $this->profile . '|' . ($this->volume ?? '');
        if (isset($this->groupedVolumesCache[$cacheKey])) {
            return $this->groupedVolumesCache[$cacheKey];
        }

        $profile = $this->getProfileConfig();
        $raw = $this->volumeRegistry->getGroupedVolumes($profile);
        $filtered = [
            'local' => array_values(array_filter(
                $raw['local'],
                fn (VolumeConfig $v): bool => $this->isVolumeViewGranted($profile, $v->name),
            )),
            'network' => array_values(array_filter(
                $raw['network'],
                fn (VolumeConfig $v): bool => $this->isVolumeViewGranted($profile, $v->name),
            )),
        ];
        $this->groupedVolumesCache[$cacheKey] = $filtered;

        return $filtered;
    }

    public function getVolumeOrder(): string
    {
        $raw = $this->getProfileConfig()->ui['volume_order'] ?? 'local_first';

        return is_string($raw) && in_array($raw, ['local_first', 'network_first', 'configured'], true)
            ? $raw
            : 'local_first';
    }

    /**
     * Returns sidebar volume sections ordered according to `ui.volume_order`.
     *
     * @return list<array{group: string, labelKey: ?string, showCount: bool, volumes: list<VolumeConfig>}>
     */
    public function getVolumeSections(): array
    {
        $order = $this->getVolumeOrder();
        if ($order === 'configured') {
            $profile = $this->getProfileConfig();
            $all = array_values(array_filter(
                $this->volumeRegistry->getVolumesForProfile($profile),
                fn (VolumeConfig $v): bool => $this->isVolumeViewGranted($profile, $v->name),
            ));

            return $all !== [] ? [[
                'group' => 'configured',
                'labelKey' => null,
                'showCount' => false,
                'volumes' => $all,
            ]] : [];
        }

        $grouped = $this->getGroupedVolumes();
        $localSection = $grouped['local'] !== [] ? [
            'group' => 'local',
            'labelKey' => 'volumes.local',
            'showCount' => false,
            'volumes' => $grouped['local'],
        ] : null;
        $networkSection = $grouped['network'] !== [] ? [
            'group' => 'network',
            'labelKey' => 'volumes.network',
            'showCount' => true,
            'volumes' => $grouped['network'],
        ] : null;

        $ordered = $order === 'network_first'
            ? [$networkSection, $localSection]
            : [$localSection, $networkSection];

        return array_values(array_filter($ordered, static fn (?array $section): bool => $section !== null));
    }

    /**
     * Returns all profile volumes in the exact display order matching `ui.volume_order`.
     *
     * @return list<VolumeConfig>
     */
    public function getOrderedVolumes(): array
    {
        $list = [];
        foreach ($this->getVolumeSections() as $section) {
            foreach ($section['volumes'] as $volume) {
                $list[] = $volume;
            }
        }

        return $list;
    }

    public function getListing(): DirectoryListing
    {
        if ($this->cachedListing === null) {
            $profile = $this->getProfileConfig();
            if ($this->page === 1 && trim($this->search) === '' && $this->sortBy === $profile->sortBy && $this->sortDirection === $profile->sortDirection) {
                $full = $this->getFullInitialDirectoryListing();
                $matched = $this->filterListingItemsByType($full->items, $this->typeFilter);
                $safePerPage = max(1, $this->perPage);
                $total = count($matched);
                $slice = array_slice($matched, 0, $safePerPage);
                $totalPages = max(1, (int) ceil($total / $safePerPage));
                $this->cachedListing = new DirectoryListing(
                    items: $slice,
                    total: $total,
                    page: 1,
                    perPage: $safePerPage,
                    totalPages: $totalPages,
                    fromCache: $full->fromCache,
                    durationMs: $full->durationMs,
                );
            } else {
                $this->cachedListing = $this->directoryLister->list(
                    profile: $profile,
                    volumeName: $this->volume,
                    path: $this->path,
                    page: $this->page,
                    perPage: $this->perPage,
                    typeFilter: $this->typeFilter,
                    search: $this->search,
                    sortBy: $this->sortBy,
                    sortDirection: $this->sortDirection,
                );
            }
        }

        return $this->cachedListing;
    }

    /**
     * @param list<FolderItem|FileItem> $items
     *
     * @return list<FolderItem|FileItem>
     */
    private function filterListingItemsByType(array $items, string $typeFilter): array
    {
        $type = strtolower(trim($typeFilter));
        if ($type === '' || $type === 'all') {
            return $items;
        }

        $filtered = [];
        foreach ($items as $item) {
            if ($item instanceof FolderItem) {
                if ($type === 'folder') {
                    $filtered[] = $item;
                }
                continue;
            }
            if ($type !== 'folder' && $item->category === $type) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    public function isInitialServerPaginated(): bool
    {
        return $this->getFullInitialDirectoryListing()->total > self::HYBRID_CLIENT_THRESHOLD;
    }

    public function getInitialTotalCount(): int
    {
        return $this->getListing()->total;
    }

    /**
     * Initial listing serialized for instant 0ms client-side view toggles (grid/list, thumbnails).
     * Automatically bounds SSR JSON to the first page when the directory exceeds HYBRID_CLIENT_THRESHOLD.
     *
     * @return list<array<string, mixed>>
     */
    public function getInitialItemsJson(): array
    {
        $items = [];
        $vol = $this->volume ?? $this->getActiveVolume()->name;
        $fullListing = $this->getFullInitialDirectoryListing($vol);
        $sourceItems = $fullListing->total > self::HYBRID_CLIENT_THRESHOLD
            ? $this->getListing()->items
            : $fullListing->items;
        $downloadBase = $this->probeStaticQueryRouteBase('liveux_file_manager_download');
        $thumbBase = $this->probeStaticQueryRouteBase('liveux_file_manager_thumb');

        foreach ($sourceItems as $item) {
            if ($item instanceof FolderItem) {
                $items[] = [
                    'kind' => 'folder',
                    'name' => $item->name,
                    'path' => $item->path,
                    'color' => $item->metadata->color,
                    'icon' => $item->metadata->icon,
                    'pinned' => $item->metadata->pinned,
                    'lastModified' => $item->lastModified,
                    'itemCount' => $item->itemCount,
                ];
                continue;
            }

            $inlineUrl = $item->publicUrl ?? ($downloadBase !== null
                ? $downloadBase . '?' . http_build_query([
                    'profile' => $this->profile,
                    'volume' => $vol,
                    'path' => $item->path,
                    'inline' => 1,
                ], '', '&', PHP_QUERY_RFC3986)
                : $this->urlGenerator->generate('liveux_file_manager_download', [
                    'profile' => $this->profile,
                    'volume' => $vol,
                    'path' => $item->path,
                    'inline' => 1,
                ]));

            $downloadUrl = $downloadBase !== null
                ? $downloadBase . '?' . http_build_query([
                    'profile' => $this->profile,
                    'volume' => $vol,
                    'path' => $item->path,
                ], '', '&', PHP_QUERY_RFC3986)
                : $this->urlGenerator->generate('liveux_file_manager_download', [
                    'profile' => $this->profile,
                    'volume' => $vol,
                    'path' => $item->path,
                ]);

            $thumbUrl = null;
            if ($item->isImage()) {
                $thumbUrl = $thumbBase !== null
                    ? $thumbBase . '?' . http_build_query([
                        'profile' => $this->profile,
                        'volume' => $vol,
                        'path' => $item->path,
                        'v' => $item->lastModified,
                    ], '', '&', PHP_QUERY_RFC3986)
                    : $this->urlGenerator->generate('liveux_file_manager_thumb', [
                        'profile' => $this->profile,
                        'volume' => $vol,
                        'path' => $item->path,
                        'v' => $item->lastModified,
                    ]);
            }

            $items[] = [
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

        return $items;
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

    public function getCsrfToken(): string
    {
        return $this->csrfValidator->getTokenValue();
    }

    /**
     * @return array{name: string, defaultFolderColor: string, icons: array<string, string>, ui: array<string, string>, badges: array<string, string>}
     */
    public function getIconSetJson(): array
    {
        if ($this->iconSet !== null && $this->iconSetRegistry->isBuiltinSet($this->iconSet)) {
            return $this->iconSetRegistry->getIconSetPayload($this->iconSet, false);
        }

        $configured = (string) ($this->getProfileConfig()->ui['icon_set'] ?? 'classic');

        return $this->iconSetRegistry->getIconSetPayload($configured, true);
    }

    public function getResolvedIconSet(): string
    {
        if ($this->iconSet !== null && $this->iconSetRegistry->isBuiltinSet($this->iconSet)) {
            return $this->iconSet;
        }

        $configured = (string) ($this->getProfileConfig()->ui['icon_set'] ?? 'classic');

        return trim($configured) !== '' ? trim($configured) : 'classic';
    }

    public function getResolvedColorScheme(): string
    {
        $raw = $this->getProfileConfig()->ui['color_scheme'] ?? 'auto';

        return is_string($raw) && in_array($raw, ['auto', 'light', 'dark'], true) ? $raw : 'auto';
    }

    public function getResolvedItemSizePx(): int
    {
        $raw = $this->getProfileConfig()->ui['item_size'] ?? 'md';
        if (is_int($raw)) {
            return max(96, min(320, $raw));
        }

        $str = is_string($raw) ? strtolower(trim($raw)) : 'md';
        if (ctype_digit($str)) {
            return max(96, min(320, (int) $str));
        }

        return match ($str) {
            'xs' => 108,
            'sm' => 132,
            'lg' => 204,
            'xl' => 260,
            default => 158,
        };
    }

    public function getResolvedCardAspectRatio(): string
    {
        $raw = $this->getProfileConfig()->ui['card_aspect_ratio'] ?? '4/3';

        return is_string($raw) && in_array($raw, ['1/1', '4/3', '16/9', '3/4'], true) ? $raw : '4/3';
    }

    public function getResolvedFolderStyle(): string
    {
        $raw = $this->getProfileConfig()->ui['folder_style'] ?? 'tile';

        return $raw === 'card' ? 'card' : 'tile';
    }

    /**
     * @return array{minWidth: ?int, minHeight: ?int, maxWidth: ?int, maxHeight: ?int, aspectRatio: ?string, aspectRatioTolerance: float, imageOptEnabled: bool}
     */
    public function getImageConstraintsJson(): array
    {
        $cfg = $this->getProfileConfig()->imageConstraints;
        $minW = is_int($cfg['min_width'] ?? null) && $cfg['min_width'] > 0 ? $cfg['min_width'] : null;
        $minH = is_int($cfg['min_height'] ?? null) && $cfg['min_height'] > 0 ? $cfg['min_height'] : null;
        $maxW = is_int($cfg['max_width'] ?? null) && $cfg['max_width'] > 0 ? $cfg['max_width'] : null;
        $maxH = is_int($cfg['max_height'] ?? null) && $cfg['max_height'] > 0 ? $cfg['max_height'] : null;
        $ratio = is_string($cfg['aspect_ratio'] ?? null) && trim($cfg['aspect_ratio']) !== '' ? trim($cfg['aspect_ratio']) : null;
        $tol = is_numeric($cfg['aspect_ratio_tolerance'] ?? null) ? max(0.0, min(0.5, (float) $cfg['aspect_ratio_tolerance'])) : 0.05;

        return [
            'minWidth' => $minW,
            'minHeight' => $minH,
            'maxWidth' => $maxW,
            'maxHeight' => $maxH,
            'aspectRatio' => $ratio,
            'aspectRatioTolerance' => $tol,
            'imageOptEnabled' => ($this->getProfileConfig()->imageOptimization['enabled'] ?? false) === true,
        ];
    }

    public function isBuiltinTheme(string $theme): bool
    {
        return FileManagerThemes::isBuiltin($theme);
    }

    public function getResolvedLocale(): string
    {
        $candidates = [
            $this->locale,
            $this->getProfileConfig()->locale,
            $this->translator?->getLocale(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && MessageTranslator::isValidLocaleString($candidate)) {
                return trim($candidate);
            }
        }

        return 'en';
    }

    public function getResolvedBcp47Locale(): string
    {
        return MessageTranslator::toBcp47($this->getResolvedLocale());
    }

    public function isRtl(): bool
    {
        return MessageTranslator::isRtlLocale($this->getResolvedLocale());
    }

    public function getResolvedTheme(): string
    {
        if ($this->theme !== null && FileManagerThemes::isBuiltin($this->theme)) {
            return $this->theme;
        }

        return $this->getProfileConfig()->theme;
    }

    public function getBundleVersion(): string
    {
        return FileManagerBundle::VERSION;
    }

    public function getResolvedZIndex(): int
    {
        $raw = $this->getProfileConfig()->ui['z_index'] ?? 1050;

        return is_int($raw) && $raw >= 1 ? $raw : 1050;
    }

    public function getResolvedMobileDialogMode(): string
    {
        $raw = $this->getProfileConfig()->ui['mobile_dialog_mode'] ?? 'auto';

        return in_array($raw, ['auto', 'fullscreen', 'sheet'], true) ? $raw : 'auto';
    }

    public function getFolderOpenOn(): string
    {
        return ($this->getProfileConfig()->ui['folder_open_on'] ?? 'dblclick') === 'click' ? 'click' : 'dblclick';
    }

    public function getThumbnailLazyLoad(): bool
    {
        return ($this->getProfileConfig()->thumbnail['lazy_load'] ?? true) === true;
    }

    public function getThumbnailFormatLabel(): string
    {
        $fmt = strtolower(trim((string) ($this->getProfileConfig()->thumbnail['format'] ?? 'webp')));

        return match ($fmt) {
            'jpg', 'jpeg' => 'JPEG',
            'png' => 'PNG',
            default => 'WebP',
        };
    }

    /**
     * @return list<int>
     */
    public function getPerPageOptions(): array
    {
        $raw = $this->getProfileConfig()->ui['per_page_options'] ?? [24, 48, 96];
        $options = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                $intVal = is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
                if ($intVal >= 1 && $intVal <= 500) {
                    $options[$intVal] = $intVal;
                }
            }
        }

        if ($options === []) {
            $options = [24 => 24, 48 => 48, 96 => 96];
        }

        $options[$this->perPage] = $this->perPage;
        ksort($options);

        return array_values($options);
    }

    /**
     * Full translated key map passed once to Stimulus so JS renders zero hardcoded strings.
     *
     * @return array<string, string>
     */
    public function getI18nJson(): array
    {
        $locale = $this->getResolvedLocale();
        $keys = [
            'actions.open', 'actions.preview', 'actions.upload', 'actions.upload_here',
            'actions.upload_to_folder', 'actions.new_folder', 'actions.new_subfolder',
            'actions.rename', 'actions.clone', 'actions.move', 'actions.move_verb',
            'actions.copy_verb', 'actions.cut', 'actions.paste', 'actions.paste_here', 'actions.paste_into_folder', 'actions.select_all',
            'actions.pin', 'actions.unpin', 'actions.apply', 'actions.create', 'actions.delete',
            'actions.download', 'actions.download_zip', 'actions.cancel', 'actions.close', 'actions.ok',
            'actions.save', 'actions.refresh', 'actions.back', 'actions.forward', 'actions.up',
            'actions.copy_url', 'actions.copied', 'actions.share_link', 'actions.customize',
            'actions.edit_metadata', 'actions.edit_image_metadata', 'actions.clear_filters',
            'actions.extract_zip', 'actions.restore', 'actions.edit_image', 'actions.import_url',
            'actions.group_edit', 'actions.group_share', 'actions.group_organize', 'actions.group_folder_content',
            'actions.ai_suggest', 'actions.ai_generating',
            'actions.delete_permanent', 'actions.empty_trash', 'actions.browse_files',
            'actions.retry', 'actions.pause', 'actions.resume', 'actions.pause_all', 'actions.cancel_all',
            'actions.confirm_cancel_upload', 'actions.keep_uploading',
            'actions.reset_default', 'actions.fullscreen', 'actions.exit_fullscreen',
            'sidebar.pinned',
            'search.target_folder', 'search.recursive_toggle', 'search.recursive_results',
            'sort.title', 'sort.field_name', 'sort.field_size', 'sort.field_date',
            'sort.field_type', 'sort.asc', 'sort.desc', 'sort.limit',
            'volumes.root_suffix',
            'inspector.title', 'inspector.empty', 'inspector.selected_count',
            'inspector.size', 'inspector.type', 'inspector.date', 'inspector.created',
            'inspector.modified', 'inspector.dimensions', 'inspector.path', 'inspector.item_count',
            'trash.title', 'trash.empty', 'trash.back',
            'trash.confirm_purge_title', 'trash.confirm_purge_message',
            'trash.confirm_empty_title', 'trash.confirm_empty_message',
            'settings.about',
            'settings.item_size', 'settings.folder_style', 'settings.folder_style_tile', 'settings.folder_style_card',
            'settings.color_scheme', 'settings.color_scheme_auto', 'settings.color_scheme_light', 'settings.color_scheme_dark',
            'shortcuts.title',
            'empty.folder', 'empty.filtered', 'empty.drop_hint', 'empty.folder_label', 'empty.subfolder_label',
            'footer.hint', 'footer.selected_multi', 'footer.selected_single', 'footer.selected_folder',
            'dropzone.title', 'dropzone.target',
            'modals.location', 'modals.target', 'modals.folder_name', 'modals.new_name',
            'modals.clone_title', 'modals.clone_name', 'modals.color_palette',
            'modals.color_custom', 'modals.color_default_label', 'modals.folder_icon_label', 'modals.folder_icon_none',
            'modals.upload_title',
            'modals.upload_drop_title', 'modals.upload_drop_hint', 'modals.upload_chunk_hint', 'modals.upload_max_size',
            'modals.upload_image_constraints',
            'modals.cancel_upload_title', 'modals.cancel_upload_single', 'modals.cancel_upload_all',
            'modals.delete_title', 'modals.delete_single', 'modals.delete_multi',
            'modals.delete_anyway', 'modals.usage_checking', 'modals.usage_none',
            'modals.usage_warn_title', 'modals.usage_block_title', 'modals.usage_block_hint',
            'modals.usage_truncated', 'modals.usage_open_record', 'modals.usage_check_failed',
            'modals.move_confirm_title', 'modals.move_confirm_message', 'modals.copy_confirm_message',
            'modals.tab_device', 'modals.tab_url', 'modals.url_placeholder', 'modals.url_hint',
            'modals.edit_image_title', 'modals.rotate_left', 'modals.rotate_right', 'modals.flip_h',
            'modals.flip_v', 'modals.crop_free', 'modals.crop_hint', 'modals.crop_reset',
            'modals.resize_width', 'modals.resize_height', 'modals.output_format', 'modals.output_quality',
            'modals.save_copy', 'modals.save_overwrite',
            'modals.file_meta_title', 'modals.meta_alt', 'modals.meta_alt_placeholder',
            'modals.meta_title', 'modals.meta_caption', 'modals.meta_tags', 'modals.meta_tags_placeholder',
            'modals.focal_point', 'modals.focal_point_hint', 'modals.focal_point_reset',
            'modals.share_title', 'modals.share_duration', 'modals.share_inline', 'modals.share_generate',
            'modals.share_expires_at', 'modals.share_copy', 'modals.share_hint',
            'modals.share_ttl_15m', 'modals.share_ttl_1h', 'modals.share_ttl_24h', 'modals.share_ttl_7d', 'modals.share_ttl_30d',
            'inspector.tags', 'inspector.alt', 'inspector.focal_point',
            'preview.truncated', 'preview.zoom_in', 'preview.zoom_out', 'preview.zoom_reset',
            'preview.prev', 'preview.next', 'preview.loading',
            'quota.label', 'quota.details_title', 'quota.click_hint', 'quota.used', 'quota.remaining',
            'quota.unlimited', 'quota.files_folders', 'quota.by_type', 'quota.top_folders',
            'quota.largest_files', 'quota.root_folder', 'quota.trash_usage', 'quota.capped_notice',
            'quota.cat_image', 'quota.cat_video', 'quota.cat_doc', 'quota.cat_archive', 'quota.cat_other', 'quota.empty_storage',
            'messages.saved', 'messages.clipboard_copied', 'messages.clipboard_cut', 'messages.upload_paused',
            'errors.network', 'errors.load_dir', 'errors.load_trash', 'errors.load_fm',
            'errors.access_denied', 'errors.action_failed', 'errors.zip_failed', 'errors.upload_failed',
            'errors.upload_network', 'errors.file_too_large', 'errors.file_type_denied',
            'errors.image_too_small', 'errors.image_too_large_dim', 'errors.image_aspect_ratio',
            'errors.preview_unsupported', 'errors.overwrite_format_mismatch', 'errors.folder_drop_truncated',
            'actions.more', 'modals.clone_default_name',
            'units.b', 'units.kb', 'units.mb', 'units.gb',
            'pagination.first', 'pagination.prev', 'pagination.next', 'pagination.last',
            'colors.blue', 'colors.sky', 'colors.cyan', 'colors.teal', 'colors.emerald',
            'colors.green', 'colors.lime', 'colors.amber', 'colors.orange', 'colors.red',
            'colors.rose', 'colors.pink', 'colors.fuchsia', 'colors.purple', 'colors.violet',
            'colors.indigo', 'colors.slate', 'colors.charcoal',
            'ui.stats', 'ui.cache_hit', 'ui.cache_disk', 'ui.file_label', 'ui.page_label',
            'ui.auto_placeholder', 'ui.new_folder_placeholder', 'ui.min_label', 'ui.max_label',
            'ui.key_enter', 'ui.key_delete',
        ];

        $translator = $this->messageTranslator ?? new MessageTranslator($this->translator);
        $map = [];
        foreach ($keys as $key) {
            $map[$key] = $translator->trans($key, [], $locale);
        }

        return $map;
    }

    /**
     * Full folder tree embedded once so sidebar collapse/expand is 0ms with zero AJAX calls.
     *
     * @return list<array{name: string, path: string, parent: string, color: ?string, icon: ?string, pinned: bool, itemCount: ?int}>
     */
    public function getInitialTreeJson(): array
    {
        if (($this->getProfileConfig()->ui['show_folder_tree'] ?? true) === false) {
            return [];
        }

        if ($this->cachedInitialTree === null) {
            $this->cachedInitialTree = $this->directoryLister->listFullTree($this->getProfileConfig(), $this->volume);
        }

        return $this->cachedInitialTree;
    }

    private function getFullInitialDirectoryListing(?string $volumeName = null): DirectoryListing
    {
        if ($this->cachedFullListing === null) {
            $this->cachedFullListing = $this->directoryLister->list(
                profile: $this->getProfileConfig(),
                volumeName: $volumeName ?? $this->volume,
                path: $this->path,
                page: 1,
                perPage: 10000,
                typeFilter: 'all',
                search: '',
                sortBy: $this->sortBy,
                sortDirection: $this->sortDirection,
            );
        }

        return $this->cachedFullListing;
    }

    /**
     * @return list<FolderItem>
     */
    public function getRootFolders(?string $volumeName = null): array
    {
        return $this->directoryLister->listSubfolders($this->getProfileConfig(), $volumeName ?? $this->volume, '');
    }

    /**
     * @return array<string, bool>
     */
    public function getCapabilities(): array
    {
        $cacheKey = $this->profile . '|' . ($this->volume ?? '') . '|' . $this->path;
        if (isset($this->capabilitiesCache[$cacheKey])) {
            return $this->capabilitiesCache[$cacheKey];
        }

        $config = $this->getProfileConfig();

        $capabilities = [
            'upload' => $this->isAllowed(FileManagerPermissions::UPLOAD),
            'remoteImport' => $this->isAllowed(FileManagerPermissions::UPLOAD) && (($config->security['allow_remote_url_import'] ?? false) === true),
            'editImage' => $this->isAllowed(FileManagerPermissions::UPLOAD),
            'createFolder' => $this->isAllowed(FileManagerPermissions::CREATE_FOLDER),
            'rename' => $this->isAllowed(FileManagerPermissions::RENAME),
            'clone' => $this->isAllowed(FileManagerPermissions::CLONE),
            'move' => $this->isAllowed(FileManagerPermissions::MOVE),
            'copy' => $this->isAllowed(FileManagerPermissions::COPY),
            'delete' => $this->isAllowed(FileManagerPermissions::DELETE),
            'usageGuard' => $this->usageGuardService?->isEnabledForProfile($config) ?? false,
            'trashRestore' => $this->isAllowed(FileManagerPermissions::TRASH_RESTORE),
            'trashPurge' => $this->isAllowed(FileManagerPermissions::TRASH_PURGE),
            'download' => $this->isAllowed(FileManagerPermissions::DOWNLOAD),
            'share' => $this->isAllowed(FileManagerPermissions::SHARE) && ($this->shareLinkService?->isAvailableForProfile($config) ?? false),
            'downloadZip' => $this->isAllowed(FileManagerPermissions::DOWNLOAD_ZIP) && $this->zipArchiveService->isAvailableForProfile($config),
            'extractZip' => $this->isAllowed(FileManagerPermissions::EXTRACT_ZIP) && $this->zipArchiveService->isExtractAvailableForProfile($config),
            'clearCache' => $this->isAllowed(FileManagerPermissions::CLEAR_CACHE)
                && ((($config->cache['enabled'] ?? true) === true) || (($config->thumbnail['enabled'] ?? true) === true)),
            'cacheEnabled' => ($config->cache['enabled'] ?? true) === true,
            'foldersFirst' => $config->foldersFirst,
            'editMetadata' => $this->isAllowed(FileManagerPermissions::EDIT_METADATA) && (($config->metadata['enabled'] ?? true) === true),
            'aiEnrich' => $this->isAllowed(FileManagerPermissions::AI_ENRICH)
                && $this->isAllowed(FileManagerPermissions::EDIT_METADATA)
                && (($config->metadata['enabled'] ?? true) === true)
                && ($this->aiEnrichmentService?->isAvailableForProfile($config) ?? false),
            'trashEnabled' => $this->trashManager->isEnabled($config)
                && ($this->isAllowed(FileManagerPermissions::DELETE)
                    || $this->isAllowed(FileManagerPermissions::TRASH_RESTORE)
                    || $this->isAllowed(FileManagerPermissions::TRASH_PURGE)),
            'showFolderTree' => ($config->ui['show_folder_tree'] ?? true) === true,
            'showFolderItemCount' => ($config->ui['show_folder_item_count'] ?? true) === true,
            'showQuota' => ($config->ui['show_quota'] ?? true) === true,
            'showQuotaDetails' => ($config->ui['show_quota'] ?? true) === true && ($config->ui['show_quota_details'] ?? true) === true,
            'showPerfBadge' => ($config->ui['show_perf_badge'] ?? false) === true,
            'showNavButtons' => ($config->ui['show_nav_buttons'] ?? true) === true,
            'showHistoryButtons' => ($config->ui['show_history_buttons'] ?? true) === true,
            'showUpButton' => ($config->ui['show_up_button'] ?? true) === true,
            'showRefreshButton' => ($config->ui['show_refresh_button'] ?? true) === true,
            'showBreadcrumbs' => ($config->ui['show_breadcrumbs'] ?? true) === true,
            'showSearch' => ($config->ui['show_search'] ?? true) === true,
            'showTypeFilter' => ($config->ui['show_type_filter'] ?? true) === true,
            'showViewToggle' => ($config->ui['show_view_toggle'] ?? true) === true,
            'showSizeSlider' => ($config->ui['show_size_slider'] ?? true) === true,
            'showPagination' => ($config->ui['show_pagination'] ?? true) === true,
            'showPerPageSelector' => ($config->ui['show_per_page_selector'] ?? true) === true,
            'showSort' => ($config->ui['show_sort'] ?? true) === true,
            'showFullscreenButton' => ($config->ui['show_fullscreen_button'] ?? true) === true,
            'showSettingsButton' => ($config->ui['show_settings_button'] ?? true) === true,
            'showFooter' => ($config->ui['show_footer'] ?? true) === true,
            'showContextNav' => ($config->ui['show_context_nav'] ?? true) === true,
            'allowContextMenu' => ($config->ui['allow_context_menu'] ?? true) === true,
            'allowPdfPreview' => ($config->ui['allow_pdf_preview'] ?? true) !== false,
            'allowVideoPreview' => ($config->ui['allow_video_preview'] ?? true) !== false,
            'allowDragMove' => ($config->ui['allow_drag_move'] ?? true) === true && $this->isAllowed(FileManagerPermissions::MOVE),
            'allowDropzoneUpload' => ($config->ui['allow_dropzone_upload'] ?? true) === true && $this->isAllowed(FileManagerPermissions::UPLOAD),
            'confirmDelete' => ($config->ui['confirm_delete'] ?? true) === true,
            'doubleClickToSelect' => ($config->ui['double_click_to_select'] ?? true) === true,
        ];
        $this->capabilitiesCache[$cacheKey] = $capabilities;

        return $capabilities;
    }

    /**
     * @return array{activeDriver: string, imagick: bool, gd: bool, webp: bool, zip: bool, php: string}
     */
    public function getDiagnostics(): array
    {
        $thumb = $this->thumbnailManager->getDiagnostics();

        return [
            ...$thumb,
            'zip' => $this->zipArchiveService->isZipArchiveAvailable(),
            'php' => PHP_VERSION,
        ];
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    public function getBreadcrumbs(): array
    {
        if ($this->path === '') {
            return [];
        }

        $crumbs = [];
        $acc = '';
        foreach (explode('/', $this->path) as $segment) {
            if ($segment === '') {
                continue;
            }
            $acc = $acc === '' ? $segment : $acc . '/' . $segment;
            $crumbs[] = ['label' => $segment, 'path' => $acc];
        }

        return $crumbs;
    }

    public function getUploadMaxSizeBytes(): int
    {
        return $this->parseIniByteSize($this->getProfileConfig()->uploadMaxSize);
    }

    public function getUploadMaxSizeLabel(): string
    {
        return $this->getProfileConfig()->uploadMaxSize;
    }

    /**
     * Computes a safe chunk size automatically bounded by PHP's upload_max_filesize and post_max_size
     * (85% headroom for multipart overhead) so large files never fail on low PHP limits.
     */
    public function getEffectiveChunkSizeBytes(): int
    {
        $rawChunk = $this->getProfileConfig()->chunkUpload['chunk_size'] ?? '2M';
        $configured = is_int($rawChunk) ? $rawChunk : $this->parseIniByteSize((string) $rawChunk);
        if ($configured <= 0) {
            $configured = 2097152;
        }

        $serverLimits = array_filter([
            $this->parseIniByteSize((string) ini_get('upload_max_filesize')),
            $this->parseIniByteSize((string) ini_get('post_max_size')),
        ], static fn (int $bytes): bool => $bytes > 0);

        if ($serverLimits === []) {
            return $configured;
        }

        $safeServerChunk = (int) floor(min($serverLimits) * 0.80);

        return max(262144, min($configured, $safeServerChunk));
    }

    public function getUploadAcceptAttribute(): string
    {
        $allow = $this->getProfileConfig()->uploadAllow;

        return $allow !== [] ? implode(',', $allow) : '';
    }

    public function getParallelUploads(): int
    {
        return max(1, min(10, (int) ($this->getProfileConfig()->chunkUpload['parallel_uploads'] ?? 3)));
    }

    public function getMaxRetries(): int
    {
        return max(0, min(10, (int) ($this->getProfileConfig()->chunkUpload['max_retries'] ?? 3)));
    }

    /**
     * @return array{enabled: bool, usedBytes: int, maxBytes: int, remainingBytes: int, percent: float, usedFormatted: string, maxFormatted: string, remainingFormatted: string}
     */
    public function getQuotaSummary(): array
    {
        $cacheKey = $this->profile . '|' . ($this->volume ?? '');
        if (isset($this->quotaSummaryCache[$cacheKey])) {
            return $this->quotaSummaryCache[$cacheKey];
        }

        $profile = $this->getProfileConfig();
        if (
            $this->quotaChecker === null
            || ($profile->ui['show_quota'] ?? true) === false
            || !$this->authorizer->isGranted($profile, FileManagerPermissions::VIEW, '', $this->volume)
        ) {
            $empty = [
                'enabled' => false,
                'usedBytes' => 0,
                'maxBytes' => 0,
                'remainingBytes' => 0,
                'percent' => 0.0,
                'usedFormatted' => '0 B',
                'maxFormatted' => '',
                'remainingFormatted' => '',
            ];
            $this->quotaSummaryCache[$cacheKey] = $empty;

            return $empty;
        }

        $summary = $this->quotaChecker->getQuotaSummary($profile, $this->volume);
        $this->quotaSummaryCache[$cacheKey] = $summary;

        return $summary;
    }

    private function parseIniByteSize(string $raw): int
    {
        return ByteSizeParser::parse($raw);
    }

    private function isAllowed(string $attribute): bool
    {
        return $this->authorizer->isPathAndAncestorsGranted($this->getProfileConfig(), $attribute, $this->path, $this->volume);
    }

    private function isVolumeViewGranted(ProfileConfig $profile, string $volumeName): bool
    {
        if ($this->volume !== null && $volumeName === $this->volume) {
            return true;
        }

        return $this->authorizer->isGranted($profile, FileManagerPermissions::VIEW, '', $volumeName);
    }
}
