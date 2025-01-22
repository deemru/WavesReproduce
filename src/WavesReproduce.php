<?php

namespace deemru;

use deemru\WavesKit;
use deemru\Triples;

class WavesReproduce
{
    const ID_FIRST = 0;
    const ID_LAST = 1;
    const ID_HEIGHT = 2;

    private WavesKit $wk;
    private array $addresses;

    public array $tx;
    public bool $working = true;
    public array $state;

    function __construct( WavesKit $wk, string $address )
    {
        $this->wk = $wk;
        $this->addresses = [ $address ];
    }

    function addDependency( string $address )
    {
        $this->addresses[] = $address;
    }

    private function dbPath( $address )
    {
        return 'rp_' . $address . '.sqlite';
    }

    private function db( $address ): Triples
    {
        $dbpath = $this->dbPath( $address );
        return new Triples( 'sqlite:' . $dbpath, 'txs', true, [ 'INTEGER PRIMARY KEY', 'TEXT UNIQUE', 'TEXT' ] );
    }

    private function dbFirst( Triples $db )
    {
        $r = $db->getUno( 0, self::ID_FIRST );
        return $r[2] ?? false;
    }

    private function dbLast( Triples $db )
    {
        $r = $db->getUno( 0, self::ID_LAST );
        return $r[2] ?? false;
    }

    private function dbHeight( Triples $db )
    {
        $r = $db->getUno( 0, self::ID_HEIGHT );
        if( $r === false )
            return 0;
        return (int)$r[2];
    }

    private function txkey( $height, $index )
    {
        return ( $height << 32 ) | $index;
    }

    private function offline( int $delay = 3 )
    {
        if( $delay < 1 )
            $delay = 1;
        $this->wk->log( 'w', 'OFFLINE: delay for ' . $delay . ' sec...' );
        sleep( $delay );
    }

    private function getTxInfo( $id )
    {
        $tx = $this->wk->getTransactionById( $id );
        if( $tx === false )
            return false;

        for( ;; )
        {
            $indexes = $this->wk->fetch( '/transactions/merkleProof', true, json_encode( [ 'ids' => [ $id ] ] ) );
            if( $indexes === false )
            {
                $this->offline();
                continue;
            }
            $indexes = $this->wk->json_decode( $indexes );
            break;
        }

        $tx['x'] = sha1( json_encode( $indexes[0] ) );
        $txjson = json_encode( $tx );
        $txkey = $this->txkey( $tx['height'], $indexes[0]['transactionIndex'] );
        return [ $txkey, $id, $txjson ];
    }

    private function getTxsInfo( $address, $batch, $after )
    {
        for( ;; )
        {
            $txs = $this->wk->getTransactions( $address, $batch, $after ?? null );
            if( $txs === false )
            {
                $this->offline();
                continue;
            }
            break;
        }

        $n = count( $txs );

        if( $n === 0 )
            return [];

        $ids = [];
        foreach( $txs as $tx )
        {
            $id = $tx['id'];
            $ids[] = $id;
        }

        for( ;; )
        {
            $indexes = $this->wk->fetch( '/transactions/merkleProof', true, json_encode( [ 'ids' => $ids ] ) );
            if( $indexes === false )
            {
                $this->offline();
                continue;
            }
            $indexes = $this->wk->json_decode( $indexes );
            break;
        }

        $txsinfo = [];
        for( $i = 0; $i < $n; ++$i )
        {
            $tx = $txs[$i];
            $id = $ids[$i];

            $txkey = $this->txkey( $tx['height'], $indexes[$i]['transactionIndex'] );
            $tx['x'] = sha1( json_encode( $indexes[$i] ) );
            $txjson = json_encode( $tx );
            $txsinfo[] = [ $txkey, $id, $txjson ];
        }

        return $txsinfo;
    }

    function txs( $from, $index )
    {
        if( $from < 1 )
            $from = 1;
        $from = $this->txkey( $from, $index );
        $txs = [];
        foreach( $this->addresses as $address )
        {
            $db = $this->db( $address );
            $txs[] = $db->query( 'SELECT * FROM txs WHERE r0 >= ' . $from . ' ORDER BY r0 ASC' );
        }

        return $txs;
    }

