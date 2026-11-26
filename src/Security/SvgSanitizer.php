<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use DOMAttr;
use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use DOMText;

/**
 * Strips XSS, XXE, Billion-Laughs, SMIL animation, CSS @import, and SSRF vectors from SVG files.
 */
final readonly class SvgSanitizer
{
    public const int MAX_SVG_BYTES = 5_242_880;

    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    private const string XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';
    private const int MAX_USE_ELEMENTS = 100;
    private const int MAX_USE_DEPTH = 4;
    private const int MAX_USE_EXPANDED_NODES = 1000;
    private const int MAX_USE_ANALYSIS_STEPS = 5000;
    private const int MAX_USE_ID_CANDIDATES = 8;

    private const array ALLOWED_SVG_TAGS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse',
        'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath', 'title', 'desc',
        'metadata', 'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath',
        'mask', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix',
        'fecomponenttransfer', 'fefuncr', 'fefuncg', 'fefuncb', 'fefunca',
        'fecomposite', 'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap',
        'fedistantlight', 'fedropshadow', 'feflood', 'femerge', 'femergenode',
        'femorphology', 'fepointlight', 'fespecularlighting', 'fespotlight',
        'fetile', 'feturbulence', 'image', 'style', 'marker', 'switch', 'view', 'a',
    ];

    private const array URI_ATTRIBUTES = [
        'href', 'xlink:href', 'src', 'action', 'formaction',
        'srcset', 'imagesrcset', 'ping', 'poster', 'srcdoc',
    ];

    private const array URL_PRESENTATION_ATTRIBUTES = [
        'filter', 'fill', 'stroke', 'clip-path', 'mask', 'cursor',
        'marker', 'marker-start', 'marker-mid', 'marker-end', 'background',
    ];

    public function sanitize(string $svgContent): string
    {
        if (
            trim($svgContent) === ''
            || strlen($svgContent) > self::MAX_SVG_BYTES
            || str_contains($svgContent, "\0")
            || !mb_check_encoding($svgContent, 'UTF-8')
        ) {
            return '';
        }

        // Reject DOCTYPE and ENTITY declarations outright to eliminate XXE and Billion-Laughs DoS.
        if (preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $svgContent) === 1) {
            return '';
        }

        $previousErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($svgContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$loaded || $dom->doctype !== null || $dom->documentElement === null) {
            return '';
        }

        if (strtolower($dom->documentElement->localName ?? '') !== 'svg' || !$this->isAllowedSvgNamespace($dom->documentElement)) {
            return '';
        }

        $this->sanitizeNode($dom->documentElement);
        $this->sanitizeUseReferences($dom->documentElement);

        $result = $dom->saveXML($dom->documentElement);

        return is_string($result) ? $result : '';
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $this->sanitizeAttributes($node);
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($this->shouldRemoveChild($child)) {
                $node->removeChild($child);
                continue;
            }

            $this->sanitizeNode($child);
        }
    }

    private function shouldRemoveChild(DOMNode $child): bool
    {
        if (
            $child instanceof DOMProcessingInstruction
            || $child instanceof DOMComment
            || $child instanceof DOMCdataSection
        ) {
            return true;
        }

        if (!$child instanceof DOMElement) {
            return false;
        }

        if (!$this->isAllowedSvgNamespace($child)) {
            return true;
        }

        $tag = strtolower($child->localName ?? '');
        if (!in_array($tag, self::ALLOWED_SVG_TAGS, true)) {
            return true;
        }

        if ($tag === 'style') {
            return !$this->sanitizeStyleElement($child);
        }

        return false;
    }

    private function isAllowedSvgNamespace(DOMElement $element): bool
    {
        $ns = $element->namespaceURI;

        return $ns === null || $ns === '' || strtolower($ns) === self::SVG_NAMESPACE;
    }

    private function sanitizeStyleElement(DOMElement $styleNode): bool
    {
        $toRemove = [];
        foreach ($styleNode->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return false;
            }
            if (!$child instanceof DOMText) {
                $toRemove[] = $child;
            }
        }

        foreach ($toRemove as $node) {
            $styleNode->removeChild($node);
        }

        $css = $styleNode->textContent;
        if ($this->hasDangerousCss($css)) {
            return false;
        }

        // Convert valid CDATA/split-text children into a single plain DOMText node because shouldRemoveChild() drops DOMCdataSection.
        while ($styleNode->firstChild !== null) {
            $styleNode->removeChild($styleNode->firstChild);
        }
        $doc = $styleNode->ownerDocument;
        if ($doc !== null && $css !== '') {
            $styleNode->appendChild($doc->createTextNode($css));
        }

        return true;
    }

    private function hasDangerousCss(string $css): bool
    {
        $rawDecoded = $this->decodeCssEscapes($css);
        if ($rawDecoded === null || $this->matchesDangerousCssTokens($css) || $this->matchesDangerousCssTokens($rawDecoded)) {
            return true;
        }

        $withoutComments = $this->stripCssCommentsPreservingStrings($css);
        if ($withoutComments === null || str_contains($withoutComments, '/*') || str_contains($withoutComments, '*/')) {
            return true;
        }

        $rawDecodedWithoutComments = $this->stripCssCommentsPreservingStrings($rawDecoded);
        if ($rawDecodedWithoutComments === null || str_contains($rawDecodedWithoutComments, '/*') || str_contains($rawDecodedWithoutComments, '*/')) {
            return true;
        }

        $decoded = $this->decodeCssEscapes($withoutComments);
        if ($decoded === null) {
            return true;
        }
        $decodedAgain = preg_replace('/\/\*.*?\*\//s', '', $decoded);
        if (!is_string($decodedAgain)) {
            return true;
        }

        return $this->matchesDangerousCssTokens($withoutComments)
            || $this->matchesDangerousCssTokens($rawDecodedWithoutComments)
            || $this->matchesDangerousCssTokens($decoded)
            || $this->matchesDangerousCssTokens($decodedAgain);
    }

    private const array FRAGMENT_URI_ELEMENTS = [
        'use',
        'image',
        'feimage',
        'textpath',
        'mpath',
        'lineargradient',
        'radialgradient',
        'pattern',
        'filter',
        'clippath',
        'mask',
        'cursor',
        'tref',
    ];

    private function stripCssCommentsPreservingStrings(string $css): ?string
    {
        $stripped = preg_replace_callback(
            '/"[^"\\\\\r\n\f]*(?:\\\\.[^"\\\\\r\n\f]*)*"|\'[^\'\\\\\r\n\f]*(?:\\\\.[^\'\\\\\r\n\f]*)*\'|\\\\.|\/\*[\s\S]*?\*\//',
            static function (array $m): string {
                return str_starts_with($m[0], '/*') ? ' ' : $m[0];
            },
            $css,
        );

        return is_string($stripped) ? $stripped : null;
    }

    private function matchesDangerousCssTokens(string $css): bool
    {
        $stripped = preg_replace('/[\x00-\x20]+/', '', $css);
        if (!is_string($stripped)) {
            return true;
        }
        $compact = strtolower($stripped);

        if (preg_match('/(@import|javascript:|vbscript:|expression\(|-moz-binding|behavior:|data:|(?:-webkit-)?image-set\(|(?:-webkit-)?cross-fade\(|element\()/i', $compact) !== 0) {
            return true;
        }

        // Allow only strict local SVG fragment references url(#safe-id); block all external/data/protocol URLs, unclosed comment tricks, CSS src(), and image().
        return preg_match('/(?:url|src|image)\((?!(["\']?)#[a-z0-9_.:-]+\1\))/i', $compact) !== 0;
    }

    private function decodeCssEscapes(string $css): ?string
    {
        $unwrapped = preg_replace('/\\\\(?:\r\n|[\r\n\f])/', '', $css);
        if (!is_string($unwrapped)) {
            return null;
        }

        $decoded = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})[\x09\x0A\x0C\x0D\x20]?/',
            static function (array $m): string {
                $codePoint = hexdec($m[1]);
                if ($codePoint <= 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
                    return '';
                }

                $decodedChar = mb_chr((int) $codePoint, 'UTF-8');

                return is_string($decodedChar) ? $decodedChar : '';
            },
            $unwrapped,
        );
        if (!is_string($decoded)) {
            return null;
        }

        $final = preg_replace('/\\\\([^\r\n\f0-9a-fA-F])/', '$1', $decoded);

        return is_string($final) ? $final : null;
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        /** @var list<DOMAttr> $toRemove */
        $toRemove = [];
        $elementLocalName = strtolower($element->localName ?? $element->nodeName);

        foreach ($element->attributes as $attr) {
            if (!$attr instanceof DOMAttr) {
                continue;
            }
            $nodeName = strtolower($attr->nodeName);
            $localName = strtolower($attr->localName ?? $attr->nodeName);
            $value = trim($attr->nodeValue ?? '');

            if (
                $this->isForbiddenAttributeName($nodeName, $localName)
                || $this->isDangerousAttributeValue($elementLocalName, $nodeName, $localName, $value)
            ) {
                $toRemove[] = $attr;
            }
        }

        foreach ($toRemove as $attrNode) {
            $element->removeAttributeNode($attrNode);
        }
    }

    private function isForbiddenAttributeName(string $nodeName, string $localName): bool
    {
        if (str_starts_with($nodeName, 'on') || str_starts_with($localName, 'on')) {
            return true;
        }

        return $nodeName === 'xml:base' || $localName === 'base';
    }

    /**
     * Prevents exponential SVG <use> Billion-Laughs DoS by capping total <use> count,
     * removing cyclic or overly deep <use href="#id"> reference chains (across both `id`
     * and `xml:id`, duplicate/shadowed IDs, and dual `href`/`xlink:href`), and enforcing
     * a strict memoized expanded-node budget across the document.
     */
    private function sanitizeUseReferences(DOMElement $root): void
    {
        /** @var array<string, list<DOMElement>> $elementsById */
        $elementsById = [];
        /** @var list<DOMElement> $useNodes */
        $useNodes = [];
        $this->indexIdsAndUseNodes($root, $elementsById, $useNodes);

        $kept = 0;
        $totalExpandedNodes = 0;
        $analysisSteps = 0;
        /** @var array<string, int> $memo */
        $memo = [];

        foreach ($useNodes as $useEl) {
            if (!$this->isConnectedToRoot($useEl, $root)) {
                continue;
            }
            if ($analysisSteps >= self::MAX_USE_ANALYSIS_STEPS) {
                $useEl->parentNode?->removeChild($useEl);
                continue;
            }
            ++$kept;
            if ($kept > self::MAX_USE_ELEMENTS) {
                $useEl->parentNode?->removeChild($useEl);
                $memo = [];
                continue;
            }

            $targetIds = $this->extractUseTargetIds($useEl);
            if ($targetIds === []) {
                continue;
            }

            $useExpansion = $this->evaluateUseTargetsExpansion($root, $useEl, $targetIds, $elementsById, $memo, $analysisSteps);
            if ($useExpansion === null || ($totalExpandedNodes + $useExpansion) > self::MAX_USE_EXPANDED_NODES) {
                $useEl->parentNode?->removeChild($useEl);
                $memo = [];
                continue;
            }

            $totalExpandedNodes += $useExpansion;
        }
    }

    private function isConnectedToRoot(DOMNode $node, DOMElement $root): bool
    {
        $cursor = $node;
        while ($cursor !== null) {
            if ($cursor === $root) {
                return true;
            }
            $cursor = $cursor->parentNode;
        }

        return false;
    }

    /**
     * @param list<string>                    $targetIds
     * @param array<string, list<DOMElement>> $elementsById
     * @param array<string, int>              $memo
     */
    private function evaluateUseTargetsExpansion(
        DOMElement $root,
        DOMElement $useEl,
        array $targetIds,
        array $elementsById,
        array &$memo,
        int &$steps,
    ): ?int {
        $baseVisited = ['@obj:' . spl_object_id($useEl) => true];
        foreach ($this->extractElementIds($useEl) as $selfId) {
            $baseVisited[$selfId] = true;
        }

        $maxExpansion = 0;
        foreach ($targetIds as $targetId) {
            if (++$steps > self::MAX_USE_ANALYSIS_STEPS || isset($baseVisited[$targetId])) {
                return null;
            }
            $candidates = $elementsById[$targetId] ?? [];
            if (count($candidates) > self::MAX_USE_ID_CANDIDATES) {
                return null;
            }
            foreach ($candidates as $candidate) {
                if (++$steps > self::MAX_USE_ANALYSIS_STEPS) {
                    return null;
                }
                if (!$this->isConnectedToRoot($candidate, $root)) {
                    continue;
                }
                $objKey = '@obj:' . spl_object_id($candidate);
                if (isset($baseVisited[$objKey])) {
                    return null;
                }
                $visited = $baseVisited;
                $visited[$targetId] = true;
                $visited[$objKey] = true;
                foreach ($this->extractElementIds($candidate) as $candId) {
                    $visited[$candId] = true;
                }
                $cost = $this->measureUseExpansion($root, $candidate, $elementsById, $visited, 1, $memo, $steps);
                if ($cost === null) {
                    return null;
                }
                if ($cost > $maxExpansion) {
                    $maxExpansion = $cost;
                }
            }
        }

        return $maxExpansion;
    }

    /**
     * @param array<string, list<DOMElement>> $elementsById
     * @param list<DOMElement>                $useNodes
     */
    private function indexIdsAndUseNodes(DOMNode $node, array &$elementsById, array &$useNodes): void
    {
        if ($node instanceof DOMElement) {
            foreach ($this->extractElementIds($node) as $id) {
                $elementsById[$id][] = $node;
            }
            if (strtolower($node->localName ?? $node->nodeName) === 'use') {
                $useNodes[] = $node;
            }
        }

        foreach ($node->childNodes as $child) {
            $this->indexIdsAndUseNodes($child, $elementsById, $useNodes);
        }
    }

    /**
     * Collects exact raw `id` / `xml:id` values (and trimmed variants without overwriting exact matches)
     * so duplicate, `xml:id`-shadowed, or whitespace-padded decoy elements can never mask real targets.
     *
     * @return list<string>
     */
    private function extractElementIds(DOMElement $element): array
    {
        $ids = [];
        foreach ([
            $element->getAttribute('id'),
            $element->getAttributeNS(self::XML_NAMESPACE, 'id'),
            $element->getAttribute('xml:id'),
        ] as $rawCandidate) {
            if ($rawCandidate !== '' && !in_array($rawCandidate, $ids, true)) {
                $ids[] = $rawCandidate;
            }
            $trimmed = trim($rawCandidate);
            if ($trimmed !== '' && $trimmed !== $rawCandidate && !in_array($trimmed, $ids, true)) {
                $ids[] = $trimmed;
            }
        }

        return $ids;
    }

    /**
     * Returns ALL local fragment target IDs referenced by any href / xlink:href / *:href attribute
     * so conflicting dual attributes (e.g. href="#safe" xlink:href="#cycle") cannot bypass guards.
     *
     * @return list<string>
     */
    private function extractUseTargetIds(DOMElement $useEl): array
    {
        $targets = [];
        foreach (['href', 'xlink:href'] as $attrName) {
            $raw = trim($useEl->getAttribute($attrName));
            if (str_starts_with($raw, '#') && strlen($raw) > 1) {
                $id = substr($raw, 1);
                if (!in_array($id, $targets, true)) {
                    $targets[] = $id;
                }
            }
        }

        foreach ($useEl->attributes as $attr) {
            if ($attr instanceof DOMAttr && strtolower($attr->localName ?? '') === 'href') {
                $raw = trim($attr->nodeValue ?? '');
                if (str_starts_with($raw, '#') && strlen($raw) > 1) {
                    $id = substr($raw, 1);
                    if (!in_array($id, $targets, true)) {
                        $targets[] = $id;
                    }
                }
            }
        }

        return $targets;
    }

    /**
     * Memoized DAG/cycle expansion analyzer. Returns the expanded node count for `$node` at `$depth`,
     * or `null` if a cycle, depth overflow, or MAX_USE_EXPANDED_NODES budget breach is encountered.
     *
     * @param array<string, list<DOMElement>> $elementsById
     * @param array<string, bool>             $visited
     * @param array<string, int>              $memo
     */
    private function measureUseExpansion(
        DOMElement $root,
        DOMNode $node,
        array $elementsById,
        array $visited,
        int $depth,
        array &$memo,
        int &$steps,
    ): ?int {
        if ($depth > self::MAX_USE_DEPTH || ++$steps > self::MAX_USE_ANALYSIS_STEPS) {
            return null;
        }

        $memoKey = $node instanceof DOMElement ? (spl_object_id($node) . ':' . $depth) : null;
        if ($memoKey !== null && isset($memo[$memoKey])) {
            return $memo[$memoKey];
        }

        $count = $node instanceof DOMElement ? 1 : 0;

        if ($node instanceof DOMElement && strtolower($node->localName ?? $node->nodeName) === 'use') {
            $nestedCost = $this->measureNestedUseElement($root, $node, $elementsById, $visited, $depth, $memo, $steps);
            if ($nestedCost === null) {
                return null;
            }
            $count += $nestedCost;
            if ($count > self::MAX_USE_EXPANDED_NODES) {
                return null;
            }
        }

        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $childCost = $this->measureUseExpansion($root, $child, $elementsById, $visited, $depth, $memo, $steps);
            if ($childCost === null) {
                return null;
            }
            $count += $childCost;
            if ($count > self::MAX_USE_EXPANDED_NODES) {
                return null;
            }
        }

        if ($memoKey !== null) {
            $memo[$memoKey] = $count;
        }

        return $count;
    }

    /**
     * @param array<string, list<DOMElement>> $elementsById
     * @param array<string, bool>             $visited
     * @param array<string, int>              $memo
     */
    private function measureNestedUseElement(
        DOMElement $root,
        DOMElement $useNode,
        array $elementsById,
        array $visited,
        int $depth,
        array &$memo,
        int &$steps,
    ): ?int {
        $maxNested = 0;
        foreach ($this->extractUseTargetIds($useNode) as $targetId) {
            if (++$steps > self::MAX_USE_ANALYSIS_STEPS || isset($visited[$targetId])) {
                return null;
            }
            $candidates = $elementsById[$targetId] ?? [];
            if (count($candidates) > self::MAX_USE_ID_CANDIDATES) {
                return null;
            }
            foreach ($candidates as $candidate) {
                if (++$steps > self::MAX_USE_ANALYSIS_STEPS) {
                    return null;
                }
                if (!$this->isConnectedToRoot($candidate, $root)) {
                    continue;
                }
                $objKey = '@obj:' . spl_object_id($candidate);
                if (isset($visited[$objKey])) {
                    return null;
                }
                $nextVisited = $visited;
                $nextVisited[$targetId] = true;
                $nextVisited[$objKey] = true;
                foreach ($this->extractElementIds($candidate) as $candId) {
                    $nextVisited[$candId] = true;
                }
                $nestedCost = $this->measureUseExpansion($root, $candidate, $elementsById, $nextVisited, $depth + 1, $memo, $steps);
                if ($nestedCost === null) {
                    return null;
                }
                if ($nestedCost > $maxNested) {
                    $maxNested = $nestedCost;
                }
            }
        }

        return $maxNested;
    }

    private function isDangerousAttributeValue(string $elementLocalName, string $nodeName, string $localName, string $value): bool
    {
        $normalized = strtolower(preg_replace('/[\x00-\x20]+/', '', $value) ?? $value);

        if (
            str_contains($normalized, 'javascript:')
            || str_contains($normalized, 'vbscript:')
            || str_contains($normalized, 'data:text/html')
            || str_contains($normalized, 'data:image/svg')
        ) {
            return true;
        }

        if ($localName === 'style' && $this->hasDangerousCss($value)) {
            return true;
        }

        if (in_array($localName, self::URL_PRESENTATION_ATTRIBUTES, true) && $this->hasDangerousCss($value)) {
            return true;
        }

        if (in_array($nodeName, self::URI_ATTRIBUTES, true) || in_array($localName, self::URI_ATTRIBUTES, true)) {
            if ($normalized === '') {
                return false;
            }

            // Strict element-scoped allowlist: local SVG fragment references only on referencing elements,
            // and raster base64 data URIs strictly on <image> elements (never on <a> or arbitrary nodes).
            if (str_starts_with($normalized, '#')) {
                return !in_array($elementLocalName, self::FRAGMENT_URI_ELEMENTS, true)
                    || preg_match('/^#[a-z0-9_.:-]+$/i', $normalized) !== 1;
            }

            if (
                $elementLocalName === 'image'
                && preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,[a-z0-9+/=]+$#i', $normalized) === 1
            ) {
                return false;
            }

            return true;
        }

        return false;
    }
}
