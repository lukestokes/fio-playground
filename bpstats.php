<?php

use EOSPHP\EOSClient;

include 'vendor/autoload.php';

$client = new EOSClient('http://fio.greymass.com');

$fio_bps = array();

$token_price = 0.227;

$filename = "bp_data_cache.txt";
$data = @file_get_contents($filename);
if ($data) {
    $fio_bps = unserialize($data);
	print "Using Cached Data...\n";
	print str_repeat("-",60) . "\n";
} else {
	print "Getting and sorting producers...\n";
	print str_repeat("-",60) . "\n";

	$producers = $client->chain()->getTableRows('eosio','eosio','producers',true,0,-1,100);
	foreach ($producers->rows as $producer) {
		if ($producer->is_active) {
			$fio_bps[$producer->owner] = array();
			$fio_bps[$producer->owner]['owner'] = $producer->owner;
			$fio_bps[$producer->owner]['fio_address'] = trim($producer->fio_address);
			$fio_bps[$producer->owner]['actor'] = $producer->owner;
			$fio_bps[$producer->owner]['total_votes'] = $producer->total_votes;
			$fio_bps[$producer->owner]['last_claim_time'] = $producer->last_claim_time;
			$fio_bps[$producer->owner]['reg_producer_time'] = 0;
		}
	}

	$sort = array();
	foreach($fio_bps as $k => $v) {
		$sort['total_votes'][$k] = $v['total_votes'];
		$sort['owner'][$k] = $v['owner'];
	}
	array_multisort($sort['total_votes'], SORT_DESC, $sort['total_votes'], SORT_ASC, $fio_bps);

	print "Getting BP payments...\n";
	print str_repeat("-",60) . "\n";

	foreach ($fio_bps as $actor => $bp) {
		print $fio_bps[$actor]['fio_address'] . "\n";

		$has_actions = true;
		$pos = 0;
		$offset = 0;
		$limit = 100;
		$processed_transaction_ids = array();

		$max_account_action_seq = 0;

		$amount_earned = 0;
		$offset += $limit;
		while ($has_actions) {
			print ".";
			$actions = $client->history()->getActions($actor, $pos, $offset);
			if (count($actions->actions) == 0) {
				$has_actions = false;
				break;
			}
			foreach ($actions->actions as $key => $action) {
				if ($action->action_trace->act->account == 'fio.token'
					&& $action->action_trace->act->name == 'transfer'
					&& $action->action_trace->act->data->memo == "Paying producer from treasury."
					&& !in_array($action->action_trace->trx_id, $processed_transaction_ids)) {
					$currency = explode(" ", $action->action_trace->act->data->quantity);
					$amount_earned += $currency[0];
					$processed_transaction_ids[] = $action->action_trace->trx_id;
				}
				if ($fio_bps[$actor]['reg_producer_time'] == 0
					&& $action->action_trace->act->account == 'eosio'
					&& $action->action_trace->act->name == 'regproducer') {
					$fio_bps[$actor]['reg_producer_time'] = $action->block_time;
				}
				$max_account_action_seq = $action->account_action_seq;
			}
			$pos = $offset;
			$offset += $limit;
		}
		print "\nAccount Actions: $max_account_action_seq\n";
		$fio_bps[$actor]['bp_rewards'] = $amount_earned;
		$fio_bps[$actor]['bp_rewards_usd'] = ($amount_earned * $token_price);
		//print_r($fio_bps[$actor]);
	}

    $data_for_file = serialize($fio_bps);
	file_put_contents($filename,$data_for_file);

}


print "\n\n\n\n";
print str_repeat("-",114) . "\n";
$format = "%-12s: %-25s %2s: %21s %17s %13s %14s";
printf($format,"owner","FIO Address","Rank","Claimed Rewards","Days Registered","Pay Per Day","Last Claimed");
print "\n" . str_repeat("-",114) . "\n";
$rank = 1;
$format = "%-12s: %-25s %2g: %9d ($%6d USD) %17s %13d %14s";
foreach ($fio_bps as $actor => $bp) {
	$datetime1 = date_create($fio_bps[$actor]['reg_producer_time']);
	$datetime2 = date_create();
	$interval = date_diff($datetime1, $datetime2);
	$days_registered = $interval->format('%a');

	$datetime1 = date_create($fio_bps[$actor]['last_claim_time']);
	$interval = date_diff($datetime1, $datetime2);
	$days_since_last_claim = $interval->format('%a');
	printf(
		$format,
		$fio_bps[$actor]['owner'],
		$fio_bps[$actor]['fio_address'],
		$rank,
		$fio_bps[$actor]['bp_rewards'],
                $fio_bps[$actor]['bp_rewards_usd'],
		$days_registered,
		($fio_bps[$actor]['bp_rewards']/$days_registered),
		$days_since_last_claim
	);
	print "\n";
	$rank++;
}
