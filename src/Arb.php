<?php


namespace Arb;

use Arb\BtcMarkets\BtcMarkets;
use Arb\CryptoCompare\CryptoCompare;
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;

class Arb
{
    protected $resourcesDir;
    protected $btcMarkets;
    protected $coinbase;
    protected $cryptoCompare;
    protected $googleSheets;
    protected $buyAmount;

    const COINS = [
        'BTC',
        'ETH',
        'LTC',
        'BCH',
    ];
    const CURRENCIES = [
        'AUD',
        'USD',
        'GBP',
    ];

    public function __construct($resourcesDir)
    {
        $this->resourcesDir = $resourcesDir;

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

        if (getenv('GOOGLE_JSON_AUTH_FILE')) {
            $jsonAuth = $this->resourcesDir . '/' . getenv('GOOGLE_JSON_AUTH_FILE');
        } else {
            $jsonAuth = json_decode(getenv('GOOGLE_JSON_AUTH') ,true);
        }

        $google->setAuthConfig($jsonAuth);

        $this->googleSheets = new Google_Service_Sheets($google);
    }

    public function get()
    {
        echo '=========================================' . PHP_EOL;
        echo '           ' . date('Y-m-d H:i:s') . PHP_EOL;
        echo '=========================================' . PHP_EOL;

        echo 'Getting base prices from CryptoCompare...' . PHP_EOL;

        $coins = self::COINS;
        $currencies = self::CURRENCIES;

        // Collect base prices from CryptoCompare
        $basePrices = [];
        foreach ($coins as $coin) {
            $basePrices[$coin] = $this->cryptoCompare->prices($coin, $currencies);

            // Output the base price for this coin in each of the currencies
            echo '    ' . $coin . ': ';
            foreach ($currencies as $currency) {
                echo $currency . $basePrices[$coin][$currency] . ' ';
            }
            echo PHP_EOL;
        }

        /** @var \Coinbase\Wallet\Resource\ResourceCollection $paymentMethods */
        $paymentMethods = $this->coinbase->getPaymentMethods();

        echo 'Getting Coinbase Limits...' . PHP_EOL;

        $coinbaseCreditCardFee = 3.99;
        $btcMarketsTradingFee = 0.75;

        /** @var \Coinbase\Wallet\Resource\PaymentMethod $paymentMethod */
        $paymentMethod = $paymentMethods->get(0);
        $limits = $paymentMethod->getLimits();
        $this->buyAmount = floatval($limits['buy'][0]['remaining']['amount']);

        echo 'Coinbase Buy Limit: $' . sprintf('%02.2f', $this->buyAmount) . PHP_EOL;

        $buyAmountAfterFee = $this->buyAmount - (($this->buyAmount / 100) * $coinbaseCreditCardFee);

        echo 'Coinbase Buy Amount after Fee: $' . $buyAmountAfterFee . PHP_EOL;

        echo PHP_EOL;

        $coinbaseBuyPrices = [];
        foreach ($coins as $coin) {
            $coinbaseBuyPrices[$coin] = $this->coinbase->getBuyPrice($coin . '-AUD')->getAmount();
        }

        echo 'Coinbase Buy Prices (after fee):' . PHP_EOL;
        $coinbasePricesAfterFee = [];
        foreach ($coins as $coin) {
            $coinbasePricesAfterFee[$coin] = (floatval($coinbaseBuyPrices[$coin]) / 100) * (100 + $coinbaseCreditCardFee);
            echo '    ' . $coin . ': $' . $coinbasePricesAfterFee[$coin].PHP_EOL;
        }
        echo PHP_EOL;

        $btcMarketsSellPrices = [];
        foreach ($coins as $coin) {
            $btcMarketsSellPrices[$coin] = $this->btcMarkets->price($coin);
        }

        echo 'BTC Markets Sell Prices (after fee):' . PHP_EOL;
        $btcMarketsSellPricesAfterFee = [];
        foreach ($coins as $coin) {
            $btcMarketsSellPricesAfterFee[$coin] = ($btcMarketsSellPrices[$coin]['lastPrice'] / 100) * (100 - $btcMarketsTradingFee);
            echo '    ' . $coin . ': $' . $btcMarketsSellPricesAfterFee[$coin].PHP_EOL;
        }
        echo PHP_EOL;

        echo 'Variances:' . PHP_EOL;

        $variances = [];
        foreach ($coins as $coin) {
            $variances[$coin] = (((100 / $coinbasePricesAfterFee[$coin]) * $btcMarketsSellPricesAfterFee[$coin]) - 100);
            echo '    ' .$coin . ': ' . $variances[$coin] . '%' . PHP_EOL;
        }
        echo PHP_EOL;

        $maxVariance = max($variances);


        echo '=========================================' . PHP_EOL . PHP_EOL;

        // Show an OSX notification if enabled
        if (getenv('NOTIFICATION_ENABLED') && getenv('NOTIFICATION_THRESHOLD') < $maxVariance) {
            $title = 'ARB: Can buy $' . round($this->buyAmount, 2) . '\n';
            $notification = 'Max Variance: ' . $maxVariance . ' (' . array_search($maxVariance, $variances) . ')';

            exec('osascript -e \'display notification "' . $notification . '" with title "' . $title . '"\'');
        }

        // Send a push if the variance is high
        if (getenv('PUSH_ENABLED') && getenv('PUSH_THRESHOLD') < $maxVariance && getenv('PUSHED_APP_KEY') && getenv('PUSHED_APP_SECRET') && $buyAmountAfterFee >= getenv('BUY_AMOUNT_THRESHOLD')) {
            $this->sendPushNotification($variances);
        }

        // Append data to a google sheet if enabled
        if (getenv('APPEND_TO_GOOGLE_SHEET') && getenv('GOOGLE_SHEET_ID')) {
            $this->appendToGoogleSheet($basePrices, $coinbasePricesAfterFee, $btcMarketsSellPricesAfterFee, $variances);
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
        $notificationParts = [];
        foreach (self::COINS as $coin) {
            $notificationParts[] = $coin . ':' . round($variances[$coin], 2) . '%';
        }
        curl_setopt_array($ch = curl_init(), [
            CURLOPT_URL => "https://api.pushed.co/1/push",
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => [
                "app_key" => getenv('PUSHED_APP_KEY'),
                "app_secret" => getenv('PUSHED_APP_SECRET'),
                "target_type" => "app",
                "content" => implode('  ', $notificationParts),
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
        $coins = self::COINS;
        $currencies = self::CURRENCIES;

        $spreadsheetId = getenv('GOOGLE_SHEET_ID');

        $range = 'A2:G2';

        $rowValues = [
            date('Y-m-d H:i:s'),
        ];
        // Add Coinbase prices
        foreach ($coins as $coin) {
            $rowValues[] = round($coinbasePrices[$coin], 2);
        }
        // Add BTC Markets prices
        foreach ($coins as $coin) {
            $rowValues[] = round($btcMarketsPrices[$coin], 2);
        }
        // Add variances
        foreach ($coins as $coin) {
            $rowValues[] = round($variances[$coin], 2);
        }
        // Add base prices
        foreach ($coins as $coin) {
            foreach ($currencies as $currency) {
                $rowValues[] = $basePrices[$coin][$currency];
            }
        }

        $newRow = new Google_Service_Sheets_ValueRange();
        $newRow->setValues(['values' => $rowValues]);

        $this->googleSheets->spreadsheets_values->append($spreadsheetId, $range, $newRow, [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
        ]);

        $requests = [
            new \Google_Service_Sheets_Request([
                'deleteDimension' => [
                    'range' => [
                        'dimension' => 'ROWS',
                        'startIndex' => 1,
                        'endIndex' => 2,
                    ],
                ]
            ]),
        ];
        $batchUpdateRequest = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);

        // Update the sheet to delete the row
        $this->googleSheets->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
    }
}