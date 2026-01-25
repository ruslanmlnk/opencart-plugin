<?php
class PromSyncApi {
    private $token;
    private $domain;
    private $language;
    private $timeout;
    private $last_error;

    public function __construct($token, $domain = 'prom.ua', $language = null, $timeout = 30) {
        $this->token = trim((string)$token);
        $this->domain = $domain ? trim((string)$domain) : 'prom.ua';
        $this->language = $language ? trim((string)$language) : null;
        $this->timeout = (int)$timeout;
    }

    public function getLastError() {
        return $this->last_error;
    }

    public function get($path, array $query = array()) {
        return $this->request('GET', $path, $query, null);
    }

    public function post($path, $body = null, array $query = array()) {
        return $this->request('POST', $path, $query, $body);
    }

    public function request($method, $path, array $query = array(), $body = null) {
        $this->last_error = null;

        if (!$this->token) {
            $this->last_error = 'Missing API token.';
            return array('success' => false, 'status' => 0, 'data' => null, 'error' => $this->last_error);
        }

        $url = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        $headers = array(
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json'
        );
        if ($this->language) {
            $headers[] = 'X-LANGUAGE: ' . $this->language;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            $this->last_error = $err ?: 'cURL error';
            return array('success' => false, 'status' => $status, 'data' => null, 'error' => $this->last_error);
        }

        $data = null;
        if ($raw !== '') {
            $data = json_decode($raw, true);
        }

        if ($status >= 400) {
            $this->last_error = 'HTTP ' . $status;
            return array('success' => false, 'status' => $status, 'data' => $data, 'error' => $this->last_error);
        }

        return array('success' => true, 'status' => $status, 'data' => $data, 'error' => null);
    }

    private function getBaseUrl() {
        return 'https://my.' . $this->domain . '/api/v1';
    }
}
