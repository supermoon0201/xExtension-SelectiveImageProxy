<?php

declare(strict_types=1);

final class SelectiveImageProxyExtension extends Minz_Extension {
    private const DEFAULT_PROXY_URL = 'https://images.example.com/?url=';
    private const DEFAULT_SCHEME_HTTP = true;
    private const DEFAULT_SCHEME_HTTPS = false;
    private const DEFAULT_SCHEME_DEFAULT = 'auto';
    private const DEFAULT_SCHEME_INCLUDE = false;
    private const DEFAULT_URL_ENCODE = true;
    private const CONFIG_PROXY_URL = 'selective_image_proxy_url';
    private const CONFIG_TARGET_FEED_IDS = 'selective_image_proxy_target_feed_ids';
    private const CONFIG_SCHEME_HTTP = 'selective_image_proxy_scheme_http';
    private const CONFIG_SCHEME_HTTPS = 'selective_image_proxy_scheme_https';
    private const CONFIG_SCHEME_DEFAULT = 'selective_image_proxy_scheme_default';
    private const CONFIG_SCHEME_INCLUDE = 'selective_image_proxy_scheme_include';
    private const CONFIG_URL_ENCODE = 'selective_image_proxy_url_encode';

    public function init() {
        parent::init();
        $this->registerHook('entry_before_display', [$this, 'setImageProxyHook']);
    }

    public function handleConfigureAction() {
        parent::handleConfigureAction();

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

        FreshRSS_Context::userConf()->_attribute(self::CONFIG_PROXY_URL, $proxyUrl);
        FreshRSS_Context::userConf()->_attribute(self::CONFIG_TARGET_FEED_IDS, $targetFeedIds);
        FreshRSS_Context::userConf()->_attribute(self::CONFIG_SCHEME_HTTP, Minz_Request::paramBoolean('scheme_http'));
        FreshRSS_Context::userConf()->_attribute(self::CONFIG_SCHEME_HTTPS, Minz_Request::paramBoolean('scheme_https'));
        FreshRSS_Context::userConf()->_attribute(self::CONFIG_SCHEME_DEFAULT, $schemeDefault);
        FreshRSS_Context::userConf()->_attribute(self::CONFIG_SCHEME_INCLUDE, Minz_Request::paramBoolean('scheme_include'));
        FreshRSS_Context::userConf()->_attribute(self::CONFIG_URL_ENCODE, Minz_Request::paramBoolean('url_encode'));
        FreshRSS_Context::userConf()->save();
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
        $configured = FreshRSS_Context::userConf()->attributeArray(self::CONFIG_TARGET_FEED_IDS) ?? [];
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

    private function getConfiguredBool(string $key, bool $default): bool {
        return FreshRSS_Context::userConf()->attributeBool($key) ?? $default;
    }

    private function getConfiguredString(string $key, string $default): string {
        return FreshRSS_Context::userConf()->attributeString($key) ?? $default;
    }

    private function getProxyImageUri(string $url): string {
        $parsedUrl = parse_url($url);
        $scheme = is_array($parsedUrl) ? strtolower($parsedUrl['scheme'] ?? '') : '';

        if ($scheme === 'http') {
            if (!$this->getConfiguredBool(self::CONFIG_SCHEME_HTTP, self::DEFAULT_SCHEME_HTTP)) {
                return $url;
            }
            if (!$this->getConfiguredBool(self::CONFIG_SCHEME_INCLUDE, self::DEFAULT_SCHEME_INCLUDE)) {
                $url = substr($url, 7);
            }
        } elseif ($scheme === 'https') {
            if (!$this->getConfiguredBool(self::CONFIG_SCHEME_HTTPS, self::DEFAULT_SCHEME_HTTPS)) {
                return $url;
            }
            if (!$this->getConfiguredBool(self::CONFIG_SCHEME_INCLUDE, self::DEFAULT_SCHEME_INCLUDE)) {
                $url = substr($url, 8);
            }
        } elseif ($scheme === '') {
            if (!str_starts_with($url, '//')) {
                return $url;
            }

            $schemeDefault = $this->getConfiguredString(self::CONFIG_SCHEME_DEFAULT, self::DEFAULT_SCHEME_DEFAULT);
            if ($schemeDefault === 'auto') {
                if ($this->getConfiguredBool(self::CONFIG_SCHEME_INCLUDE, self::DEFAULT_SCHEME_INCLUDE)) {
                    $url = ((is_string($_SERVER['HTTPS'] ?? null) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https:' : 'http:') . $url;
                } else {
                    $url = substr($url, 2);
                }
            } elseif ($schemeDefault === 'http' || $schemeDefault === 'https') {
                if ($this->getConfiguredBool(self::CONFIG_SCHEME_INCLUDE, self::DEFAULT_SCHEME_INCLUDE)) {
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

        if ($this->getConfiguredBool(self::CONFIG_URL_ENCODE, self::DEFAULT_URL_ENCODE)) {
            $url = rawurlencode($url);
        }

        return $this->getConfiguredString(self::CONFIG_PROXY_URL, self::DEFAULT_PROXY_URL) . $url;
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