    function update( $batch = 100, $stabilityIn = 10 )
    {
        $stability = $stabilityIn << 32;

        foreach( $this->addresses as $address )
        {
            $tt = microtime( true );

            $db = $this->db( $address );
            $first = $this->dbFirst( $db );

            // FIRST RUN
            if( $first !== 'OK' )
            {
                if( $first !== false )
                {
                    $after = $first;
                    $this->wk->log( __FUNCTION__ . ': ' . $address . ' resuming...');
                }
                else
                {
                    $this->wk->log( __FUNCTION__ . ': ' . $address . ' first run' );
                }

                for( ;; )
                {
                    $txsinfo = $this->getTxsInfo( $address, $batch, $after ?? null );
                    if( count( $txsinfo ) === 0 )
                        break;

                    $ntxs = [];
                    foreach( $txsinfo as [ $txkey, $id, $txjson ] )
                        $ntxs[] = [ $txkey, $id, gzdeflate( $txjson, 9 ) ];

                    $this->wk->log( __FUNCTION__ . ': ' . $address . ' appending ' . count( $ntxs ) . ' transactions' . date( ' (Y.m.d H:i:s)', intdiv( $this->wk->json_decode( $txjson )['timestamp'], 1000 ) ) );
                    $ntxs[] = [ 0, 'last', $id ];

                    $db->begin();
                    $db->merge( $ntxs );
                    $db->commit();

                    if( !isset( $txsinfo[$batch - 1][1] ) )
                        break;

                    $after = $txsinfo[$batch - 1][1];
                }

                $db->merge( [[ self::ID_FIRST, 'ID_FIRST', 'OK' ]] );
                $db->merge( [[ self::ID_LAST, 'ID_LAST', 'OK' ]] );
                $this->wk->log( __FUNCTION__ . ': ' . $address . ' first run done');
            }

            $this->wk->log( __FUNCTION__ . ': ' . $address . ' updating...' );

            $last = $this->dbLast( $db );
            if( $last !== 'OK' )
            {
                $this->wk->log( __FUNCTION__ . ': ' . $address . ' clearing unfinished update...' );
                $db->query( 'DELETE FROM txs WHERE r0 > ' . $last );
            }

            $lastTxKey = $db->getHigh( 0 );
            $dbtx = $db->getUno( 0, $lastTxKey );
            $stable = false;

            $stableTxKey = false;
            $stableJson = false;

            if( $last === 'OK' )
            {
                [ $txkey, $id, $txjson ] = $this->getTxsInfo( $address, 1, null )[0];
                if( $txkey === $dbtx[0] && $txjson === gzinflate( $dbtx[2] ) )
                {
                    $txheight = $this->wk->json_decode( $txjson )['height'];
                    if( $this->dbHeight( $db ) - $txheight >= $stabilityIn )
                    {
                        $height = $txheight;
                        $stable = true;
                    }
                    else
                    {
                        $stableJson = $txjson;
                        $stableTxKey = $txkey;
                    }
                }
            }

            if( !$stable )
            {
                if( $stableTxKey !== false )
                {
                    [ $lastTxKey, $lastId ] = $dbtx;
                    $height = $this->wk->height();
                    $db->merge( [[ self::ID_LAST, 'ID_LAST', $lastTxKey ]] );
                }
                else
                for( ;; ) // find valid after (lastTxKey can be rolled back)
                {
                    [ $lastTxKey, $lastId ] = $dbtx;
                    $tx = $this->wk->getTransactionById( $lastId );
                    if( $tx !== false )
                    {
                        $height = $this->wk->height();
                        $db->merge( [[ self::ID_LAST, 'ID_LAST', $lastTxKey ]] );
                        $lastTxInfo = $this->getTxInfo( $lastId );
                        break;
                    }

                    $this->wk->log( __FUNCTION__ . ': ' . $address . ' last transaction not found...' );
                    $db->query( 'DELETE FROM txs WHERE r0 >= ' . $lastTxKey );
                    $lastTxKey = $db->getHigh( 0 );
                    $dbtx = $db->getUno( 0, $lastTxKey );
                }

                $finishTxKey = false;
                $finishJson = false;
                $after = $lastId;

                for( ;; )
                {
                    $txsinfo = $this->getTxsInfo( $address, $batch, $after ?? null );
                    if( count( $txsinfo ) === 0 )
                        break;

                    if( isset( $lastTxInfo ) )
                    {
                        $txsinfo = array_merge( [ $lastTxInfo ], $txsinfo );
                        unset( $lastTxInfo );
                    }

                    $ntxs = [];
                    foreach( $txsinfo as [ $txkey, $id, $txjson ] )
                    {
                        $dbtx = $db->getUno( 1, $id );
                        if( $dbtx !== false && $txkey === $dbtx[0] && $txjson === gzinflate( $dbtx[2] ) )
                        {
                            if( $stableTxKey === false )
                            {
                                $stableJson = $txjson;
                                $stableTxKey = $txkey;
                                $db->query( 'DELETE FROM txs WHERE r0 > ' . $stableTxKey );
                            }

                            if( $stableTxKey - $txkey >= $stability )
                            {
                                $finishJson = $txjson;
                                $finishTxKey = $txkey;
                                break;
                            }
                        }
                        else
                        {
                            $stableTxKey = false;
                        }
                    }

                    if( $finishTxKey !== false || !isset( $txsinfo[$batch - 1][1] ) )
                        break;

                    $after = $txsinfo[$batch - 1][1];

                    if( $stableTxKey === false )
                        $this->wk->log( 'w', __FUNCTION__ . ': ' . $address . ' looking stable transaction' . date( ' (Y.m.d H:i:s)', intdiv( $this->wk->json_decode( $txjson )['timestamp'], 1000 ) ) );
                }

                if( $stableTxKey === false )
                {
                    $this->wk->log( 'e', __FUNCTION__ . ': ' . $address . ' stable not found' );
                    $db->query( 'DELETE FROM txs' );
                    return $this->update( $batch, $stability ); // start from scratch
                }

                $stableTxKey2 = false;
                $finishTxKey2 = false;
                $stableJson2 = false;
                $finishJson2 = false;
                $after = null;

                for( ;; )
                {
                    $txsinfo = $this->getTxsInfo( $address, $batch, $after ?? null );
                    if( count( $txsinfo ) === 0 )
                        break;

                    $ntxs = [];
                    foreach( $txsinfo as [ $txkey, $id, $txjson ] )
                    {
                        $dbtx = $db->getUno( 1, $id );
                        if( $dbtx !== false && $txkey === $dbtx[0] && $txjson === gzinflate( $dbtx[2] ) )
                        {
                            if( $stableTxKey2 === false )
                            {
                                $stableJson2 = $txjson;
                                $stableTxKey2 = $txkey;
                            }

                            if( $stableTxKey2 - $txkey >= $stability )
                            {
                                $finishJson2 = $txjson;
                                $finishTxKey2 = $txkey;
                                break;
                            }
                        }
                        else
                        {
                            if( $stableTxKey2 !== false )
                            {
                                $this->wk->log( 'w', __FUNCTION__ . ': ' . $address . ' found unstable' );
                                return $this->update( $batch, $stability );
                            }

                            $ntxs[] = [ $txkey, $id, gzdeflate( $txjson, 9 ) ];
                        }
                    }

                    if( count( $ntxs ) )
                    {
                        $this->wk->log( __FUNCTION__ . ': ' . $address . ' new ' . count( $ntxs ) . ' transactions' . date( ' (Y.m.d H:i:s)', intdiv( $this->wk->json_decode( $txjson )['timestamp'], 1000 ) ) );

                        $db->begin();
                        $db->merge( $ntxs );
                        $db->commit();
                    }

                    if( $finishTxKey2 !== false || !isset( $txsinfo[$batch - 1][1] ) )
                        break;

                    $after = $txsinfo[$batch - 1][1];
                }

                if( !( $stableTxKey === $stableTxKey2 && $stableJson === $stableJson2 && $finishTxKey === $finishTxKey2 && $finishJson === $finishJson2 ) )
                {
                    $this->wk->log( 'w', __FUNCTION__ . ': ' . $address . ' found unstable' );
                    return $this->update( $batch, $stability );
                }
            }

            $db->merge( [[ self::ID_LAST, 'ID_LAST', 'OK' ]] );
            $db->merge( [[ self::ID_HEIGHT, 'ID_HEIGHT', (string)$height ]] );
            $this->wk->log( __FUNCTION__ . ': ' . $address . sprintf( ' updating done in %.2f ms', 1000 * ( microtime( true ) - $tt ) ) );
        }
    }

