<?php

// include composer autoloader
require_once 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Driver\Exception;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

//TODO: queue name should be env variable as well to keep synchronized
$exchange = 'router';
$queue = 'links_queue';
$consumerTag = 'consumer';

$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
echo "Connected to RabbitMQ\n";
$channel = $connection->channel();
$channel->queue_declare($queue, false, false, false, false);

$links = (new MongoDB\Client("mongodb://localhost:27017"))->zillow->listings;

$json = file_get_contents('./zip_codes_long.json');
$zip_codes = json_decode($json, true);
$florida = $zip_codes['Florida'];

foreach($florida as $row){
	$zip = $row["ZIP Code"];
	$record = $links->findOne(array('_id' => $zip), ['projection' => ['Homes' => 1]]);
	
	foreach($record->Homes as $url){
		$msg = new AMQPMessage("$zip");
		$channel->basic_publish($msg, '', $queue);
	}
}

$channel->close();
$connection->close();
