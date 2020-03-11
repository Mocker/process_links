<?php

echo "Notice: Set error handling\n";
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// include composer autoloader
require_once 'vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

use MongoDB\Client;
use MongoDB\Driver\Exception;

use CollectData\Zillow;

//RABBITMQ Consumer setup - https://github.com/php-amqplib/php-amqplib/blob/master/demo/amqp_consumer.php
//TODO: queue name should be env variable as well to keep synchronized
$exchange = 'router';
$queue = 'links_queue';
$consumerTag = 'consumer';


//TODO:: get rabbit connection details from env. we assume default user/pass of guest, guest but could move that to env variables as well
//$connection = new AMQPStreamConnection(getenv('RABBIT_HOST'), getenv('RABBIT_PORT'), 'guest', 'guest');
$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
echo "Connected to RabbitMQ\n";
$channel = $connection->channel();
// IMPORTANT: prefetch is set to 1; this should synchronize/heartbeat between producer/consumer https://github.com/php-amqplib/php-amqplib/issues/424
$channel->basic_qos(0, 1, false);
$channel->exchange_declare($exchange, AMQPExchangeType::DIRECT, false, true, false);
$channel->queue_declare($queue, false, false, false, false);
$channel->queue_bind($queue, $exchange);

// NoSQL
$home_details = (new MongoDB\Client("mongodb://localhost:27017"))->zillow->homes;

function process_message($message)
{
	global $home_details;
    
    //echo "\n--------\n";
    //echo "{$message->body}\n";
    //echo "\n--------\n";
    
	$parts = explode("@", $message->body);
	$url = $parts[0];
	$zip = $parts[1];
	
	$a = explode("/", $url);
	$index = "{$a[4]}";
	if(strpos($url, "community")){
		$index .= "-{$a[5]}";
	}
	$count = $home_details->count(array('_id' => $index ), []);

	if($count == 0){
		$zillow = new Zillow();
		$homes_data = $zillow->runDetails($zip, $url);	
		//print_r($homes_data);
		try
		{	
			$insert = array(
				'_id'=> $index,
				'listing'=> array($index => $homes_data)
			);	
			$home_details->insertOne($insert);
			echo "index added $index\n";
			$zillow->selfDestruct();
		}
		catch(Exception $e)
		{
			echo "Exception:", $e->getMessage(), "\n";
			$zillow->selfDestruct();
		}
	}
	else{
			echo "index exists $index\n";
	}
	
    $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);

    // Send a message with the string "quit" to cancel the consumer.
    if ($message->body === 'quit') {
        $message->delivery_info['channel']->basic_cancel($message->delivery_info['consumer_tag']);
    }
}

$channel->basic_consume($queue, $consumerTag, false, false, false, false, 'process_message');
function shutdown($channel, $connection)
{
    $channel->close();
    $connection->close();
}
// Loop as long as the channel has callbacks registered
echo "Starting consume loop\n";
while ($channel ->is_consuming()) {
    $channel->wait();
}
