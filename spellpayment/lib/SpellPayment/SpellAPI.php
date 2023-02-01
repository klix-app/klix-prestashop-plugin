<?php

namespace SpellPayment;

class SpellAPI
{
    const ROOT_URL = "https://portal.klix.app";

    private $private_key;
    private $brand_id;
    private $logger;
    private $debug;

    public function __construct($private_key, $brand_id, $logger, $debug)
    {
        $this->private_key = $private_key;
        $this->brand_id = $brand_id;
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public function createPayment($params)
    {
        $this->logInfo("loading payment form");
        return $this->call('POST', '/purchases/', $params);
    }

    public function paymentMethods($currency, $language)
    {
        $this->logInfo("fetching payment methods");
        return $this->call(
            'GET',
            "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
        );
    }

    public function purchases($payment_id)
    {
        return $this->call('GET', "/purchases/{$payment_id}/");
    }

    public function wasPaymentSuccessful($payment_id)
    {
        $this->logInfo(sprintf("validating payment: %s", $payment_id));
        $result = $this->purchases($payment_id);
        $this->logInfo(sprintf(
            "success check result: %s",
            var_export($result, true)
        ));
        return $result && $result['status'] == 'paid';
    }

    private function call($method, $route, $params = [])
    {
        $private_key = $this->private_key;
        if (!empty($params)) {
            $params = json_encode($params);
        }

        $response = $this->request(
            $method,
            sprintf("%s/api/v1%s", self::ROOT_URL, $route),
            $params,
            [
                'Content-type: application/json',
                'Authorization: ' . "Bearer " . $private_key,
            ]
        );
        $this->logInfo(sprintf('received response: %s', $response));
        $result = json_decode($response, true);
        if (!$result) {
            $this->logError('JSON parsing error/NULL API response');
            return null;
        }

        if (!empty($result['errors'])) {
            $this->logError('API error', $result['errors']);
            return null;
        }

        return $result;
    }

    private function request($method, $url, $params = [], $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        }
        if ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_PUT, 1);
        }
        if ($method == 'PUT' or $method == 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->logInfo(sprintf(
            "%s `%s`\n%s\n%s",
            $method,
            $url,
            var_export($params, true),
            var_export($headers, true)
        ));
        $response = curl_exec($ch);
        switch ($code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200:
            case 201:
                break;
            default:
                $this->logError(
                    sprintf("%s %s: %d", $method, $url, $code),
                    $response
                );
        }
        if (!$response) {
            $this->logError('curl', curl_error($ch));
        }

        curl_close($ch);

        return $response;
    }

    public function logInfo($text)
    {
        if ($this->debug) {
            $this->logger->log("Klix E-commerce gateway INFO: " . $text . ";");
        }
    }

    public function logError($error_text, $error_data = null)
    {
        $error_text = "Klix E-commerce gateway ERROR: " . $error_text . ";";
        if ($error_data) {
            $error_text .= " ERROR DATA: " . var_export($error_data, true) . ";";
        }
        $this->logger->log($error_text);
    }

    
    public function refundPayment($payment_id, $params)
    {
        $this->logInfo(sprintf("refunding payment: %s %s", $payment_id, var_export($params, true)));

        $result = $this->call('POST', "/purchases/{$payment_id}/refund/", $params);

        $this->logInfo(sprintf(
            "payment refund result: %s",
            var_export($result, true)
        ));

        return $result;
    }

}
