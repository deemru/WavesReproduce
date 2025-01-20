# WavesReproduce

[![packagist](https://img.shields.io/packagist/v/deemru/wavesreproduce.svg)](https://packagist.org/packages/deemru/wavesreproduce) [![php-v](https://img.shields.io/packagist/php-v/deemru/wavesreproduce.svg)](https://packagist.org/packages/deemru/wavesreproduce) [![GitHub](https://img.shields.io/github/actions/workflow/status/deemru/WavesReproduce/php.yml?label=github%20actions)](https://github.com/deemru/WavesReproduce/actions/workflows/php.yml) [![license](https://img.shields.io/packagist/l/deemru/wavesreproduce.svg)](https://packagist.org/packages/deemru/wavesreproduce)

[WavesReproduce](https://github.com/deemru/WavesReproduce) is a framework for reproducing transactions logic already applied to a Waves type blockchain.

- Automatically reconstructs on-chain state
- Watches multiple addresses
- Lets you attach custom logic to different transaction types

## Installation

```bash
composer require deemru/wavesreproduce
```

## Basic usage

```php
use deemru\WavesKit;
use deemru\WavesReproduce;

$wk = new WavesKit;
$address = 'target_waves_address';

$rp = new WavesReproduce( $wk, $address );
$rp->update();

$handlers = [
    // Data transactions (type = 12)
    12 => [
        $address => function( $tx ) {
            // Handle data tx for this address
        }
    ],
    // Invoke transactions (type = 16)
    16 => [
        $address => function( $tx ) {
            // Handle invoke tx for this address
        }
    ],
];

// Replay all transactions of interest from the earliest recorded height
$rp->reproduce( $handlers );

// Access your replicated state
$state = $rp->state[$address];
```

## Documentaion

- Consider to learn self tests: [selftest.php](https://github.com/deemru/WavesReproduce/blob/master/test/selftest.php)
