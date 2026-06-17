<?php
class UrlRewriter {
    public static function rewrite($url) {
        if (empty($url)) return $url;

        $host = '';
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return $url; 
        }

        $originalDomain = $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $originalDomain .= ':' . $parsedUrl['port'];
        }

        // Target FOTO host based on Doogle Host (HTTP_HOST)
        $targetDomain = null;

        if (strpos($host, '192.168.1.17') !== false) {
            $targetDomain = '192.168.1.17:81';
        } elseif (strpos($host, '10.147.17.60') !== false) {
            $targetDomain = '10.147.17.60:81';
        } elseif (strpos($host, 'gkr.my.id') !== false || strpos($host, 'gkr.my.id') !== false) {
            $targetDomain = 'foto.gkr.my.id';
        }

        if ($targetDomain) {
            // Replace the original domain with the target domain
            $knownDomains = ['192.168.1.17:81', '10.147.17.60:81', 'foto.gkr.my.id'];
            if (in_array($originalDomain, $knownDomains)) {
                if ($targetDomain === 'foto.gkr.my.id') {
                    // Force HTTPS for public domain to avoid Mixed Content Error
                    $url = str_replace('http://' . $originalDomain, 'https://' . $targetDomain, $url);
                } else {
                    $url = str_replace($originalDomain, $targetDomain, $url);
                }
            }
        }

        return $url;
    }

    public static function normalize($url) {
        if (empty($url)) return $url;

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return $url; 
        }

        $originalDomain = $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $originalDomain .= ':' . $parsedUrl['port'];
        }

        // Canonical Database Domain (Standar penyimpanan di database)
        $canonicalDomain = '192.168.1.17:81';
        $knownDomains = ['192.168.1.17:81', '10.147.17.60:81', 'foto.gkr.my.id'];

        if (in_array($originalDomain, $knownDomains) && $originalDomain !== $canonicalDomain) {
            // Revert back to canonical domain and force http protocol
            $url = str_replace(['https://' . $originalDomain, 'http://' . $originalDomain], 'http://' . $canonicalDomain, $url);
        }

        return $url;
    }
}
?>
