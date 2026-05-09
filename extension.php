<?php

declare(strict_types=1);

final class SelectiveImageProxyExtension extends Minz_Extension {
    private const DEFAULT_PROXY_URL = 'https://images.example.com/?url=';
    private const DEFAULT_SCHEME_HTTP = true;
    private const DEFAULT_SCHEME_HTTPS = false;
    private const DEFAULT_SCHEME_DEFAULT = 'auto';
    private const DEFAULT_SCHEME_INCLUDE = false;
    private const DEFAULT_URL_ENCODE = true;

    #[\Override]
    public function init(): void {
        parent::init();
        $this->ensureDefaultConfiguration();
        $this->registerHook(Minz_HookType::EntryBeforeDisplay, [$this, 'setImageProxyHook']);
    }

    #[\Override]
    public function handleConfigureAction(): void {
        parent::handleConfigureAction();
        $this->ensureDefaultConfiguration();

        if (!Minz_Request::isPost()) {
            return;
        }

        $proxyUrl = trim(Minz_Request::paramString('proxy_url', plaintext: true) ?: '');
        if ($proxyUrl === '') {
            $proxyUrl = self::DEFAULT_PROXY_URL;
        }

        $targetFeedIdsRaw = Minz_Request::paramString('target_feed_ids', plaintext: true) ?: '';
        $targetFeedIds = $this->parseFeedIds($targetFeedIdsRaw);

        $schemeDefault = trim(Minz_Request::paramString('scheme_default', plaintext: true) ?: self::DEFAULT_SCHEME_DEFAULT);
        if (!in_array($schemeDefault, ['auto', 'http', 'https', '-'], true)) {
            $schemeDefault = self::DEFAULT_SCHEME_DEFAULT;
        }

        $this->setUserConfigurationValue('proxy_url', $proxyUrl);
        $this->setUserConfigurationValue('target_feed_ids', $targetFeedIds);
        $this->setUserConfigurationValue('scheme_http', Minz_Request::paramBoolean('scheme_http'));
        $this->setUserConfigurationValue('scheme_https', Minz_Request::paramBoolean('scheme_https'));
        $this->setUserConfigurationValue('scheme_default', $schemeDefault);
        $this->setUserConfigurationValue('scheme_include', Minz_Request::paramBoolean('scheme_include'));
        $this->setUserConfigurationValue('url_encode', Minz_Request::paramBoolean('url_encode'));
    }

    public function setImageProxyHook(FreshRSS_Entry $entry): FreshRSS_Entry {
        if (!$this->shouldProxyEntry($entry)) {
            return $entry;
        }

        $entry->_content($this->swapUris($entry->content()));
        return $entry;
    }

    private function shouldProxyEntry(FreshRSS_Entry $entry): bool {
        $targetFeedIds = $this->getConfiguredFeedIds();
        if ($targetFeedIds === []) {
            return false;
        }

        return in_array($entry->feedId(), $targetFeedIds, true);
    }

    /**
     * @return list<int>
     */
    private function getConfiguredFeedIds(): array {
        $configured = $this->getUserConfigurationArray('target_feed_ids') ?? [];
        $feedIds = [];

        foreach ($configured as $feedId) {
            if (!is_numeric($feedId)) {
                continue;
            }

            $feedId = (int)$feedId;
            if ($feedId > 0) {
                $feedIds[] = $feedId;
            }
        }

        $feedIds = array_values(array_unique($feedIds));
        sort($feedIds);
        return $feedIds;
    }

    /**
     * @return list<int>
     */
    private function parseFeedIds(string $rawFeedIds): array {
        $chunks = preg_split('/[\s,;]+/', trim($rawFeedIds)) ?: [];
        $feedIds = [];

        foreach ($chunks as $chunk) {
            if ($chunk === '' || !ctype_digit($chunk)) {
                continue;
            }

            $feedId = (int)$chunk;
            if ($feedId > 0) {
                $feedIds[] = $feedId;
            }
        }

        $feedIds = array_values(array_unique($feedIds));
        sort($feedIds);
        return $feedIds;
    }

    private function ensureDefaultConfiguration(): void {
        if ($this->getUserConfigurationString('proxy_url') === null) {
            $this->setUserConfigurationValue('proxy_url', self::DEFAULT_PROXY_URL);
        }
        if ($this->getUserConfigurationArray('target_feed_ids') === null) {
            $this->setUserConfigurationValue('target_feed_ids', []);
        }
        if ($this->getUserConfigurationBool('scheme_http') === null) {
            $this->setUserConfigurationValue('scheme_http', self::DEFAULT_SCHEME_HTTP);
        }
        if ($this->getUserConfigurationBool('scheme_https') === null) {
            $this->setUserConfigurationValue('scheme_https', self::DEFAULT_SCHEME_HTTPS);
        }
        if ($this->getUserConfigurationString('scheme_default') === null) {
            $this->setUserConfigurationValue('scheme_default', self::DEFAULT_SCHEME_DEFAULT);
        }
        if ($this->getUserConfigurationBool('scheme_include') === null) {
            $this->setUserConfigurationValue('scheme_include', self::DEFAULT_SCHEME_INCLUDE);
        }
        if ($this->getUserConfigurationBool('url_encode') === null) {
            $this->setUserConfigurationValue('url_encode', self::DEFAULT_URL_ENCODE);
        }
    }

