<?php
namespace Arb\BtcMarkets;
/*
| BTCMarkets
| Exchange is in AUD.
|
| https://github.com/BTCMarkets/API/wiki/Introduction
|
*/
class BtcMarkets {
    protected $key;
    protected $secret;
    protected $url;
    protected $numberConverter = 100000000;

    function __construct($key, $secret)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->url = 'https://api.btcmarkets.net';
    }

    public function price($coin = 'BTC')
    {
        return $this->getRequest('/market/' . $coin . '/AUD/tick');
    }

    public function sellAtMarketValue($coin, $volume)
    {
        return $this->request('/order/create', [
            'currency' => 'AUD',
            'instrument' => $coin,
            'price' => null,
            'volume' => 100000000 * $volume,
            'orderSide' => 'Ask',
            'ordertype' => 'Market',
            'clientRequestId' => '1',
        ]);
    }

    public function getOrderHistory($coin)
    {
        return $this->request('/order/history', [
            'currency' => 'AUD',
            'instrument' => $coin,
            'limit' => 10,
            'since' => 979087000,
        ]);
    }

    protected function request($method, $data = [])
    {
        $data = json_encode($data);
        $nonce = floor(microtime(true) * 1000); // API requires timestamp in milliseconds
        // build query string
        $tosign = implode('\n', [$method, $nonce, $data]);
        // sign query string
        $signed = base64_encode(hash_hmac('sha512', $tosign, base64_decode($this->secret, true)));
        // generate the extra headers
        $headers = [
            "Accept: application/json",
            "Accept-Charset: UTF-8",
            "Content-Type: application/json",
            "apikey: " . $this->key,
            "timestamp: " . $nonce,
            "signature: " . $signed,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; BTC Markets Client; '.php_uname('s').'; PHP/'.phpversion().')');
        curl_setopt($ch, CURLOPT_URL, $this->url . $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);

        curl_close($ch);

        if ($response === false)  {
            return false;
        }

        $result = json_decode($response, true);
        if (!$result) {
            return false;
        }

        return $result;
    }

    protected function getRequest($method)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; BTC Markets Client; '.php_uname('s').'; PHP/'.phpversion().')');

        curl_setopt($ch, CURLOPT_URL, $this->url . $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);

        curl_close($ch);

        if ($response === false)  {
            return false;
        }

        $result = json_decode($response, true);
        if (!$result) {
            return false;
        }

        return $result;
    }
}