<?php
 function doZebitexCancelOrder($OrderID=false)
{
	if(!$OrderID) return;
 	// todo
	 
        $res = zebitex_api_user("orders/".$OrderID."/cancel", array("id" => $OrderID));
        if($res && !isset($res->error)) {
                $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                        ':market'=>'zebitex', ':uuid'=>$OrderID
                ));
                if($db_order) $db_order->delete();
        }
}
 function doZebitexTrading($quick=false)
{
	$exchange = 'zebitex';
	$updatebalances = true;
 	if (exchange_get($exchange, 'disabled')) return;
 	$data = zebitex_api_user('funds');
	if (empty($data)) return;
 	$savebalance = getdbosql('db_balances', "name='$exchange'");
 	foreach($data as $balance)
	{
		if ($balance->code == 'BTC') {
			if (is_object($savebalance)) {
				$savebalance->balance = $balance->balance;
				$savebalance->onsell = $balance->lockedBalance;
				$savebalance->save();
			}
			continue;
		}
 		if ($updatebalances) {
			// store available balance in market table
			$coins = getdbolist('db_coins', "symbol=:symbol OR symbol2=:symbol",
				array(':symbol'=>$balance->code)
			);
			if (empty($coins)) continue;
			foreach ($coins as $coin) {
				$market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid'=>$coin->id));
				if (!$market) continue;
				$market->balance = $balance->balance;
				$market->ontrade = $balance->lockedBalance;
				$market->balancetime = time();
 				$market->deposit_address = $balance->paymentAddress;
				$market->save();
			}
		}
	}
 	if (!YAAMP_ALLOW_EXCHANGE) return;
 	// real trading, todo..

	         $flushall = rand(0, 8) == 0;
        if($quick) $flushall = false;
        $min_btc_trade = exchange_get($exchange, 'min_btc_trade', 0.00050000); // minimum allowed by the exchange
        $sell_ask_pct = 1.05;        // sell on ask price + 5%
        $cancel_ask_pct = 1.20;      // cancel order if our price is more than ask price + 20%
        // auto trade
        sleep(1);
        $orders = zebitex_api_user('orders/current');
        sleep(1);
        $tickers = zebitex_api_query('orders/tickers');
        foreach ($data as $balance)
        {
                if ($balance->balance == 0 && $balance->lockedBalance == 0) continue;
                if ($balance->code == 'BTC') continue;
                $coin = getdbosql('db_coins', "symbol=:symbol AND dontsell=0", array(':symbol'=>strtoupper($balance->code)));
                if(!$coin) continue;
                $symbol = $coin->symbol;
                if (!empty($coin->symbol2)) $symbol = $coin->symbol2;
		
		$market = getdbosql('db_markets', "coinid=:coinid AND name='zebitex'", array(':coinid'=>$coin->id));
                if(!$market) continue;
                $market->balance = floatval($balance->lockedBalance);
                if (floatval($balance->lockedBalance) > floatval(0)) {
                        $ticker = $tickers->{strtolower($balance->code).'btc'};
                        if(!$ticker || $balance->isDisabled || $balance->isFiat) continue;
                }
		
                // {"per":10,"page":1,"total":4,"items":[{"id":86,"price":"0.001","state":"wait","ordType":"limit","amount":"25.0","originAmount":"25.0","side":"buy","cre$
                //
                if(!empty($orders) && !empty($orders->items))
                foreach($orders->items as $order)
                {
                        $pairs = explode("/", $order->pair);
                        $pair = $order->currency;
			debuglog("pair is ".$pairs[0]);
                        if ($pairs[1] != 'BTC') continue;
                        // ignore buy orders
                        if(stripos($order->side, 'sell') === false) continue;
                        if($market->marketid == 0) {
                                $market->marketid = $order->id;
                                $market->save();
                        }
                        $ask = bitcoinvaluetoa($ticker->sell);
                        $sellprice = bitcoinvaluetoa($order->price);
                        // cancel orders not on the wanted ask range
                        if($sellprice > $ask*$cancel_ask_pct || $flushall)
                        {
                                debuglog("zebitex: cancel order $pair at $sellprice, ask price is now $ask");
                                sleep(1);
                                doZebitexCancelOrder($order->id);
                        }
                        // store existing orders
                        else
			{
			                                $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                                        ':market'=>'zebitex', ':uuid'=>$order->id
                                ));
                                if($db_order) continue;
                                 debuglog("zebitex: store order of {$order->amount} {$symbol} at $sellprice BTC");
                                $db_order = new db_orders;
                                $db_order->market = 'zebitex';
                                $db_order->coinid = $coin->id;
                                $db_order->amount = $order->amount;
                                $db_order->price = $sellprice;
                                $db_order->ask = $ticker->sell;
                                $db_order->bid = $ticker->buy;
                                $db_order->uuid = $order->id;
                                $db_order->created = time(); // $order->TimeStamp 2016-03-07T20:04:05.3947572"
                                $db_order->save();
                        }
                }
                // drop obsolete orders
                $list = getdbolist('db_orders', "coinid={$coin->id} AND market='zebitex'");
                foreach($list as $db_order)
			{
                        $found = false;
                        if(!empty($orders) && isset($orders->items))
                        foreach($orders->items as $order) {
                                if(stripos($order->side, 'sell') === false) continue;
                                if($order->id == $db_order->uuid) {
                                        $found = true;
                                        break;
                                }
                        }
                        if(!$found) {
                                 debuglog("zebitex: delete db order {$db_order->amount} {$coin->symbol} at {$db_order->price} BTC");
                                $db_order->delete();
                        }
                }
                if($coin->dontsell) continue;
                $market->lasttraded = time();
                $market->save();
                // new orders
	 	$amount = floatval($balance->balance);
                if(!$amount) continue;
                if($amount*$coin->price < $min_btc_trade || !$market->marketid) continue;
                sleep(1);
                $data = zebitex_api_query('orders/orderbook', array("market" => strtolower($symbol).'btc'));
