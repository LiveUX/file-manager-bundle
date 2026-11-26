<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Service;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\FileManagerBundle;
use LiveUX\FileManagerBundle\Security\FileManagerAuthorizer;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileSecurityGuard;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Util\ByteSizeParser;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/**
 * SSRF-hardened Remote URL File Importer.
 * Blocks private/loopback/link-local/CGNAT/metadata IPv4 & IPv6 ranges (including IPv4-mapped IPv6,
 * NAT64, userinfo credentials, non-HTTP(S) ports, and trailing DNS dots) on initial URL and redirects,
 * and pins resolved public IPs via cURL CURLOPT_RESOLVE to prevent DNS rebinding (TOCTOU).
 */
final readonly class RemoteUrlImporter
{
    private const int MAX_REDIRECTS = 2;
    private const int TIMEOUT_SECONDS = 12;
    private const array ALLOWED_PORTS = [80, 443];

    private FileManagerAuthorizer $authorizer;

    public function __construct(
        private UploadManager $uploadManager,
        private ?VolumeRegistry $volumeRegistry = null,
        private PathNormalizer $pathNormalizer = new PathNormalizer(),
        ?AuthorizationCheckerInterface $authChecker = null,
        private FileSecurityGuard $fileSecurityGuard = new FileSecurityGuard(),
    ) {
        $this->authorizer = new FileManagerAuthorizer($authChecker);
    }

    /**
     * @return array{completed: bool, path: string, name: string, size: int}
     */
    public function import(
        ProfileConfig $profile,
        string $url,
        string $targetDirectory = '',
        ?string $volumeName = null,
    ): array {
        if (($profile->security['allow_remote_url_import'] ?? false) !== true) {
            throw new AccessDeniedException('Remote URL import is disabled for this profile.', 'errors.remote_import_disabled');
        }

        $cleanDir = $this->pathNormalizer->normalize($targetDirectory);
        $this->fileSecurityGuard->assertAccessiblePath($cleanDir, $profile);
        $resolvedVolName = $this->volumeRegistry !== null
            ? $this->volumeRegistry->getVolumeForProfile($profile, $volumeName)->name
            : $volumeName;
        $this->assertImportAuthorized($profile, $cleanDir, $resolvedVolName);
        $this->uploadManager->assertCanImportInto($profile, $cleanDir, $resolvedVolName);

        $cleanUrl = trim($url);
        $resolved = $this->validateAndResolveUrl($cleanUrl);

        $maxBytes = $this->parseByteSize($profile->uploadMaxSize);
        $tempFile = tempnam(sys_get_temp_dir(), 'liveux_fm_url_');
        if ($tempFile === false) {
            throw new UploadValidationException('Unable to allocate temporary file.', 'errors.temp_file_failed');
        }

        try {
            [$finalUrl, $responseHeaders] = $this->downloadWithSsrfGuard($resolved['url'], $resolved, $tempFile, $maxBytes > 0 ? $maxBytes : 10485760);
            $filename = $this->extractFilename($finalUrl, $responseHeaders, $tempFile);

            return $this->uploadManager->handleImportedTempFile($profile, $tempFile, $filename, $cleanDir, $resolvedVolName);
        } finally {
            @unlink($tempFile);
        }
    }

    public function assertSafeUrl(string $url): void
    {
        $this->validateAndResolveUrl($url);
    }

    private function assertImportAuthorized(ProfileConfig $profile, string $cleanDir, ?string $volumeName): void
    {
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::VIEW, $cleanDir, $volumeName);
        $this->authorizer->assertPathAndAncestorsGranted($profile, FileManagerPermissions::UPLOAD, $cleanDir, $volumeName);
    }

    /**
     * @return array{url: string, host: string, port: int, ips: list<string>}
     */
    private function validateAndResolveUrl(string $url): array
    {
        $normalizedUrl = $this->normalizeUrlForHttp($url);
        if (!filter_var($normalizedUrl, FILTER_VALIDATE_URL)) {
            throw new UploadValidationException('Invalid remote URL.', 'errors.remote_url_invalid');
        }

        $parts = parse_url($normalizedUrl);
        if (!is_array($parts)) {
            throw new UploadValidationException('Malformed remote URL.', 'errors.remote_url_invalid');
        }

        $this->assertSafeUrlParts($parts);

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new UploadValidationException('Unable to resolve remote host.', 'errors.remote_host_unresolvable');
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new UploadValidationException('Access to private or reserved IP ranges is blocked (SSRF protection).', 'errors.remote_ssrf_blocked');
            }
        }

        return ['url' => $normalizedUrl, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    private function normalizeUrlForHttp(string $rawUrl): string
    {
        $trimmed = trim($rawUrl);
        // Reject backslashes & control/BiDi chars before parse_url() to prevent PHP vs libcurl URL parser differentials.
        if ($trimmed === '' || str_contains($trimmed, '\\') || UnicodeHelper::hasForbiddenControls($trimmed)) {
            throw new UploadValidationException('Invalid remote URL.', 'errors.remote_url_invalid');
        }

        $withoutFragment = explode('#', $trimmed, 2)[0];
        $parts = parse_url($withoutFragment);
        if (!is_array($parts) || !isset($parts['scheme'])) {
            throw new UploadValidationException('Invalid remote URL.', 'errors.remote_url_invalid');
        }

        $this->assertSafePreCanonicalParts($parts);

        $rawHost = (string) ($parts['host'] ?? '');
        $asciiHost = UnicodeHelper::canonicalizeHostToAscii($rawHost);
        if ($asciiHost === null) {
            throw new UploadValidationException('Invalid remote URL.', 'errors.remote_url_invalid');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $portPart = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? self::encodeNonAsciiUriChars((string) $parts['path']) : '';
        $query = isset($parts['query']) ? '?' . self::encodeNonAsciiUriChars((string) $parts['query']) : '';

        return sprintf('%s://%s%s%s%s', $scheme, $asciiHost, $portPart, $path, $query);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function assertSafePreCanonicalParts(array $parts): void
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new UploadValidationException('Only http:// and https:// URLs are allowed.', 'errors.remote_scheme_only_http');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UploadValidationException('Credentials in remote URLs are blocked.', 'errors.remote_credentials_blocked');
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], self::ALLOWED_PORTS, true)) {
            throw new UploadValidationException('Only standard HTTP (80) and HTTPS (443) ports are allowed.', 'errors.remote_port_blocked');
        }

        $rawHost = trim((string) ($parts['host'] ?? ''));
        if (str_ends_with(UnicodeHelper::foldDotEquivalents($rawHost), '.')) {
            throw new UploadValidationException('Trailing-dot hostnames are blocked (SSRF protection).', 'errors.remote_ssrf_blocked');
        }
    }

    private static function encodeNonAsciiUriChars(string $segment): string
    {
        return preg_replace_callback(
            '/[^\x21-\x7E]/',
            static fn (array $m): string => rawurlencode($m[0]),
            $segment,
        ) ?? $segment;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function assertSafeUrlParts(array $parts): void
    {
        $this->assertSafePreCanonicalParts($parts);

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (
            $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa')
            || str_ends_with($host, '.lan')
        ) {
            throw new UploadValidationException('Access to local/internal hosts is blocked.', 'errors.remote_local_blocked');
        }

        $unbracketed = trim($host, '[]');
        if (self::isObfuscatedNumericHost($unbracketed)) {
            throw new UploadValidationException('Obfuscated numeric/hex IP hosts are blocked.', 'errors.remote_ssrf_blocked');
        }
    }

    public static function isSafeRemoteHostLiteral(string $rawHost): bool
    {
        $host = UnicodeHelper::canonicalizeHostToAscii($rawHost);
        if ($host === null) {
            return false;
        }
        if (
            $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa')
            || str_ends_with($host, '.lan')
        ) {
            return false;
        }

        $unbracketed = trim($host, '[]');
        if (self::isObfuscatedNumericHost($unbracketed)) {
            return false;
        }

        if (str_starts_with($host, '[') || filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIpAddress($unbracketed);
        }

        return true;
    }

    public static function isPublicIpAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return self::isPublicIpv4($ip, $packed);
        }

        if (strlen($packed) === 16) {
            return self::isPublicIpv6($ip, $packed);
        }

        return false;
    }

    private static function isObfuscatedNumericHost(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return false;
        }

        $parts = explode('.', $host);
        $allNumericLike = true;
        $hasObfuscatedPart = false;

        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^(?:0x[0-9a-f]+|\d+)$/i', $part) !== 1) {
                $allNumericLike = false;
                break;
            }
            if (str_starts_with(strtolower($part), '0x') || (strlen($part) > 1 && str_starts_with($part, '0'))) {
                $hasObfuscatedPart = true;
            }
        }

        if (!$allNumericLike) {
            return false;
        }

        return count($parts) !== 4 || $hasObfuscatedPart;
    }

    private function isPublicIp(string $ip): bool
    {
        return self::isPublicIpAddress($ip);
    }

    private static function isPublicIpv4(string $ip, string $packed): bool
    {
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        $third = ord($packed[2]);

        // Block 0.0.0.0/8, 100.64.0.0/10 (CGNAT), 192.0.0.0/24, 192.0.2.0/24 (TEST-NET-1),
        // 198.18.0.0/15 (benchmarking), 198.51.100.0/24 (TEST-NET-2), 203.0.113.0/24 (TEST-NET-3), 224.0.0.0/4 (multicast/reserved).
        if (
            $first === 0
            || $first >= 224
            || ($first === 100 && $second >= 64 && $second <= 127)
            || ($first === 198 && ($second === 18 || $second === 19))
            || ($first === 192 && $second === 0 && ($third === 0 || $third === 2))
            || ($first === 198 && $second === 51 && $third === 100)
            || ($first === 203 && $second === 0 && $third === 113)
        ) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function isPublicIpv6(string $ip, string $packed): bool
    {
        // Reject unspecified (::), loopback (::1), deprecated SIIT (::ffff:0:0:0/96), and local-use NAT64 (64:ff9b:1::/48).
        if (
            $packed === str_repeat("\x00", 16)
            || $packed === str_repeat("\x00", 15) . "\x01"
            || str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x00\x00")
            || str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01")
        ) {
            return false;
        }

        // Unwrap IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96), and well-known NAT64 (64:ff9b::/96)
        $prefix12 = substr($packed, 0, 12);
        $isMapped = $prefix12 === str_repeat("\x00", 10) . "\xff\xff";
        $isCompat = $prefix12 === str_repeat("\x00", 12);
        $isNat64 = $prefix12 === "\x00\x64\xff\x9b" . str_repeat("\x00", 8);
        if ($isMapped || $isCompat || $isNat64) {
            $embeddedV4 = inet_ntop(substr($packed, 12, 4));

            return is_string($embeddedV4) && self::isPublicIpv4($embeddedV4, substr($packed, 12, 4));
        }

        $firstByte = ord($packed[0]);
        $secondByte = ord($packed[1]);
        $thirdByte = ord($packed[2]);

        // Strict IANA Global Unicast allowlist (2000::/3): automatically rejects fe00::/9, 0100::/8, 0200::/7, 4000::/3, 5f00::/16, fc00::/7, ff00::/8, etc.
        if (($firstByte & 0xE0) !== 0x20) {
            return false;
        }

        // Exclude IANA special-purpose blocks inside 2000::/3:
        // - 2001::/23 (IETF protocol assignments: Teredo, Benchmarking, ORCHID/ORCHIDv2, Port Control, etc.)
        // - 2001:db8::/32 & 3fff::/20 (Documentation)
        // - 2002::/16 (6to4)
        if (
            ($firstByte === 0x20 && $secondByte === 0x01 && ($thirdByte & 0xFE) === 0x00)
            || ($firstByte === 0x20 && $secondByte === 0x02)
            || str_starts_with($packed, "\x20\x01\x0d\xb8")
            || ($firstByte === 0x3F && $secondByte === 0xFF && ($thirdByte & 0xF0) === 0x00)
        ) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        $stripped = trim($host, '[]');
        if (filter_var($stripped, FILTER_VALIDATE_IP) !== false) {
            return [$stripped];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (is_string($record['ipv6'] ?? null)) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param array{url: string, host: string, port: int, ips: list<string>} $initialResolved
     *
     * @return array{0: string, 1: list<string>}
     */
    private function downloadWithSsrfGuard(string $url, array $initialResolved, string $destPath, int $maxBytes): array
    {
        $currentUrl = $url;
        $resolved = $initialResolved;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            // Without cURL the host would be re-resolved by PHP streams (DNS rebinding / TOCTOU), so the importer refuses to run.
            if (!function_exists('curl_init')) {
                throw new UploadValidationException('Remote URL import requires the PHP cURL extension (DNS-pinned SSRF protection).', 'errors.remote_import_requires_curl');
            }

            [$status, $headers] = $this->fetchSingleHopWithCurl($currentUrl, $resolved, $destPath, $maxBytes);

            if ($status >= 300 && $status < 400) {
                $locations = $this->findAllHeaders($headers, 'location');
                if (count($locations) !== 1 || $hop === self::MAX_REDIRECTS) {
                    throw new UploadValidationException('Too many or invalid redirects.', 'errors.remote_redirects_invalid');
                }
                $nextUrl = $this->resolveRedirectUrl($currentUrl, $locations[0]);
                $resolved = $this->validateAndResolveUrl($nextUrl);
                $currentUrl = $resolved['url'];
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new UploadValidationException(sprintf('Remote server returned HTTP %d.', $status), 'errors.remote_http_error', ['%status%' => $status]);
            }

            return [$currentUrl, $headers];
        }

        throw new UploadValidationException('Unable to complete remote download.', 'errors.remote_fetch_failed');
    }

    /**
     * Performs one HTTP(S) hop with DNS IP pinning via CURLOPT_RESOLVE to eliminate DNS rebinding TOCTOU.
     *
     * @param array{host: string, port: int, ips: list<string>} $resolved
     *
     * @return array{0: int, 1: list<string>}
     */
    private function fetchSingleHopWithCurl(string $url, array $resolved, string $destPath, int $maxBytes): array
    {
        $out = fopen($destPath, 'wb');
        if ($out === false) {
            throw new UploadValidationException('Unable to write temporary file.', 'errors.temp_file_failed');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($out);
            throw new UploadValidationException('Unable to fetch remote URL.', 'errors.remote_fetch_failed');
        }

        $headers = [];
        $headerBytes = 0;
        $totalBytes = 0;
        $sizeExceeded = false;
        $pinnedIp = $resolved['ips'][0];
        $resolveHost = trim($resolved['host'], '[]');
        $isIpLiteral = filter_var($resolveHost, FILTER_VALIDATE_IP) !== false;

        $curlOptions = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => sprintf('LiveUX-FileManager/%s', FileManagerBundle::VERSION),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $headerLine) use (&$headers, &$headerBytes): int {
                $len = strlen($headerLine);
                $headerBytes += $len;
                if ($headerBytes > 65536 || count($headers) >= 200) {
                    return 0;
                }
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $headers[] = $trimmed;
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $data) use ($out, $maxBytes, &$totalBytes, &$sizeExceeded): int {
                $len = strlen($data);
                $totalBytes += $len;
                if ($totalBytes > $maxBytes) {
                    $sizeExceeded = true;

                    return 0;
                }

                $written = fwrite($out, $data);

                return $written === false ? 0 : $written;
            },
        ];

        if (!$isIpLiteral) {
            $resolveEntry = sprintf('%s:%d:%s', $resolveHost, $resolved['port'], str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp);
            $curlOptions[CURLOPT_RESOLVE] = [$resolveEntry];
        }

        curl_setopt_array($ch, $curlOptions);

        try {
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
            fclose($out);
        }

        if ($sizeExceeded) {
            throw new UploadValidationException('Remote file exceeds maximum allowed size.', 'errors.remote_too_large');
        }

        if ($ok === false) {
            throw new UploadValidationException('Unable to fetch remote URL.', 'errors.remote_fetch_failed');
        }

        return [$status, $headers];
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $trimmed = trim($location);
        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
            throw new UploadValidationException('Unsafe redirect target blocked.', 'errors.remote_redirects_invalid');
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        if (!str_starts_with($trimmed, '/')) {
            throw new UploadValidationException('Relative redirect target blocked.', 'errors.remote_redirects_invalid');
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new UploadValidationException('Unable to resolve redirect base URL.', 'errors.remote_redirects_invalid');
        }

        $portSuffix = isset($base['port']) ? ':' . (int) $base['port'] : '';

        return sprintf('%s://%s%s%s', $base['scheme'], $base['host'], $portSuffix, $trimmed);
    }

    /**
     * @param list<mixed> $headers
     *
     * @return list<string>
     */
    private function findAllHeaders(array $headers, string $name): array
    {
        $prefix = strtolower($name) . ':';
        $found = [];
        foreach ($headers as $line) {
            if (is_string($line) && str_starts_with(strtolower($line), $prefix)) {
                $val = trim(substr($line, strlen($prefix)));
                if ($val !== '') {
                    $found[] = $val;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<mixed> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        $all = $this->findAllHeaders($headers, $name);

        return $all[0] ?? null;
    }

    /**
     * @param list<string> $headers
     */
    private function extractFilename(string $url, array $headers = [], string $tempFile = ''): string
    {
        $mimeTypes = MimeTypes::getDefault();
        $detectedMime = ($tempFile !== '' && is_file($tempFile)) ? ($mimeTypes->guessMimeType($tempFile) ?? '') : '';
        $headerCt = $this->findHeader($headers, 'content-type');
        $cleanHeaderMime = $headerCt !== null ? strtolower(trim(explode(';', $headerCt, 2)[0])) : '';
        if ($detectedMime === '' || $detectedMime === 'application/octet-stream') {
            if ($cleanHeaderMime !== '') {
                $detectedMime = $cleanHeaderMime;
            }
        }
        if (
            in_array($detectedMime, ['text/plain', 'text/xml', 'application/xml', 'image/svg'], true)
            && ($cleanHeaderMime === 'image/svg+xml' || ($tempFile !== '' && FileSecurityGuard::looksLikeSvgDocument($tempFile)))
        ) {
            $detectedMime = 'image/svg+xml';
        }

        $dispBase = '';
        $disp = $this->findHeader($headers, 'content-disposition');
        if ($disp !== null) {
            $dispBase = $this->extractDispositionFilename($disp);
            $dispCandidate = $this->resolveSafeExtractedCandidate($dispBase, $detectedMime, $mimeTypes);
            if ($dispCandidate !== null) {
                return $dispCandidate;
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $decodedUrlPath = rawurldecode($path);
        $utf8Path = mb_check_encoding($decodedUrlPath, 'UTF-8') ? UnicodeHelper::toNfc($decodedUrlPath) : mb_scrub($decodedUrlPath, 'UTF-8');
        $base = UnicodeHelper::trimEdgeInvisiblesAndDots(PathNormalizer::basenameUtf8($utf8Path));
        $urlCandidate = $this->resolveSafeExtractedCandidate($base, $detectedMime, $mimeTypes);
        if ($urlCandidate !== null) {
            return $urlCandidate;
        }

        $guessedExts = $detectedMime !== '' ? $mimeTypes->getExtensions($detectedMime) : [];
        $resolvedExt = 'bin';
        foreach ($guessedExts as $candExt) {
            $lowerExt = strtolower($candExt);
            if (!in_array($lowerExt, FileSecurityGuard::BLOCKED_EXTENSIONS, true)) {
                $resolvedExt = $lowerExt;
                break;
            }
        }
        if ($resolvedExt === 'jpeg') {
            $resolvedExt = 'jpg';
        }

        $rawStemSource = $dispBase !== '' ? $dispBase : $base;
        $rawStem = $rawStemSource !== '' ? UnicodeHelper::stripIgnorableFillers(UnicodeHelper::toNfc(PathNormalizer::stemUtf8($rawStemSource))) : '';
        $safeStem = $rawStem !== '' ? (preg_replace('/[^\p{L}\p{M}\p{N}\x{200C}_-]+/u', '-', $rawStem) ?? '') : '';
        $safeStem = preg_replace('/(?<=^|-)[\p{M}]+/u', '', $safeStem) ?? $safeStem;
        $safeStem = UnicodeHelper::trimEdgeInvisiblesAndDots(trim($safeStem, '-_.'));
        $safeStem = UnicodeHelper::truncateStemBytes($safeStem, 180);
        if ($safeStem === '' || UnicodeHelper::visibleSkeleton($safeStem) === '') {
            $safeStem = 'imported-' . date('Ymd-His');
        }

        $candidateName = $safeStem . '.' . $resolvedExt;
        try {
            $this->fileSecurityGuard->assertSafeFilename($candidateName, strictOsChars: false);
        } catch (Throwable) {
            $candidateName = 'imported-' . $candidateName;
        }

        return $candidateName;
    }

    private function extractDispositionFilename(string $disposition): string
    {
        // RFC 6266 §4.3: `filename*` MUST take precedence over legacy `filename` when both are present.
        if (preg_match('/(?:^|;)\s*filename\*\s*=\s*(?:"?UTF-8\'[a-zA-Z0-9-]*\'([^";\s\r\n]+)"?)/i', $disposition, $mStar) === 1) {
            $decoded = rawurldecode($mStar[1]);
            if (mb_check_encoding($decoded, 'UTF-8') && !UnicodeHelper::hasForbiddenControls($decoded)) {
                $base = UnicodeHelper::trimEdgeInvisiblesAndDots(PathNormalizer::basenameUtf8(UnicodeHelper::trimSpaces(UnicodeHelper::toNfc($decoded))));
                if ($base !== '') {
                    return $base;
                }
            }
        }

        if (preg_match('/(?:^|;)\s*filename\s*=\s*(?:"([^"\r\n]+)"|([^;\s\r\n]+))/i', $disposition, $mPlain) === 1) {
            $raw = $mPlain[1] !== '' ? $mPlain[1] : ($mPlain[2] ?? '');
            if (mb_check_encoding($raw, 'UTF-8') && !UnicodeHelper::hasForbiddenControls($raw)) {
                return UnicodeHelper::trimEdgeInvisiblesAndDots(PathNormalizer::basenameUtf8(UnicodeHelper::trimSpaces(UnicodeHelper::toNfc($raw))));
            }
        }

        return '';
    }

    private function resolveSafeExtractedCandidate(string $basename, string $detectedMime, MimeTypes $mimeTypes): ?string
    {
        if ($basename === '') {
            return null;
        }

        $ext = PathNormalizer::extensionUtf8($basename);
        if ($ext === '' || in_array($ext, FileSecurityGuard::BLOCKED_EXTENSIONS, true) || $mimeTypes->getMimeTypes($ext) === []) {
            return null;
        }

        $reconciled = $this->reconcileRasterExtension($basename, $ext, $detectedMime);

        try {
            $this->fileSecurityGuard->assertSafeFilename($reconciled, strictOsChars: false);
            $this->fileSecurityGuard->assertMimeMatchesExtension($reconciled, $detectedMime);
        } catch (Throwable) {
            return null;
        }

        return $reconciled;
    }

    private function reconcileRasterExtension(string $basename, string $ext, string $detectedMime): string
    {
        $rasterMap = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            'image/gif' => ['gif'],
            'image/avif' => ['avif'],
        ];
        $allRasterExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
        $cleanMime = strtolower(trim(explode(';', $detectedMime, 2)[0]));

        if (!isset($rasterMap[$cleanMime]) || !in_array($ext, $allRasterExts, true)) {
            return $basename;
        }

        if (in_array($ext, $rasterMap[$cleanMime], true)) {
            return $basename;
        }

        $stem = PathNormalizer::stemUtf8($basename);

        return ($stem !== '' ? $stem : 'image') . '.' . $rasterMap[$cleanMime][0];
    }

    private function parseByteSize(string $size): int
    {
        return ByteSizeParser::parse($size);
    }
}