    private function getProxyImageUri(string $url): string {
        $parsedUrl = parse_url($url);
        $scheme = is_array($parsedUrl) ? strtolower($parsedUrl['scheme'] ?? '') : '';

        if ($scheme === 'http') {
            if (!$this->getUserConfigurationBool('scheme_http')) {
                return $url;
            }
            if (!$this->getUserConfigurationBool('scheme_include')) {
                $url = substr($url, 7);
            }
        } elseif ($scheme === 'https') {
            if (!$this->getUserConfigurationBool('scheme_https')) {
                return $url;
            }
            if (!$this->getUserConfigurationBool('scheme_include')) {
                $url = substr($url, 8);
            }
        } elseif ($scheme === '') {
            if (!str_starts_with($url, '//')) {
                return $url;
            }

            $schemeDefault = $this->getUserConfigurationString('scheme_default') ?? self::DEFAULT_SCHEME_DEFAULT;
            if ($schemeDefault === 'auto') {
                if ($this->getUserConfigurationBool('scheme_include')) {
                    $url = ((is_string($_SERVER['HTTPS'] ?? null) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https:' : 'http:') . $url;
                } else {
                    $url = substr($url, 2);
                }
            } elseif ($schemeDefault === 'http' || $schemeDefault === 'https') {
                if ($this->getUserConfigurationBool('scheme_include')) {
                    $url = $schemeDefault . ':' . $url;
                } else {
                    $url = substr($url, 2);
                }
            } else {
                return $url;
            }
        } else {
            return $url;
        }

        if ($this->getUserConfigurationBool('url_encode')) {
            $url = rawurlencode($url);
        }

        return ($this->getUserConfigurationString('proxy_url') ?? self::DEFAULT_PROXY_URL) . $url;
    }

    private function getProxySrcSet(string $srcSet): string {
        $proxiedCandidates = [];
        foreach ($this->parseSrcSetCandidates($srcSet) as [$url, $descriptor]) {
            $proxiedCandidates[] = $this->getProxyImageUri($url) . $descriptor;
        }

        return $proxiedCandidates === [] ? $srcSet : implode(', ', $proxiedCandidates);
    }

    /**
     * @return list<array{string, string}>
     */
    private function parseSrcSetCandidates(string $srcSet): array {
        $candidates = [];
        $length = strlen($srcSet);
        $position = 0;

        while ($position < $length) {
            while ($position < $length && (ctype_space($srcSet[$position]) || $srcSet[$position] === ',')) {
                $position++;
            }

            if ($position >= $length) {
                break;
            }

            $urlStart = $position;
            $isDataUri = strncasecmp(substr($srcSet, $position, 5), 'data:', 5) === 0;
            while (
                $position < $length
                && !ctype_space($srcSet[$position])
                && ($isDataUri || $srcSet[$position] !== ',')
            ) {
                $position++;
            }

            $url = substr($srcSet, $urlStart, $position - $urlStart);
            $descriptorStart = $position;
            while ($position < $length && $srcSet[$position] !== ',') {
                $position++;
            }

            $candidates[] = [$url, substr($srcSet, $descriptorStart, $position - $descriptorStart)];

            if ($position < $length && $srcSet[$position] === ',') {
                $position++;
            }
        }

        return $candidates;
    }

    private function swapUris(string $content): string {
        if ($content === '') {
            return $content;
        }

        $doc = new DOMDocument();
        $previousLibxmlErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $content);
            if ($loaded === false) {
                return $content;
            }

            $images = $doc->getElementsByTagName('img');
            foreach ($images as $img) {
                if (!($img instanceof DOMElement)) {
                    continue;
                }

                if ($img->hasAttribute('src')) {
                    $src = $img->getAttribute('src');
                    $newSrc = $this->getProxyImageUri($src);
                    $img->setAttribute('data-xextension-imageproxy-original-src', $src);
                    $img->setAttribute('src', $newSrc);
                }

                if ($img->hasAttribute('srcset')) {
                    $srcSet = $img->getAttribute('srcset');
                    $img->setAttribute('data-xextension-imageproxy-original-srcset', $srcSet);
                    $img->setAttribute('srcset', $this->getProxySrcSet($srcSet));
                }
            }

            $body = $doc->getElementsByTagName('body')->item(0);
            $output = $body === null ? false : $doc->saveHTML($body);
            if ($output === false) {
                return $content;
            }

            return preg_replace('/^<body>|<\/body>$/', '', $output) ?? $content;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlErrors);
        }
    }
}
