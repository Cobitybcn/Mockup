<?php
declare(strict_types=1);

final class MetaGraphTransportException extends RuntimeException {}

final class MetaGraphClient
{
    public function __construct(
        private readonly string $version,
        private readonly ?Closure $transport = null
    ) {}

    public function request(
        string $method,
        string $path,
        array $fields,
        string $accessToken,
        string $appSecret
    ): array {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported Meta Graph method.');
        }
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Meta Graph paths must begin with a slash.');
        }
        if ($accessToken === '') {
            throw new RuntimeException('Meta access token is unavailable.');
        }
        $version = preg_replace('/[^v0-9.]/', '', $this->version) ?: 'v25.0';
        $fields['access_token'] = $accessToken;
        if ($appSecret !== '') {
            $fields['appsecret_proof'] = hash_hmac('sha256', $accessToken, $appSecret);
        }
        $url = 'https://graph.facebook.com/' . $version . $path;

        if ($this->transport instanceof Closure) {
            $response = ($this->transport)($method, $url, $fields);
            if (!is_array($response)) {
                throw new RuntimeException('The Meta test transport returned an invalid response.');
            }
            return $response;
        }

        $curl = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($method === 'GET') {
            $options[CURLOPT_URL] = $url . '?' . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        } else {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($curlError !== '' || $status === 0 || $status >= 500 || ($status >= 200 && $status < 300 && !is_array($decoded))) {
            throw new MetaGraphTransportException('Meta Graph API did not return a conclusive response. Verify the destination before retrying.');
        }
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? trim((string)($decoded['error']['message'] ?? '')) : '';
            $code = is_array($decoded) ? preg_replace('/[^A-Za-z0-9._-]/', '', (string)($decoded['error']['code'] ?? '')) : '';
            $detail = $message !== '' ? ' ' . mb_substr($message, 0, 300) : '';
            if ($code !== '') $detail = ' Meta code ' . $code . '.' . $detail;
            throw new RuntimeException('Meta Graph API rejected the request (HTTP ' . $status . ').' . $detail);
        }
        return $decoded;
    }
}
