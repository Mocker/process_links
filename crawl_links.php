<?php
$MAX_ZIPS = 10;
$MAX_LISTINGS = 5;
$zips_read = 0;
$listings_read = 0;
$BASE_DIR = './listings';

$start = microtime(true);
$file = fopen("./mongo/zillow/listings.json","r");
print "opened json file, beginning to iterate over zip codes\n";

while(++$zips_read <= $MAX_ZIPS && $listings_read < $MAX_LISTINGS && $line = fgets($file) ) {
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
            echo exec("curl -L -x http://scrapoxy:8888 -o $location $url") . "\n";
            $listings_read++;
            sleep(2);
        }else{
            echo "file $location is there\n";
        }
        if($listings_read > $MAX_LISTINGS) break;
        sleep(1);
    }
}

fclose($file);

print "Read $listings_read listings in ".(microtime(true) - $start)." seconds\n";

