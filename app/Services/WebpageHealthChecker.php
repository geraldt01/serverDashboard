<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Performs a basic, HTML-level "frontend" health check against a webpage:
 * page reachability, broken <img>/<video> sources, and presence of required
 * elements (by #id, .class, or tag name). This does not execute JavaScript
 * or render the page, so purely visual/layout issues are out of scope.
 */
class WebpageHealthChecker
{
    private const MAX_IMAGES = 25;
    private const MAX_VIDEOS = 10;
    private const REQUEST_TIMEOUT = 10;
    private const RESOURCE_TIMEOUT = 6;

    /**
     * @param array<int, string> $requiredElements
     * @return array{status:string,http_status:?int,response_time_ms:?int,broken_images:array,broken_videos:array,missing_elements:array,error:?string}
     */
    public function check(string $url, array $requiredElements = []): array
    {
        $result = [
            'status' => 'broken',
            'http_status' => null,
            'response_time_ms' => null,
            'broken_images' => [],
            'broken_videos' => [],
            'missing_elements' => [],
            'error' => null,
        ];

        if (! $this->isUrlSafeToFetch($url)) {
            $result['error'] = 'URL is not allowed: it must be a public http/https address (no internal, loopback, or link-local hosts).';

            return $result;
        }

        $client = $this->makeClient();
        $started = microtime(true);

        try {
            $response = $client->request('GET', $url);
        } catch (GuzzleException $exception) {
            $result['error'] = 'Could not load the page: ' . $this->shortMessage($exception);

            return $result;
        }

        $result['response_time_ms'] = (int) round((microtime(true) - $started) * 1000);
        $result['http_status'] = $response->getStatusCode();

        if ($response->getStatusCode() >= 400) {
            $result['error'] = "Page responded with HTTP {$response->getStatusCode()}.";

            return $result;
        }

        $html = (string) $response->getBody();

        if (trim($html) === '') {
            $result['error'] = 'Page returned an empty response body.';

            return $result;
        }

        $xpath = new DOMXPath($this->parseHtml($html));

        $result['broken_images'] = $this->checkImages($xpath, $url, $client);
        $result['broken_videos'] = $this->checkVideos($xpath, $url, $client);
        $result['missing_elements'] = $this->checkRequiredElements($xpath, $requiredElements);

        $hasIssues = $result['broken_images'] !== [] || $result['broken_videos'] !== [] || $result['missing_elements'] !== [];
        $result['status'] = $hasIssues ? 'warning' : 'healthy';

        return $result;
    }

    private function makeClient(): Client
    {
        return new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => 5,
            'http_errors' => false,
            // Capped and left unvalidated per-hop: acceptable since this feature is admin-only and self-service.
            'allow_redirects' => ['max' => 5, 'strict' => true],
            'headers' => ['User-Agent' => 'ServerDashboard-FrontendChecker/1.0'],
        ]);
    }

    /**
     * @return array<int, array{src:string,reason:string}>
     */
    private function checkImages(DOMXPath $xpath, string $baseUrl, Client $client): array
    {
        $broken = [];
        $checked = 0;

        foreach ($xpath->query('//img[@src]') as $img) {
            if ($checked >= self::MAX_IMAGES) {
                break;
            }

            $src = trim($img->getAttribute('src'));
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $resolved = $this->resolveRelativeUrl($src, $baseUrl);
            if ($resolved === null) {
                continue;
            }

            $checked++;
            $error = $this->probeResource($resolved, $client);
            if ($error !== null) {
                $broken[] = ['src' => $src, 'reason' => $error];
            }
        }

        return $broken;
    }

    /**
     * @return array<int, array{src:string,reason:string}>
     */
    private function checkVideos(DOMXPath $xpath, string $baseUrl, Client $client): array
    {
        $broken = [];
        $checked = 0;
        $candidates = [];

        foreach ($xpath->query('//video') as $video) {
            if ($video->hasAttribute('src')) {
                $candidates[] = $video->getAttribute('src');
            }

            foreach ($xpath->query('.//source[@src]', $video) as $source) {
                $candidates[] = $source->getAttribute('src');
            }
        }

        foreach (array_unique($candidates) as $src) {
            if ($checked >= self::MAX_VIDEOS) {
                break;
            }

            $src = trim($src);
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $resolved = $this->resolveRelativeUrl($src, $baseUrl);
            if ($resolved === null) {
                continue;
            }

            $checked++;
            $error = $this->probeResource($resolved, $client);
            if ($error !== null) {
                $broken[] = ['src' => $src, 'reason' => $error];
            }
        }

        return $broken;
    }

    /**
     * @param array<int, string> $requiredElements
     * @return array<int, string>
     */
    private function checkRequiredElements(DOMXPath $xpath, array $requiredElements): array
    {
        $missing = [];

        foreach ($requiredElements as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }

            $query = $this->selectorToXPath($selector);
            if ($query === null) {
                $missing[] = "{$selector} (unsupported selector; use #id, .class, or a tag name)";
                continue;
            }

            if ($xpath->query($query)->length === 0) {
                $missing[] = $selector;
            }
        }

        return $missing;
    }

    private function selectorToXPath(string $selector): ?string
    {
        if (str_starts_with($selector, '#')) {
            $id = substr($selector, 1);

            return $id === '' ? null : '//*[@id=' . $this->xpathLiteral($id) . ']';
        }

        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);

            return $class === '' ? null : '//*[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $class . ' ') . ')]';
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
            return '//' . strtolower($selector);
        }

        return null;
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        if (! str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        $parts = explode('"', $value);

        return 'concat("' . implode('", \'"\', "', $parts) . '")';
    }

    /**
     * HEAD the resource (falling back to a ranged GET if HEAD isn't supported) to see if it loads.
     */
    private function probeResource(string $url, Client $client): ?string
    {
        if (! $this->isUrlSafeToFetch($url)) {
            return 'blocked (internal/unsafe address)';
        }

        try {
            $response = $client->request('HEAD', $url, ['timeout' => self::RESOURCE_TIMEOUT]);
            $status = $response->getStatusCode();

            if ($status === 405 || $status === 501) {
                $response = $client->request('GET', $url, [
                    'timeout' => self::RESOURCE_TIMEOUT,
                    'headers' => ['Range' => 'bytes=0-0'],
                ]);
                $status = $response->getStatusCode();
            }

            return $status >= 400 ? "HTTP {$status}" : null;
        } catch (GuzzleException $exception) {
            return 'unreachable (' . $this->shortMessage($exception) . ')';
        }
    }

    private function resolveRelativeUrl(string $src, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }

        $base = parse_url($baseUrl);
        if (! $base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'];
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $authority = "{$scheme}://{$host}{$port}";

        if (str_starts_with($src, '//')) {
            return "{$scheme}:{$src}";
        }

        if (str_starts_with($src, '/')) {
            return $authority . $src;
        }

        $basePath = $base['path'] ?? '/';
        $lastSlash = strrpos($basePath, '/');
        $baseDir = $lastSlash === false ? '/' : rtrim(substr($basePath, 0, $lastSlash + 1), '/') . '/';

        return $authority . $baseDir . $src;
    }

    /**
     * Reject anything that isn't a plain public http/https host, blocking SSRF against
     * internal infrastructure, loopback addresses, and the cloud metadata service.
     */
    private function isUrlSafeToFetch(string $url): bool
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $ips = $this->resolveIps($parts['host']);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP)) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            $viaSystemResolver = gethostbyname($host);
            if ($viaSystemResolver !== $host && filter_var($viaSystemResolver, FILTER_VALIDATE_IP)) {
                $ips[] = $viaSystemResolver;
            }
        }

        return $ips;
    }

    private function parseHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($internalErrors);

        return $dom;
    }

    private function shortMessage(GuzzleException $exception): string
    {
        $message = $exception->getMessage();

        return mb_strlen($message) > 160 ? mb_substr($message, 0, 160) . '…' : $message;
    }
}
