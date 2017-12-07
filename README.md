# Arbitrage

Connect to Coinbase and BTC Markets to calculate how much money you could make buying from one and selling on the other.

## Installation

- Composer install: `$ composer install`
- Copy `/.env.example` to `/.env`.
- Get a Coinbase API key and secret, requires `wallet:payment-methods:read` and `wallet:payment-methods:limits` permissions.
- Put the values in the `/.env` file.
- Get a BTC Markets API key and secret.
- Put the values in the `/.env
- Make the script executable `$ chmod +x arb.php`

## Usage

`$ ./arb.php`

Example output:

```
Getting Coinbase Limits...
Coinbase Buy Limit: $55.00
Coinbase Buy Amount after Fee: $52.8055


Getting Coinbase BTC price...
Getting Coinbase ETH price...
    BTC: $18681.37
    ETH: $599.06

Getting BTCMarkets BTC price...
Getting BTCMarkets ETH price...
    BTC: $21090.47
    ETH: $648.01

Calculating variances...
    BTC: 12.8957351629%
    ETH: 8.17113477782%

Can Buy BTC: 0.002826639588
Can Buy ETH: 0.088147264047

Expected BTC Profit: $7.6878128193
Expected ETH Profit: $4.66737739918
```

## Run as a daemon

`$ ./arb.php daemon`

Runs the script as a daemon, checking every 10 minutes.