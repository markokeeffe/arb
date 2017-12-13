#!/usr/bin/env php
<?php

require_once dirname(__FILE__) . '/vendor/autoload.php';

use App\BtcMarkets\BtcMarkets;
use App\CryptoCompare\CryptoCompare;
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}
set_time_limit(0);
date_default_timezone_set('Australia/Brisbane');

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

class Arb {

    protected $btcMarkets;
    protected $coinbase;
    protected $cryptoCompare;
    protected $googleSheets;
    protected $buyAmount;

    public function __construct()
    {
        $btcMarketsApiKey = getenv('BTC_MARKETS_API_KEY');
        $btcMarketsApiSecret = getenv('BTC_MARKETS_API_SECRET');
        $this->btcMarkets = new BtcMarkets($btcMarketsApiKey, $btcMarketsApiSecret);

        $coinbaseApiKey = getenv('COINBASE_API_KEY');
        $coinbaseApiSecret = getenv('COINBASE_API_SECRET');
        $this->coinbase = Client::create(Configuration::apiKey($coinbaseApiKey, $coinbaseApiSecret));

        $this->cryptoCompare = new CryptoCompare();

        $google = new Google_Client();
        $google->setApplicationName(getenv('GOOGLE_API_APP_NAME'));
        $google->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $google->setAccessType('offline');

        $jsonAuth = __DIR__ . '/' . getenv('GOOGLE_JSON_AUTH');
        $google->setAuthConfigFile($jsonAuth);

        $this->googleSheets = new Google_Service_Sheets($google);
    }

