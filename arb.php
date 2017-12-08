#!/usr/bin/env php
<?php

require_once dirname(__FILE__) . '/vendor/autoload.php';

use BtcMarkets\BtcMarkets;
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}
set_time_limit(0);

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

class Arb {

    protected $btcMarkets;
    protected $coinbase;
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

        $google = new Google_Client();
        $google->setApplicationName(getenv('GOOGLE_API_APP_NAME'));
        $google->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $google->setAccessType('offline');

        $jsonAuth = getenv('GOOGLE_JSON_AUTH');
        $google->setAuthConfigFile($jsonAuth);

        $this->googleSheets = new Google_Service_Sheets($google);
    }

    public function get()
    {
        /** @var Coinbase\Wallet\Resource\ResourceCollection $paymentMethods */
        $paymentMethods = $this->coinbase->getPaymentMethods();

        echo 'Getting Coinbase Limits...' . PHP_EOL;

        /** @var Coinbase\Wallet\Resource\PaymentMethod $paymentMethod */
        $paymentMethod = $paymentMethods->get(0);
        $limits = $paymentMethod->getLimits();
        $this->buyAmount = floatval($limits['buy'][0]['remaining']['amount']);

        echo 'Coinbase Buy Limit: $' . sprintf('%02.2f', $this->buyAmount) . PHP_EOL;

        $buyAmountAfterFee = $this->buyAmount - (($this->buyAmount / 100) * 3.99);

        echo 'Coinbase Buy Amount after Fee: $' . $buyAmountAfterFee . PHP_EOL;

        echo PHP_EOL.PHP_EOL;

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
            'BTC' => floatval($btcPrice->getAmount()),
            'ETH' => floatval($ethPrice->getAmount()),
            'LTC' => floatval($ltcPrice->getAmount()),
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
            'BTC' => $btcPrice['lastPrice'],
            'ETH' => $ethPrice['lastPrice'],
            'LTC' => $ltcPrice['lastPrice'],
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

        $title = 'ARB: Can buy $' . round($this->buyAmount, 2) . '\n';
        $subtitle = 'Max Profit: $' . $maxProfit . ' (' . array_search($maxProfit, $profits) . ')';
        $notification = 'BTC: ' . round($variances['BTC'], 2) . '%  ';
        $notification .= 'ETH: ' . round($variances['ETH'], 2) . '%  ';
        $notification .= 'LTC: ' . round($variances['LTC'], 2) . '%';

        exec('osascript -e \'display notification "' . $notification . '" with title "' . $title . '" subtitle "' . $subtitle . '"\'');

        if (getenv('PUSH_ENABLED') && getenv('PUSHED_APP_KEY') && getenv('PUSHED_APP_SECRET')) {
            $this->sendPushNotification(round($variances['BTC'], 2), round($variances['ETH'], 2), round($variances['LTC'], 2));
        }

        if (getenv('APPEND_TO_GOOGLE_SHEET') && getenv('GOOGLE_SHEET_ID')) {
            $this->appendToGoogleSheet($coinbasePrices, $btcMarketsPrices, $variances);
        }
    }

    /**
     * Send a push if the variance is high
     *
     * @param $btcVariancePct
     * @param $ethVariancePct
     * @param $ltcVariancePct
     */
    protected function sendPushNotification($btcVariancePct, $ethVariancePct, $ltcVariancePct)
    {
        $highVariance = 13;

        // Don't send a push if the variance is below 14%
        if ($btcVariancePct < $highVariance || $ethVariancePct < $highVariance || $ltcVariancePct < $highVariance) {
            return;
        }

        curl_setopt_array($ch = curl_init(), array(
            CURLOPT_URL => "https://api.pushed.co/1/push",
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => array(
                "app_key" => getenv('PUSHED_APP_KEY'),
                "app_secret" => getenv('PUSHED_APP_SECRET'),
                "target_type" => "app",
                "content" => "BTC: $btcVariancePct%  ETH: $ethVariancePct%  LTC: $ltcVariancePct%",
            ),
            CURLOPT_SAFE_UPLOAD => true,
            CURLOPT_RETURNTRANSFER => true
        ));
        curl_exec($ch);
        curl_close($ch);
    }

    protected function appendToGoogleSheet($coinbasePrices, $btcMarketsPrices, $variances)
    {
        $spreadsheetId = getenv('GOOGLE_SHEET_ID');
        $range = 'A2:G2';

        $newRow = new Google_Service_Sheets_ValueRange();
        $newRow->setValues(['values' => [
            date('Y-m-d H:i:s'),
            $coinbasePrices['BTC'],
            $coinbasePrices['ETH'],
            $coinbasePrices['LTC'],
            $btcMarketsPrices['BTC'],
            $btcMarketsPrices['ETH'],
            $btcMarketsPrices['LTC'],
            round($variances['BTC'], 2),
            round($variances['ETH'], 2),
            round($variances['LTC'], 2),
        ]]);

        $this->googleSheets->spreadsheets_values->append($spreadsheetId, $range, $newRow, [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
        ]);
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

