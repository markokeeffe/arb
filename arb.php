#!/usr/bin/env php
<?php

require_once dirname(__FILE__) . '/vendor/autoload.php';

use BtcMarkets\BtcMarkets;
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

class Arb {
    protected $btcMarkets;
    protected $coinbase;

    public function __construct()
    {
        $btcMarketsApiKey = getenv('BTC_MARKETS_API_KEY');
        $btcMarketsApiSecret = getenv('BTC_MARKETS_API_SECRET');
        $this->btcMarkets = new BtcMarkets($btcMarketsApiKey, $btcMarketsApiSecret);

        $coinbaseApiKey = getenv('COINBASE_API_KEY');
        $coinbaseApiSecret = getenv('COINBASE_API_SECRET');
        $this->coinbase = Client::create(Configuration::apiKey($coinbaseApiKey, $coinbaseApiSecret));
    }

    public function get()
    {
        /** @var Coinbase\Wallet\Resource\ResourceCollection $paymentMethods */
        $paymentMethods = $this->coinbase->getPaymentMethods();

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
        $btcPrice = $this->coinbase->getBuyPrice('BTC-AUD');

        echo 'Getting Coinbase ETH price...' . PHP_EOL;

        /** @var \Coinbase\Wallet\Value\Money $btcPrice */
        $ethPrice = $this->coinbase->getBuyPrice('ETH-AUD');

        $coinbasePrices = [
            'BTC' => floatval($btcPrice->getAmount()),
            'ETH' => floatval($ethPrice->getAmount()),
        ];

        echo '    BTC: $' . $coinbasePrices['BTC'].PHP_EOL;
        echo '    ETH: $' . $coinbasePrices['ETH'].PHP_EOL;
        echo PHP_EOL;

        echo 'Getting BTCMarkets BTC price...' . PHP_EOL;

        $btcPrice = $this->btcMarkets->price('BTC');

        echo 'Getting BTCMarkets ETH price...' . PHP_EOL;

        $ethPrice = $this->btcMarkets->price('ETH');

        $btcMarketsPrices = [
            'BTC' => $btcPrice['lastPrice'],
            'ETH' => $ethPrice['lastPrice'],
        ];

        echo '    BTC: $' . $btcMarketsPrices['BTC'].PHP_EOL;
        echo '    ETH: $' . $btcMarketsPrices['ETH'].PHP_EOL;
        echo PHP_EOL;

        echo 'Calculating variances...' . PHP_EOL;

        $btcVariancePct = (((100 / $coinbasePrices['BTC']) * $btcMarketsPrices['BTC']) - 100);
        $ethVariancePct = (((100 / $coinbasePrices['ETH']) * $btcMarketsPrices['ETH']) - 100);

        echo '    BTC: ' . $btcVariancePct . '%' . PHP_EOL;
        echo '    ETH: ' . $ethVariancePct . '%' . PHP_EOL;

        echo PHP_EOL;

        $buyBtc = $buyAmountAfterFee / $coinbasePrices['BTC'];

        echo 'Can Buy BTC: ' . $buyBtc . PHP_EOL;

        $buyEth = $buyAmountAfterFee / $coinbasePrices['ETH'];

        echo 'Can Buy ETH: ' . $buyEth . PHP_EOL;

        $btcExpected = ($buyBtc / 100 * (100 + $btcVariancePct)) - $buyBtc;
        $ethExpected = ($buyEth / 100 * (100 + $ethVariancePct)) - $buyEth;

        $btcExpectedProfit = round($btcExpected * $btcMarketsPrices['BTC'], 2);
        $ethExpectedProfit = round($ethExpected * $btcMarketsPrices['ETH'], 2);

        echo PHP_EOL;

        echo 'Expected BTC Profit: $' . $btcExpectedProfit . PHP_EOL;
        echo 'Expected ETH Profit: $' . $ethExpectedProfit . PHP_EOL;

        $notification = 'BTC: ' . round($btcVariancePct, 2) . '%  $' . $btcExpectedProfit . '\n';
        $notification .= 'ETH: ' . round($ethVariancePct, 2) . '%  $' . $ethExpectedProfit . '\n';

        exec('osascript -e \'display notification "' . $notification . '" with title "Arb"\'');
    }
}

$arb = new Arb;

// Has the 'daemon' flag been provided?
if (isset($argv[1]) && $argv[1] == 'daemon') {
    // Recalculate every 10 minutes
    do {
        $arb->get();
        sleep(600);
    } while (1);
} else {
    $arb->get();
}