    public function get()
    {
        echo '=========================================' . PHP_EOL;
        echo '           ' . date('Y-m-d H:i:s') . PHP_EOL;
        echo '=========================================' . PHP_EOL;

        echo 'Getting base prices from CryptoCompare...' . PHP_EOL;

        $currencies = ['AUD', 'USD', 'GBP'];

        $basePrices = [
            'BTC' => $this->cryptoCompare->prices('BTC', $currencies),
            'ETH' => $this->cryptoCompare->prices('ETH', $currencies),
            'LTC' => $this->cryptoCompare->prices('LTC', $currencies),
        ];

        echo '    BTC: ';
        echo 'AUD$' . $basePrices['BTC']['AUD'] . ' ';
        echo 'USD$' . $basePrices['BTC']['USD'] . ' ';
        echo 'GBP£' . $basePrices['BTC']['GBP'] . ' ';
        echo PHP_EOL;

        echo '    ETH: ';
        echo 'AUD$' . $basePrices['ETH']['AUD'] . ' ';
        echo 'USD$' . $basePrices['ETH']['USD'] . ' ';
        echo 'GBP£' . $basePrices['ETH']['GBP'] . ' ';
        echo PHP_EOL;

        echo '    LTC: ';
        echo 'AUD$' . $basePrices['LTC']['AUD'] . ' ';
        echo 'USD$' . $basePrices['LTC']['USD'] . ' ';
        echo 'GBP£' . $basePrices['LTC']['GBP'] . ' ';
        echo PHP_EOL;

        /** @var Coinbase\Wallet\Resource\ResourceCollection $paymentMethods */
        $paymentMethods = $this->coinbase->getPaymentMethods();

        echo 'Getting Coinbase Limits...' . PHP_EOL;

        $coinbaseCreditCardFee = 3.99;
        $btcMarketsTradingFee = 0.75;

        /** @var Coinbase\Wallet\Resource\PaymentMethod $paymentMethod */
        $paymentMethod = $paymentMethods->get(0);
        $limits = $paymentMethod->getLimits();
        $this->buyAmount = floatval($limits['buy'][0]['remaining']['amount']);

        echo 'Coinbase Buy Limit: $' . sprintf('%02.2f', $this->buyAmount) . PHP_EOL;

        $buyAmountAfterFee = $this->buyAmount - (($this->buyAmount / 100) * $coinbaseCreditCardFee);

        echo 'Coinbase Buy Amount after Fee: $' . $buyAmountAfterFee . PHP_EOL;

        echo PHP_EOL;

        echo 'Getting Coinbase BTC price...' . PHP_EOL;

        /** @var \Coinbase\Wallet\Value\Money $btcPrice */
        $btcPrice = $this->coinbase->getBuyPrice('BTC-AUD');

        echo 'Getting Coinbase ETH price...' . PHP_EOL;

        /** @var \Coinbase\Wallet\Value\Money $ethPrice */
        $ethPrice = $this->coinbase->getBuyPrice('ETH-AUD');

        echo 'Getting Coinbase LTC price...' . PHP_EOL;

        /** @var \Coinbase\Wallet\Value\Money $ltcPrice */
        $ltcPrice = $this->coinbase->getBuyPrice('LTC-AUD');

        $coinbasePrices = [
            'BTC' => (floatval($btcPrice->getAmount()) / 100) * (100 + $coinbaseCreditCardFee),
            'ETH' => (floatval($ethPrice->getAmount()) / 100) * (100 + $coinbaseCreditCardFee),
            'LTC' => (floatval($ltcPrice->getAmount()) / 100) * (100 + $coinbaseCreditCardFee),
        ];

        echo '    BTC: $' . $coinbasePrices['BTC'].PHP_EOL;
        echo '    ETH: $' . $coinbasePrices['ETH'].PHP_EOL;
        echo '    LTC: $' . $coinbasePrices['LTC'].PHP_EOL;
        echo PHP_EOL;

        echo 'Getting BTCMarkets BTC price...' . PHP_EOL;

        $btcPrice = $this->btcMarkets->price('BTC');

        echo 'Getting BTCMarkets ETH price...' . PHP_EOL;

        $ethPrice = $this->btcMarkets->price('ETH');

        echo 'Getting BTCMarkets LTC price...' . PHP_EOL;

        $ltcPrice = $this->btcMarkets->price('LTC');

        $btcMarketsPrices = [
            'BTC' => ($btcPrice['lastPrice'] / 100) * (100 - $btcMarketsTradingFee),
            'ETH' => ($ethPrice['lastPrice'] / 100) * (100 - $btcMarketsTradingFee),
            'LTC' => ($ltcPrice['lastPrice'] / 100) * (100 - $btcMarketsTradingFee),
        ];

        echo '    BTC: $' . $btcMarketsPrices['BTC'].PHP_EOL;
        echo '    ETH: $' . $btcMarketsPrices['ETH'].PHP_EOL;
        echo '    LTC: $' . $btcMarketsPrices['LTC'].PHP_EOL;
        echo PHP_EOL;

        echo 'Calculating variances...' . PHP_EOL;

        $variances = [
            'BTC' => (((100 / $coinbasePrices['BTC']) * $btcMarketsPrices['BTC']) - 100),
            'ETH' => (((100 / $coinbasePrices['ETH']) * $btcMarketsPrices['ETH']) - 100),
            'LTC' => (((100 / $coinbasePrices['LTC']) * $btcMarketsPrices['LTC']) - 100),
        ];

        $maxVariance = max($variances);

        echo '    BTC: ' . $variances['BTC'] . '%' . PHP_EOL;
        echo '    ETH: ' . $variances['ETH'] . '%' . PHP_EOL;
        echo '    LTC: ' . $variances['LTC'] . '%' . PHP_EOL;

        echo PHP_EOL;

        $buyBtc = $buyAmountAfterFee / $coinbasePrices['BTC'];

        echo 'Can Buy BTC: ' . $buyBtc . PHP_EOL;

        $buyEth = $buyAmountAfterFee / $coinbasePrices['ETH'];

        echo 'Can Buy ETH: ' . $buyEth . PHP_EOL;

        $buyLtc = $buyAmountAfterFee / $coinbasePrices['LTC'];

        echo 'Can Buy LTC: ' . $buyLtc . PHP_EOL;

        $btcExpected = ($buyBtc / 100 * (100 + $variances['BTC'])) - $buyBtc;
        $ethExpected = ($buyEth / 100 * (100 + $variances['ETH'])) - $buyEth;
        $ltcExpected = ($buyLtc / 100 * (100 + $variances['LTC'])) - $buyLtc;

        $profits = [
            'BTC' => round($btcExpected * $btcMarketsPrices['BTC'], 2),
            'ETH' => round($ethExpected * $btcMarketsPrices['ETH'], 2),
            'LTC' => round($ltcExpected * $btcMarketsPrices['LTC'], 2),
        ];

        $maxProfit = max($profits);

        echo PHP_EOL;

        echo 'Expected BTC Profit: $' . $profits['BTC'] . PHP_EOL;
        echo 'Expected ETH Profit: $' . $profits['ETH'] . PHP_EOL;
        echo 'Expected LTC Profit: $' . $profits['LTC'] . PHP_EOL;

        echo '=========================================' . PHP_EOL . PHP_EOL;

        // Show an OSX notification if enabled
        if (getenv('NOTIFICATION_ENABLED') && getenv('NOTIFICATION_THRESHOLD') < $maxVariance) {
            $title = 'ARB: Can buy $' . round($this->buyAmount, 2) . '\n';
            $subtitle = 'Max Profit: $' . $maxProfit . ' (' . array_search($maxProfit, $profits) . ')';
            $notification = 'Max Variance: ' . $maxVariance . ' (' . array_search($maxVariance, $variances) . ')';

            exec('osascript -e \'display notification "' . $notification . '" with title "' . $title . '" subtitle "' . $subtitle . '"\'');
        }

        // Send a push if the variance is high
        if (getenv('PUSH_ENABLED') && getenv('PUSH_THRESHOLD') < $maxVariance && getenv('PUSHED_APP_KEY') && getenv('PUSHED_APP_SECRET')) {
            $this->sendPushNotification($variances);
        }

        // Append data to a google sheet if enabled
        if (getenv('APPEND_TO_GOOGLE_SHEET') && getenv('GOOGLE_SHEET_ID')) {
            $this->appendToGoogleSheet($basePrices, $coinbasePrices, $btcMarketsPrices, $variances);
        }
    }

