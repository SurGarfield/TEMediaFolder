<?php

namespace TypechoPlugin\TEMediaFolder\Core;

class HttpClient
{
    public static function request($url, $method = 'GET', $headers = [], $body = null, $options = [])
    {
        if (!function_exists('curl_init')) {
            throw new \Exception('cURL extension not available');
        }

        $method = strtoupper((string)$method);
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 30;
        $connectTimeout = isset($options['connect_timeout']) ? (int)$options['connect_timeout'] : 10;
        $verifySsl = isset($options['verify_ssl']) ? (bool)$options['verify_ssl'] : false;
        $followLocation = isset($options['follow_location']) ? (bool)$options['follow_location'] : false;
        $maxRedirs = isset($options['max_redirs']) ? (int)$options['max_redirs'] : 10;

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => is_array($headers) ? $headers : [],
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $followLocation,
            CURLOPT_MAXREDIRS => max(0, $maxRedirs),
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];

        if ($body !== null && $method !== 'GET') {
            if ($method === 'POST') {
                $curlOptions[CURLOPT_POST] = true;
            }
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $curlOptions);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($raw === false) {
            return [
                'status' => $httpCode,
                'headers' => '',
                'body' => '',
                'error' => $curlError !== '' ? $curlError : 'Unknown network error'
            ];
        }

        $rawHeaders = $headerSize > 0 ? substr($raw, 0, $headerSize) : '';
        $responseBody = $headerSize > 0 ? substr($raw, $headerSize) : $raw;

        return [
            'status' => $httpCode,
            'headers' => $rawHeaders,
            'body' => $responseBody,
            'error' => $curlError !== '' ? $curlError : null
        ];
    }
}
