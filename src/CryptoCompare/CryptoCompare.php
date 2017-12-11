<?php

namespace App\CryptoCompare;


class CryptoCompare
{
    protected $url = 'https://min-api.cryptocompare.com/data/';

    public function prices($coin, array $currencies)
    {
        $queryData = [
            'fsym' => $coin,
            'tsyms' => implode(',', $currencies),
        ];
        $path = 'price?' . http_build_query($queryData);

        return $this->getRequest($path);
    }

    protected function getRequest($path)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; BTC Markets Client; '.php_uname('s').'; PHP/'.phpversion().')');

        curl_setopt($ch, CURLOPT_URL, $this->url . $path);
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