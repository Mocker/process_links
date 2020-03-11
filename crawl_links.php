<?php
$MAX_ZIPS = 1;
$MAX_LISTINGS = 5;
$zips_read = 0;
$listings_read = 0;
$BASE_DIR = './listings';

$start = microtime(true);
$file = fopen("./mongo/zillow/listings.json","r");
print "opened json file, beginning to iterate over zip codes\n";

while($line = fgets($file) && ++$zips_read < $MAX_ZIPS && $listings_read < $MAX_LISTINGS ) {
    $zip = json_decode($line);
    $dir = $BASE_DIR.'/'.$zip['Zip'];
    foreach($zip['Homes'] as $url){
        $listings_read++;
        $a = explode("/", $url);
        $fname = "{$a[4]}";
        if(strpos($url, "community")){
            $fname .= "-{$a[5]}";
        }
        
        $location = "$dir/{$fname}.html";
        echo "file $location\n";
        
        if (!file_exists($location)) {
            
            echo exec("curl -x http://scrapoxy:8888 -o $locations $url") . "\n";
            
            sleep(2);
        }else{
            echo "file $location is there\n";
        }
        
        sleep(5);
    }
}

fclose($file);

print "Read $listings_read listings in ".(microtime(true) - $start)." seconds\n";

