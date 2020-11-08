<?php

function WriteBoxHeader($title)
{
	echo "<div class='main-left-box'>";
	echo "<div class='main-left-title'>$title</div>";
	echo "<div class='main-left-inner'>";
}

$algo = user()->getState('yaamp-algo');

$user = getuserparam(getparam('address'));
if(!$user || $user->is_locked) return;

$count = getparam('count');
$count = $count? $count: 20;


WriteBoxHeader("Last $count Blocks found by $user->username");
$db_blocks = getdbolist('db_blocks', "userid=$user->id order by time desc limit :count", array(':count'=>$count));


echo <<<EOT
<style type="text/css">
span.block { padding: 2px; display: inline-block; text-align: center; min-width: 75px; border-radius: 3px; }
span.block.invalid  { color: white; background-color: #d9534f; }
span.block.immature { color: white; background-color: #f0ad4e; }
span.block.exchange { color: white; background-color: #5cb85c; }
span.block.confirmed { color: white; background-color: #5cb85c; }
span.block.cleared  { color: white; background-color: gray; }
span.block2 { padding: 2px; display: inline-block; text-align: center; min-width: 35px; border-radius: 3px; margin-right: 5px; }
span.block2.solo { color: white;  background-color: #4ca6b3 !important; }
span.block2.shared { color: white;  background-color: #4ca6b3 !important; }
</style>
<table class="dataGrid2">
<thead>
<tr>
<th style='max-width:18px'></th>
<th data-sorter="text">Name</th>
<th data-sorter="numeric" >Block</th>
<th data-sorter="numeric" >Amount</th>
<th data-sorter="numeric" >Difficulty</th>
<th data-sorter="numeric" >Time</th>
<th data-sorter="text" >Type</th>
<th data-sorter="text" >Status</th>
</tr>
</thead>
EOT;

foreach($db_blocks as $db_block)
{
	//if(!$db_block->coin_id) continue;

	$coin = getdbo('db_coins', $db_block->coin_id);
	if(!$coin) continue;

	if($db_block->category == 'stake' && !$this->admin) continue;
	if($db_block->category == 'generated' && !$this->admin) continue; // mature stake income

	if($db_block->category == 'immature')
		echo "<tr style='background-color: #e0d3e8;'>";
	else
		echo "<tr class='ssrow'>";

	echo '<td><img width="16" src="'.$coin->image.'"></td>';

	$flags = $db_block->segwit ? '&nbsp;<img src="/images/ui/segwit.png" height="8px" valign="center" title="segwit"/>' : '';

	echo '<td>';
	if ($this->admin)
		echo '<a href="/site/coin?id='.$coin->id.'"><b>'.$coin->name.'</b></a>';
	else
		echo '<b>'.$coin->name.'</b>';
	echo '&nbsp;('.$coin->symbol.')'.$flags.'</td>';


	$d = datetoa2($db_block->time);
	echo '<td>'.$coin->createExplorerLink($db_block->height, array('height'=>$db_block->height)).'</td>';
	echo '<td>'.$db_block->amount.'</td>';
	echo '<td>'.round_difficulty($db_block->difficulty).'</td>';
	echo '<td data="'.$db_block->time.'"><b>'.$d.' ago</b></td>';

	echo '<td>';
	if($db_block->solo == '1') 
		echo '<span class="block2 solo" title="Block was found by solo miner">Solo</span>';
	else
                echo '<span class="block2 shared" title="Block was found by shared miners">Shared</span>'; 
	echo "</td>";

	echo '<td class="'.strtolower($db_block->category).'">';

	if($db_block->category == 'orphan')
		echo '<span class="block orphan">Orphan</span>';

	else if($db_block->category == 'immature') 
	{
		$eta = '';
		if ($coin->block_time && $coin->mature_blocks) 
		{
			$t = (int) ($coin->mature_blocks - $db_block->confirmations) * $coin->block_time;
			$eta = "ETA: ".sprintf('%dh %02dmn', ($t/3600), ($t/60)%60);
		}
		echo '<span class="block immature" title="'.$eta.'">Immature ('.$db_block->confirmations.'/'.$coin->mature_blocks.')</span>';
	}

	else if($db_block->category == 'generate')
		echo '<span class="block confirmed">Confirmed</span>';

	echo "</td>";

	echo "</tr>";
}

echo "</table>";

echo "<br></div></div><br>";
