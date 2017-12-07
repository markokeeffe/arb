#!/usr/bin/env php
<?php

require_once dirname(__FILE__) . '/vendor/autoload.php';

use BtcMarkets\BtcMarkets;
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$btcMarketsApiKey = getenv('BTC_MARKETS_API_KEY');
$btcMarketsApiSecret = getenv('BTC_MARKETS_API_SECRET');
$btcMarkets = new BtcMarkets($btcMarketsApiKey, $btcMarketsApiSecret);

$coinbaseApiKey = getenv('COINBASE_API_KEY');
$coinbaseApiSecret = getenv('COINBASE_API_SECRET');
$coinbase = Client::create(Configuration::apiKey($coinbaseApiKey, $coinbaseApiSecret));

/** @var Coinbase\Wallet\Resource\ResourceCollection $paymentMethods */
$paymentMethods = $coinbase->getPaymentMethods();

echo 'Getting Coinbase Limits...' . PHP_EOL;

/** @var Coinbase\Wallet\Resource\PaymentMethod $paymentMethod */
$paymentMethod = $paymentMethods->get(0);
$limits = $paymentMethod->getLimits();
$remaining = floatval($limits['buy'][0]['remaining']['amount']);

echo 'Coinbase Buy Limit: $' . sprintf('%02.2f', $remaining) . PHP_EOL;

$buyAmountAfterFee = $remaining - (($remaining / 100) * 3.99);

echo 'Coinbase Buy Amount after Fee: $' . $buyAmountAfterFee . PHP_EOL;

echo PHP_EOL.PHP_EOL;

echo 'Getting Coinbase BTC price...' . PHP_EOL;

/** @var \Coinbase\Wallet\Value\Money $btcPrice */
$btcPrice = $coinbase->getBuyPrice('BTC-AUD');

echo 'Getting Coinbase ETH price...' . PHP_EOL;

/** @var \Coinbase\Wallet\Value\Money $btcPrice */
$ethPrice = $coinbase->getBuyPrice('ETH-AUD');

$coinbasePrices = [
    'BTC' => floatval($btcPrice->getAmount()),
    'ETH' => floatval($ethPrice->getAmount()),
];

var_dump($coinbasePrices);
echo PHP_EOL;

echo 'Getting BTCMarkets BTC price...' . PHP_EOL;

$btcPrice = $btcMarkets->price('BTC');

echo 'Getting BTCMarkets ETH price...' . PHP_EOL;

$ethPrice = $btcMarkets->price('ETH');

$btcMarketsPrices = [
    'BTC' => $btcPrice['lastPrice'],
    'ETH' => $ethPrice['lastPrice'],
];

var_dump($btcMarketsPrices);
echo PHP_EOL;

echo 'Calculating variances...' . PHP_EOL;

$btcVariancePct = (((100 / $coinbasePrices['BTC']) * $btcMarketsPrices['BTC']) - 100);
$ethVariancePct = (((100 / $coinbasePrices['ETH']) * $btcMarketsPrices['ETH']) - 100);

echo 'BTC: ' . $btcVariancePct . '%' . PHP_EOL;
echo 'ETH: ' . $ethVariancePct . '%' . PHP_EOL;

echo PHP_EOL.PHP_EOL;

$buyBtc = $buyAmountAfterFee / $coinbasePrices['BTC'];

echo 'Can Buy BTC: ' . $buyBtc . PHP_EOL;

$buyEth = $buyAmountAfterFee / $coinbasePrices['ETH'];

echo 'Can Buy ETH: ' . $buyEth . PHP_EOL;

$expectedBtc = ($buyBtc / 100 * (100 + $btcVariancePct)) - $buyBtc;
$expectedEth = ($buyEth / 100 * (100 + $ethVariancePct)) - $buyEth;

$expectedBtcProfit = $expectedBtc * $btcMarketsPrices['BTC'];
$expectedEthProfit = $expectedEth * $btcMarketsPrices['ETH'];

echo PHP_EOL.PHP_EOL;

echo 'Expected BTC Profit: $' . $expectedBtcProfit . PHP_EOL;
echo 'Expected ETH Profit: $' . $expectedEthProfit . PHP_EOL;