    public function test()
    {
//        $response = $this->btcMarkets->sellAtMarketValue('ETH', 0.00000001);
        $response = $this->btcMarkets->getOrderHistory('ETH');
        var_dump($response);
    }

    /**
     * Send a push notification using "Pushed"
     * @param array $variances
     */
    protected function sendPushNotification(array $variances)
    {
        curl_setopt_array($ch = curl_init(), [
            CURLOPT_URL => "https://api.pushed.co/1/push",
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => [
                "app_key" => getenv('PUSHED_APP_KEY'),
                "app_secret" => getenv('PUSHED_APP_SECRET'),
                "target_type" => "app",
                "content" => 'BTC: ' . round($variances['BTC'], 2) . '%  ETH: ' . round($variances['ETH'], 2) . '%  LTC: ' . round($variances['LTC'], 2) . '%',
            ],
            CURLOPT_SAFE_UPLOAD => true,
            CURLOPT_RETURNTRANSFER => true
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Append a row of prices to a google sheet using the Google Sheets API
     * @param $basePrices
     * @param $coinbasePrices
     * @param $btcMarketsPrices
     * @param $variances
     */
    protected function appendToGoogleSheet($basePrices, $coinbasePrices, $btcMarketsPrices, $variances)
    {
        $spreadsheetId = getenv('GOOGLE_SHEET_ID');
        $range = 'A2:G2';

        $newRow = new Google_Service_Sheets_ValueRange();
        $newRow->setValues(['values' => [
            date('Y-m-d H:i:s'),
            round($coinbasePrices['BTC'], 2),
            round($coinbasePrices['ETH'], 2),
            round($coinbasePrices['LTC'], 2),
            round($btcMarketsPrices['BTC'], 2),
            round($btcMarketsPrices['ETH'], 2),
            round($btcMarketsPrices['LTC'], 2),
            round($variances['BTC'], 2),
            round($variances['ETH'], 2),
            round($variances['LTC'], 2),
            $basePrices['BTC']['AUD'],
            $basePrices['BTC']['USD'],
            $basePrices['BTC']['GBP'],
            $basePrices['ETH']['AUD'],
            $basePrices['ETH']['USD'],
            $basePrices['ETH']['GBP'],
            $basePrices['LTC']['AUD'],
            $basePrices['LTC']['USD'],
            $basePrices['LTC']['GBP'],
        ]]);

        $this->googleSheets->spreadsheets_values->append($spreadsheetId, $range, $newRow, [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
        ]);
    }
}

// Run
$arb = new Arb;

if (isset($argv[1]) && 'test' === $argv[1]) {
    $arb->test();
} else {
    $arb->get();
}
