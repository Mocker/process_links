	foreach($record->Homes as $url){
		
		$a = explode("/", $url);
		$fname = "{$a[4]}";
		if(strpos($url, "community")){
			$fname .= "-{$a[5]}";
		}
		
		$location = "$dir/{$fname}.html";
		echo "file $location\n";
		
		if (!file_exists($location)) {
			$test = new zillowTest($driver, $url);
			$content = $test->page->getHtml();

			if( file_put_contents($location, $content) ){
				echo "the fucking file should be there\n";
			}else{
				echo "not there\n";
			}
			die;
			
			sleep(2);
		}else{
			echo "file $location is there\n";
		}
		
	}
	sleep(5);
