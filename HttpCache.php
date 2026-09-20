<?php

/**
 * @fileoverview HTTP Cache Helper - Implements ETag and Cache-Control headers
 * @author roBrowser Legacy Team
 * @version 1.0.0
 * 
 * Provides HTTP caching functionality to reduce bandwidth and improve
 * client-side performance through proper cache headers.
 */

class HttpCache
{
    /**
     * Default max-age for Cache-Control (30 days in seconds)
     */
    const DEFAULT_MAX_AGE = 2592000;

    /**
     * Max-age for immutable game assets (1 year)
     */
    const IMMUTABLE_MAX_AGE = 31536000;

    /**
     * File extensions considered immutable (rarely change)
     */
    private static $immutableExtensions = array(
        'grf', 'gat', 'gnd', 'rsw', 'rsm', 'spr', 'act', 'pal',
        'wav', 'mp3', 'ogg', 'bmp', 'jpg', 'jpeg', 'png', 'gif', 'tga'
    );

    /**
     * Interface texture / data-table extensions the ROenglishRE overlay hand-edits
     * in place (PLAN-010) -- must always revalidate, never pinned to a long-lived
     * cache.
     */
    private static $overlayProneExtensions = array(
        'bmp', 'jpg', 'jpeg', 'png', 'gif', 'txt'
    );

    /**
     * File extensions that should not be cached
     */
    private static $noCacheExtensions = array(
        'php', 'ini'
    );


    /**
     * Generate ETag from file content
     *
     * @param string $content File content
     * @param string $path File path (optional, for additional uniqueness)
     * @return string ETag value (quoted)
     */
    public static function generateETag($content, $path = '')
    {
        // Use MD5 for speed, combined with content length for extra uniqueness
        $hash = md5($content);
        $size = strlen($content);
        return '"' . substr($hash, 0, 16) . '-' . dechex($size) . '"';
    }


    /**
     * Check if client has valid cached version (conditional request)
     *
     * @param string $etag Current ETag value
     * @return bool True if client cache is valid (should return 304)
     */
    public static function checkConditionalRequest($etag)
    {
        // Check If-None-Match header
        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH']);
            
            // Handle multiple ETags (comma-separated)
            $clientEtags = array_map('trim', explode(',', $clientEtag));
            
            foreach ($clientEtags as $tag) {
                // Remove weak validator prefix if present
                $tag = preg_replace('/^W\//', '', $tag);
                
                if ($tag === $etag || $tag === '*') {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Send 304 Not Modified response
     */
    public static function sendNotModified()
    {
        header('HTTP/1.1 304 Not Modified', true, 304);
        header('Status: 304 Not Modified', true, 304);
        exit();
    }


    /**
     * Set all cache headers for a file
     *
     * @param string $content File content
     * @param string $path File path
     * @param string $ext File extension
     * @return string Generated ETag
     */
    public static function setCacheHeaders($content, $path, $ext)
    {
        $etag = self::generateETag($content, $path);
        $ext = strtolower($ext);

        // Check if this extension should not be cached
        if (in_array($ext, self::$noCacheExtensions)) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            return $etag;
        }

        // Set ETag
        header('ETag: ' . $etag);

        // exro (PLAN-010) made EVERY extension revalidate-always to stop the
        // ROenglishRE loose overlay (data/*.txt, interface BMPs, brand art -- edited
        // in place on the host) from sticking in a year-long browser cache. That fix
        // was too broad: it also caught the GRF-sourced binary assets (sprites,
        // models, maps, sounds) that make up the vast majority of a map load and
        // never change at runtime, forcing a full round-trip (cheap 304, but still a
        // round-trip to a remote VM) per file on every load -- the actual cause of
        // slow map loads. Split it back out: interface/overlay-prone extensions stay
        // revalidate-always; everything else (compiled game data) is truly immutable.
        if (in_array($ext, self::$overlayProneExtensions)) {
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
        } else {
            header('Cache-Control: public, max-age=' . self::IMMUTABLE_MAX_AGE . ', immutable');
        }

        // Set Last-Modified to now (since we don't track file modification times in GRF)
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        // Vary header for proper caching with different encodings
        header('Vary: Accept-Encoding');

        return $etag;
    }


    /**
     * Process cache headers and check for conditional request
     * Returns true if 304 response was sent (caller should exit)
     *
     * @param string $content File content
     * @param string $path File path
     * @param string $ext File extension
     * @return bool True if 304 was sent
     */
    public static function processCache($content, $path, $ext)
    {
        $etag = self::setCacheHeaders($content, $path, $ext);

        // Check if client has valid cache
        if (self::checkConditionalRequest($etag)) {
            self::sendNotModified();
            return true; // Never reached, but for clarity
        }

        return false;
    }


    /**
     * Get content type for file extension
     *
     * @param string $ext File extension
     * @return string MIME type
     */
    public static function getContentType($ext)
    {
        $ext = strtolower($ext);
        
        $mimeTypes = array(
            // Images
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'tga'  => 'image/x-tga',
            
            // Audio
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            
            // Text/Data
            'xml'  => 'application/xml',
            'txt'  => 'text/plain',
            'lua'  => 'text/x-lua',
            'lub'  => 'application/octet-stream',
            
            // RO specific
            'gat'  => 'application/x-ro-gat',
            'gnd'  => 'application/x-ro-gnd',
            'rsw'  => 'application/x-ro-rsw',
            'rsm'  => 'application/x-ro-rsm',
            'spr'  => 'application/x-ro-spr',
            'act'  => 'application/x-ro-act',
            'pal'  => 'application/x-ro-pal',
            'str'  => 'application/x-ro-str',
        );

        return isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';
    }
}
