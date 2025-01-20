<?php

require __DIR__ . '/../vendor/autoload.php';
use deemru\WavesKit;
use deemru\WavesReproduce;

$wk = new WavesKit();

function ms( $ms )
{
    if( $ms > 100 )
        return round( $ms );
    else if( $ms > 10 )
        return sprintf( '%.01f', $ms );
    return sprintf( '%.02f', $ms );
}

class tester
{
    private $successful = 0;
    public $failed = 0;
    private $depth = 0;
    private $info = [];
    private $start = [];
    private $init;

    public function pretest( $info )
    {
        $this->info[$this->depth] = $info;
        $this->start[$this->depth] = microtime( true );
        if( !isset( $this->init ) )
            $this->init = $this->start[$this->depth];
        $this->depth++;
    }

    private function ms( $start )
    {
        $ms = ( microtime( true ) - $start ) * 1000;
        $ms = $ms > 100 ? round( $ms ) : $ms;
        $ms = sprintf( $ms > 10 ? ( $ms > 100 ? '%.00f' : '%.01f' ) : '%.02f', $ms );
        return $ms;
    }

    public function test( $cond )
    {
        global $wk;
        $this->depth--;
        $ms = $this->ms( $this->start[$this->depth] );
        $wk->log( $cond ? 's' : 'e', "{$this->info[$this->depth]} ($ms ms)" );
        $cond ? $this->successful++ : $this->failed++;
        return $cond;
    }

    public function finish()
    {
        $total = $this->successful + $this->failed;
        $ms = $this->ms( $this->init );
        echo "  TOTAL: {$this->successful}/$total ($ms ms)\n";
        sleep( 3 );

        if( $this->failed > 0 )
            exit( 1 );
    }
}

echo "   TEST: WavesReproduce\n";
$t = new tester();

if( file_exists( __DIR__ . '/private.php' ) )
    require_once __DIR__ . '/private.php';

$t->pretest( 'private faucet ready' );
{
    $wkFaucet = new WavesKit( 'T' );
    $wkFaucet->setNodeAddress( 'https://nodes-testnet.w8.io' );
    $wkFaucet->setSeed( getenv( 'WAVESKIT_SEED' ) );
    $address = $wkFaucet->getAddress();
    $balance = $wkFaucet->balance( null, 'WAVES' );
    $t->test( $balance >= 10000000000 );
    $wkFaucet->log( 'i', "faucet = $address (" . number_format( $balance / 100000000, 8, '.', '' ) . ' Waves)' );
}

if( $t->failed > 0 )
    $t->finish();

$t->pretest( 'new tester' );
{
    $wk = new WavesKit( $wkFaucet->getChainId() );
    $wk->setSeed( $wk->randomSeed() );
    $address = $wk->getAddress();
    $balance = $wk->balance( null, 'WAVES' );
    $tx = $wk->getTransactions();

    $t->test( $balance === 0 && $tx === false );
    $wk->log( 'i', "tester = $address" );
}

$wavesAmount = 100000000;
$confirmations = 0;
$sleep = 3;

if( $balance < $wavesAmount )
{
    $wavesAmountPrint = number_format( $wavesAmount / 100000000, 8, '.', '' ) . ' Waves';
    $t->pretest( "txTransfer faucet => tester ($wavesAmountPrint)" );
    {
        $tx = $wkFaucet->txTransfer( $wk->getAddress(), $wavesAmount );
        $tx = $wkFaucet->txSign( $tx );
        $tx = $wkFaucet->txBroadcast( $tx );
        $tx = $wkFaucet->ensure( $tx, $confirmations, $sleep );

        sleep( $sleep );
        $balance = $wk->balance( null, 'WAVES' );
        $t->test( $balance === $wavesAmount );
    }
}

$n = mt_rand( 4, 100 );
$t->pretest( "txData (x$n)" );
{
    $data = [];
    for( $i = 0; $i < $n; $i++ )
    {
        if( $i === 0 )
        {
            $integer = mt_rand();
            $data["key_$i"] = $integer;
        }
        else if( $i === 1 )
        {
            $boolean = mt_rand( 0, 1 ) ? true : false;
            $data["key_$i"] = $boolean;
        }
        else if( $i === 2 )
        {
            $binary = $wk->sha256( $wk->randomSeed() );
            $data["key_$i"] = [ $binary ];
        }
        else
        {
            $string = $wk->randomSeed( 1 );
            $data["key_$i"] = $string;
        }
    }

    $tx = $wk->txData( $data );
    $tx = $wk->txSign( $tx );
    $tx = $wk->txBroadcast( $tx );
    $tx = $wk->ensure( $tx, $confirmations, $sleep );

    $dataOK = true;
    foreach( $data as $key => $value )
    {
        if( is_array( $value ) )
        {
            $value = $value[0];
            $dataOK &= $value === $wk->base64TxToBin( $wk->getData( $key ) );
        }
        else
        {
            $dataOK &= $value === $wk->getData( $key );
        }
    }

    $t->test( $dataOK );
}

$t->pretest( "WavesReproduce (tester)" );
{
    $rp = new WavesReproduce( $wk, $wk->getAddress() );
    $rp->update();
    $rp->reproduce(
    [
        12 => [ $wk->getAddress() => function(){} ],
        16 => [ $wk->getAddress() => function(){} ],
    ] );
    $state = $rp->state[$wk->getAddress()];

    $dataOK = true;
    foreach( $data as $key => $value )
    {
        if( is_array( $value ) )
            $value = $value[0];
        $dataOK &= $value === $state[$key];
    }

    $t->test( $dataOK );
}

$t->pretest( "txTransfer return funds" );
{
    sleep( $sleep );
    $tx = $wk->txTransfer( $wkFaucet->getAddress(), $wk->balance( null, 'WAVES' ) - 100000 );
    $tx = $wk->txSign( $tx );
    $tx = $wk->txBroadcast( $tx );
    $tx = $wk->ensure( $tx, $confirmations, $sleep );

    sleep( $sleep );
    $balance = $wk->balance( null, 'WAVES' );
    $t->test( $balance === 0 );
}

$t->finish();