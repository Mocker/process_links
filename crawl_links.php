<?php
$MAX_ZIPS = 10;
$MAX_LISTINGS = 5;
$zips_read = 0;
$listings_read = 0;
$BASE_DIR = './listings';
$SCRAPOXY_AUTH = base64_encode( getenv('SCRAPOXY_PWD') );
$USER_AGENT_IOS = "Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5";
$LOGDIR = './logs';
$SCRAPOXY_MIN = 2;
$SCRAPOXY_MAX = 3;
$SCRAPOXY_REQUIRED = 2;
$MAX_FAILURES=5;

$proxy_status = [];
$proxies_ready = 0;

function shutdown() {
    global $SCRAPOXY_AUTH;
    $proxy_down = "curl -s -i -H \"Content-Type: application/json\" -H \"Authorization: $SCRAPOXY_AUTH\" --request PATCH --data '{\"min\":\"0\",\"max\":\"0\",\"required\":\"0\"}' http://scrapoxy:8889/api/scaling";
    print "Requesting scrapoxy changes to configuration with 0 instances\n$proxy_down\n";
    echo shell_exec($proxy_down);
}
register_shutdown_function('shutdown');



print "Requesting proxy configuration update to start sending requests\n";
echo exec("curl  -s -H \"Content-Type: application/json\" -H \"Authorization: $SCRAPOXY_AUTH\" --request PATCH --data '{\"min\":\"$SCRAPOXY_MIN\",\"max\":\"$SCRAPOXY_MAX\",\"required\":\"$SCRAPOXY_REQUIRED\"}' http://scrapoxy:8889/api/scaling");
//could also call scrapoxy api to get available instances if it does not seem a reliable wait for the instances to spin up
sleep(5);
$proxy_status = waitForInstances($SCRAPOXY_AUTH);
$proxies_ready = count( array_filter($proxy_status, 'instanceIsAvailable') );
if($proxies_ready<1) {
    print "Could not start any proxies within wait time\n";
    exit();
}
print( "Currently $proxies_ready proxy nodes are ready\n");
$start = microtime(true);

$log_name = $LOGDIR.'/'.date('Ymd-H-i-s').'.log';
$log_file = fopen($log_name, "w");
fwrite($log_file, "Beginning script. MAX_ZIPS: $MAX_ZIPS . MAX_LISTINGS: $MAX_LISTINGS . PROXIES REQUIRED: $SCRAPOXY_REQUIRED \nPROXIES: ".json_encode($proxy_status)."\n");

$file = fopen("./mongo/zillow/listings.json","r");
print "opened json file, beginning to iterate over zip codes\n";

if(!file_exists($LOGDIR) ){
    mkdir($LOGDIR, 0777, true);
}

$failures=0;

while($zips_read++ <= $MAX_ZIPS && $listings_read < $MAX_LISTINGS && $proxies_ready > 0 && $failures < $MAX_FAILURES && $line = fgets($file) ) {
    $zip = json_decode($line);
    $dir = $BASE_DIR.'/'.$zip->Zip;
    if(!file_exists($dir) ) mkdir($dir, 0777, true);
    print $zip->Zip." - ".count($zip->Homes)." listings.. \n";
    foreach($zip->Homes as $url){
        $a = explode("/", $url);
        $fname = "{$a[4]}";
        if(strpos($url, "community")){
            $fname .= "-{$a[5]}";
        }
        
        $location = "$dir/{$fname}.html";
        echo "file $location\n";
        
        if (!file_exists($location)) {
            echo exec("curl -s -L -A \"$USER_AGENT_IOS\" -x http://scrapoxy:8888 -o $location -D $location.headers $url") . "\n";
            $headers = file_get_contents("$location.headers");
            $http_status = substr($headers, 0, 10);
            fwrite($log_file, microtime()." - $http_status - $url \n");
            if( 'HTTP/2 200' != $http_status && 'HTTP/1.1 200' != $http_status ) {
                $failures++;
                //get proxy instance name and send request to stop it so it will rotate ips
                print "Received failure HTTP response - $http_status - rotating all proxies\n";
                fwrite($log_file, microtime()." - Received failure HTTP response - $http_status - rotating all proxies\n");
                print shell_exec("curl -s -H \"Content-Type: application/json\" -H \"Authorization: $SCRAPOXY_AUTH\" --request POST --data '{\"name\":\"$proxy_name\"}' http://scrapoxy:8889/api/instances/stop");
                fwrite($log_file, microtime()." - Rotated proxy [$proxy_name]\n");
                print "Waiting for another proxy to be available.. \n";
                $proxy_status = waitForInstances($SCRAPOXY_AUTH, 60);
                $proxies_ready = count( array_filter($proxy_status, 'instanceIsAvailable') );
                print json_encode($proxy_status)."\n";
                fwrite($log_file, microtime()." - Current proxies available: ".json_encode($proxy_status)."\n");
                if($proxies_ready < 1 ){
                    print "No proxies available after waiting. Breaking\n";
                    break;
                }
                sleep(2);
            } else {
                $listings_read++;
                sleep(2);
            }
        }else{
            echo "file $location is there\n";
        }
        if($listings_read >= $MAX_LISTINGS) break;
        sleep(1);
    }
}
print "Done. $zips_read / $MAX_ZIPS - $listings_read / $MAX_LISTINGS - $proxies_ready > 0 - $failures / $MAX_FAILURES \n";

fclose($file);
fclose($log_file);

print "Read $listings_read listings in ".(microtime(true) - $start)." seconds\n";

shutdown();

function waitForInstances($auth, $max_wait=300){
    $now = time();
    $instances=0;
    $status = [];
    while($instances<1 && $max_wait> (time()-$now) ) {
        $status = json_decode(shell_exec("curl -s -H \"Content-Type: application/json\" -H \"Authorization: $auth\" --request GET http://scrapoxy:8889/api/instances") );
        $instances = count( array_filter($status, 'instanceIsAvailable') );
        sleep(15);
    }
    return $status;
}

function instanceIsAvailable($instance) {
    return ($instance->status == 'started' && $instance->alive == 'true' && isset($instance->address) ) ? true : false;
}