    private function unpack( $data )
    {
        return json_decode( gzinflate( $data ), true, 512, JSON_BIGINT_AS_STRING );
    }

    private function invokeReproduce( $functions, $invokes, $caller, $originCaller )
    {
        foreach( $invokes as $tx )
        {
            $dApp = $tx['dApp'];
            $function = $tx['call']['function'];
            $tx['caller'] = $caller;
            $tx['originCaller'] = $originCaller;

            $recursion = $tx['stateChanges']['invokes'];
            if( count( $recursion ) > 0 )
                $this->invokeReproduce( $functions, $recursion, $dApp, $originCaller );

            if( isset( $functions[16][$dApp][$function] ) )
                $functions[16][$dApp][$function]( $tx );

            if( isset( $functions[16][$dApp]['*'] ) )
                $functions[16][$dApp]['*']( $tx );

            if( isset( $functions[16]['*'] ) )
                $functions[16]['*']( $tx );

            $this->stateReproduce( $tx['stateChanges'], $dApp );
        }
    }

    private function dataReproduce( $functions, $tx, $address )
    {
        $this->otherReproduce( $functions, $tx, $address, 12 );
        $this->stateReproduce( $tx, $address );
    }

    private function otherReproduce( $functions, $tx, $address, $type )
    {
        if( isset( $functions[$type][$address] ) )
            $functions[$type][$address]( $tx );

        if( isset( $functions[$type]['*'] ) )
            $functions[$type]['*']( $tx );
    }

