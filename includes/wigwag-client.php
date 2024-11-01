<?php

defined('ABSPATH') || exit;

class WigWag_Exception extends Exception {
    public function __construct(string $message, int $code = 0) {
        parent::__construct('[WigWag Exception] '.$message, $code);
    }
}

class WigWag_Failed_Request_Exception extends WigWag_Exception {
    public function __construct(WigWag_Response $response) {
        parent::__construct("Request failed: ({$response->status_code}) {$response->raw_body}");
    }
}

class WigWag_Failed_Authentication_Exception extends WigWag_Exception {
    public function __construct(WigWag_Response $response) {
        parent::__construct("Authentication failed: ({$response->status_code}) {$response->raw_body}");
    }
}

class WigWag_Response {
    public int $status_code;
    public bool $is_error;
    public null|array $data;
    public string $raw_body;

    public function __construct(string $raw_response, int|string $status_code) {
        $this->status_code = gettype($status_code) === 'string' ? 0 : $status_code;
        $this->raw_body = $raw_response;
        $this->data = json_decode($raw_response, true);
        $this->is_error = $status_code >= 500;
    }
}

class WigWag_Client {
    private string $baseUrl = 'https://just.wigwag.me';
    private string $client_id;
    private string $client_secret;

    public function __construct(string $client_id, string $client_secret) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    /**
     * @throws WigWag_Failed_Request_Exception
     * @throws WigWag_Failed_Authentication_Exception
     */
    public function get_payment_status(string $payment_id): null|string {
        $response = $this->make_wigwag_request('/api/v1/payments/'.$payment_id, 'GET');

        if ($response->status_code === 404) {
            return null;
        }

        if ($response->is_error) {
            throw new WigWag_Failed_Request_Exception($response);
        }

        return $response->data['data']['payment']['status'];
    }

    /**
     * @throws WigWag_Failed_Request_Exception
     * @throws WigWag_Exception
     */
    public function create_payment_link(
        int $amount,
        string $payer_name,
        string $merchant_ref,
        bool $skip_checkout_page
    ): string {
        $response = $this->make_wigwag_request('/api/v1/payments', 'POST', [
            'amount' => $amount,
            'payerName' => $payer_name,
            'merchantReference' => $merchant_ref,
            'skipCheckoutPage' => $skip_checkout_page,
        ]);

        if ($response->is_error || !$response->data['success']) {
            throw new WigWag_Failed_Request_Exception($response);
        }

        return $response->data['data']['payment']['link'];
    }

    /**
     * @throws WigWag_Failed_Request_Exception
     * @throws WigWag_Failed_Authentication_Exception
     */
    private function make_wigwag_request(
        string $path,
        string $method,
        array $data = [],
        bool $withToken = true
    ): WigWag_Response {
        $headers = ['Content-Type' => 'application/json', 'X-IS-WC' => 'true'];

        if ($withToken) {
            $token = $this->get_wigwag_token();
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $result = wp_remote_request($this->baseUrl.$path, [
            'method' => $method,
            'headers' => $headers,
            'body' => $method !== 'GET' ? wp_json_encode($data) : null,
        ]);

        $body = wp_remote_retrieve_body($result);
        $response_code = wp_remote_retrieve_response_code($result);
        $response = new WigWag_Response($body, $response_code);

        if (is_wp_error($result) || $response->is_error) {
            throw new WigWag_Failed_Request_Exception($response);
        }

        return $response;
    }

    /**
     * @throws WigWag_Failed_Request_Exception
     * @throws WigWag_Failed_Authentication_Exception
     */
    private function get_wigwag_token(): string {
        $response = $this->make_wigwag_request('/api/v1/token', 'POST', [
            'clientId' => $this->client_id,
            'clientSecret' => $this->client_secret,
        ], false);

        if ($response->status_code !== 200 || $response->is_error) {
            throw new WigWag_Failed_Authentication_Exception($response);
        }

        return $response->data['data']['accessToken'];
    }
}