//  {"asks":[["0.01654547","3.0"],["0.01784895","2.654466"],["0.018799","4.0"],["0.01954657","0.345534"]],"bids":[["0.01","2.0"],["0.001","11.0"]]}

                if($coin->sellonbid)
                for($i = 0; $i < 5 && $amount >= 0; $i++)
                {
                        if(!isset($data->bids[$i])) break;
                        $nextbuy = $data->bids[$i];
                        if($amount*1.1 < $nextbuy[1]) break;
                        $sellprice = bitcoinvaluetoa($nextbuy[0]);
                        $sellamount = min($amount, $nextbuy[1]);
                        if($sellamount*$sellprice < $min_btc_trade) continue;
                        debuglog("zebitex: selling $sellamount $symbol at $sellprice");
                        sleep(1);
			$params = array('bid' => 'btc', 'ask' => strtolower($symbol), 'side' => 'ask', 'price' => $sellprice,
                                'amount'=>$sellamount, 'market' => strtolower($symbol).'btc', 'ord_type' => 'limit');
                        $res = zebitex_api_user('orders', $params);
                        if(!$res || isset($res->error)) {
                                debuglog("zebitex create order err: ".json_encode($res));
                                break;
                        }
                        $amount -= $sellamount;
                }
                if($amount <= 0) continue;

                sleep(1);
                $ticker = $tickers->{strtolower($balance->code).'btc'};
                if(!$ticker || $balance->isDisabled || $balance->isFiat) continue;
                if($coin->sellonbid)
                        $sellprice = bitcoinvaluetoa($ticker->buy);
		else
                        $sellprice = bitcoinvaluetoa($ticker->sell * $sell_ask_pct); // lowest ask price +5%
                if($amount*$sellprice < $min_btc_trade) continue;
                debuglog("zebitex: selling $amount $symbol at $sellprice");
                sleep(1);
                $params = array('bid' => 'btc', 'ask' => strtolower($symbol), 'side' => 'ask', 'price' => $sellprice,
                                'amount'=>$sellamount, 'market' => strtolower($symbol).'btc', 'ord_type' => 'limit');
                $res = zebitex_api_user('orders', $params);

                if(!$res || isset($res->error)) {
                        debuglog("zebitex create order err: ".json_encode($res));
                        continue;
                }
                $db_order = new db_orders;
                $db_order->market = 'zebitex';
                $db_order->coinid = $coin->id;
                $db_order->amount = $amount;
                $db_order->price = $sellprice;
                $db_order->ask = $ticker->sell;
                $db_order->bid = $ticker->buy;
//              $db_order->uuid = $res->id;
	                 $db_order->created = time();
                $db_order->save();

        }

        $withdraw_min = exchange_get($exchange, 'withdraw_min_btc', EXCH_AUTO_WITHDRAW);
        $withdraw_fee = exchange_get($exchange, 'withdraw_fee_btc', 0.002);
        // auto withdraw : to do
        if(is_object($savebalance))
        if(floatval($withdraw_min) > 0 && $savebalance->balance >= ($withdraw_min + $withdraw_fee))
        {
                // $btcaddr = exchange_get($exchange, 'withdraw_btc_address', YAAMP_BTCADDRESS);
                $btcaddr = YAAMP_BTCADDRESS;
                $amount = $savebalance->balance - $withdraw_fee;
                debuglog("zebitex: withdraw $amount BTC to $btcaddr");
                sleep(1);
                $params = array("code" => $amount."btc");
                $res = zebitex_api_user('withdrawals', $params);
                if(is_object($res) && !isset($res->error))
                {
			$withdraw = new db_withdraws;
                        $withdraw->market = 'zebitex';
                        $withdraw->address = $btcaddr;
                        $withdraw->amount = $amount;
                        $withdraw->time = time();
//                        $withdraw->uuid = $res->Data;
                        $withdraw->save();
                        $savebalance->balance = 0;
                        $savebalance->save();
                } else {
                        debuglog("zebitex withdraw BTC error: ".json_encode($res));
                }
        }

 }
