<?php

# API able to query balances
# https://cryptofresh.com/api/account/balances?account=...

function cryptobridge_api_user($method, $params='')
{
	$uri = "https://cryptofresh.com/api/{$method}";
	if (!empty($params)) $uri .= "?{$params}";

	$ch = curl_init($uri);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);

	$execResult = strip_tags(curl_exec($ch));
	$arr = json_decode($execResult, true);

	return $arr;
}

function doCryptobridgeTrading($quick=false)
{
	$exchange = 'cryptobridge';
	$updatebalances = true;

	if (empty(EXCH_CRYPTOBRIDGE_ID)) return;
        if (exchange_get($exchange, 'disabled')) return;

	$balances = cryptobridge_api_user('account/balances','account='.EXCH_CRYPTOBRIDGE_ID);
	if (!is_array($balances)) return;

	$savebalance = getdbosql('db_balances', "name='$exchange'");
	if (is_object($savebalance)) {
		$savebalance->balance = 0;
		$savebalance->onsell = 0;
		$savebalance->save();
	}

	foreach($balances as $asset => $balance)
	{
		$parts = explode('.', $asset);
		$symbol = arraySafeVal($parts,1);
		if (empty($symbol) || $parts[0] != 'BRIDGE') continue;

		if ($symbol == 'BTC') {
			if (is_object($savebalance)) {
				$savebalance->balance = arraySafeVal($balance,'balance',0);
				$savebalance->onsell = arraySafeVal($balance,'orders',0);
				$savebalance->save();
			}
			continue;
		}

		if ($updatebalances) {
			// store available balance in market table
			$coins = getdbolist('db_coins', "symbol=:symbol OR symbol2=:symbol",
				array(':symbol'=>$symbol)
			);
			if (empty($coins)) continue;
			foreach ($coins as $coin) {
				$market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid'=>$coin->id));
				if (!$market) continue;
				$market->balance = arraySafeVal($balance,'balance',0);
				$market->ontrade = arraySafeVal($balance,'orders',0);
				$market->balancetime = time();
				$market->save();
			}
		}
	}

	if (!YAAMP_ALLOW_EXCHANGE) return;

	// more could be done i guess
}