    function stateReproduce( $tx, $address )
    {
        if( in_array( $address, $this->addresses ) )
            foreach( $tx['data'] as $ktv )
            {
                $key = $ktv['key'];
                $value = $ktv['value'];

                if( $value === null )
                {
                    unset( $this->state[$address][$key] );
                    continue;
                }
                else
                if( is_string( $value ) && $ktv['type'] === 'binary' )
                    $value = $this->wk->base64TxToBin( $value );

                $this->state[$address][$key] = $value;
            }
    }

    function reproduce( $functions, $from = 1, $index = 0 )
    {
        $qs = $this->txs( $from, $index );
        $qpos = [];
        $qtx = [];
        $n = count( $qs );
        for( $i = 0; $i < $n; ++$i )
        {
            $r = $qs[$i]->fetch();
            if( $r === false )
            {
                $qpos[$i] = 0;
                $qtx[$i] = false;
            }
            else
            {
                $qpos[$i] = $r[0];
                $qtx[$i] = $this->unpack( $r[2] );
            }
        }

        for( ; $this->working; )
        {
            $cpos = PHP_INT_MAX;
            $ci = false;
            for( $i = 0; $i < $n; ++$i )
            {
                $pos = $qpos[$i];
                if( $pos > 0 && $pos < $cpos )
                {
                    $cpos = $pos;
                    $ci = $i;
                }
            }

            if( $ci === false )
                return;

            $tx = $qtx[$ci];

            // fill next
            {
                $r = $qs[$ci]->fetch();
                if( $r === false )
                {
                    $qpos[$ci] = 0;
                    $qtx[$ci] = false;
                }
                else
                {
                    $qpos[$ci] = $r[0];
                    $qtx[$ci] = $this->unpack( $r[2] );
                }
            }

            if( $tx['applicationStatus'] !== 'succeeded' )
                continue;

            unset( $tx['x'] );
            $tx['index'] = $cpos & 0xFFFFFFFF;
            $this->tx = $tx;

            $type = $tx['type'];
            $sender = $tx['sender'];

            if( $type === 18 && isset( $functions[16] ) && $tx['payload']['type'] === 'invocation' )
            {
                $type = 16;
                $tx = $tx['payload'];
            }

            if( isset( $functions[$type] ) )
            {
                if( $type === 16 )
                    $this->invokeReproduce( $functions, [ $tx ], $sender, $sender );
                else
                if( $type === 12 )
                    $this->dataReproduce( $functions, $tx, $sender );
                else
                    $this->otherReproduce( $functions, $tx, $sender, $type );
            }
        }
    }
}
