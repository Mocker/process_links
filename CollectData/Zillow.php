<?php
// dnt-ocs
namespace CollectData;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\WebDriverCapabilityType;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Remote\DesiredCapabilities;

class Zillow {
	protected $driver;
	
	function __construct( $wd_host = 'http://localhost:4444/wd/hub') {
		/*
		$capabilities = DesiredCapabilities::firefox();
		$capabilities->setCapability(
			'moz:firefoxOptions',
		   ['args' => ['-headless']]
		);
		* */

		$capabilities = array(WebDriverCapabilityType::BROWSER_NAME => 'firefox');
       // $this->driver = $this->webDriver = RemoteWebDriver::create($wd_host, $capabilities, 1000*4); //4 sec timeout
       $this->driver = $this->webDriver = RemoteWebDriver::create($wd_host, $capabilities, 60000, 60000); //4 sec timeout
	}
	
	function __destruct() {
		//echo "Notice: close the current browser\n";
        $this->driver->quit();
	}
	
	public function selfDestruct() {
		$this->__destruct();
	}
	
	// run links
	public function runLinks(string $zip){
		$url = "https://www.zillow.com/homes/{$zip}_rb/";
		$this->setZipSearch($zip, $url);
		$links = $this->getZillowLinks($zip);
		return $links;
		//self::__destruct();
	}
	
	// run details
	public function runDetails($zip, $url){
		try{
			$this->setZipSearch($zip, $url);
			$data = $this->getDetails();
			$data['zip'] = $zip;
			return $data;
		}catch(TimeOutException $e) {
			echo "The webdriver timed out!!!\n\n\n\n\n\n\n";
		}
	}
	
	// Get home detail links
	// tbd: fix this zipcode buillshit
	protected function getZillowLinks($zip){
		//echo "Notice: Try to get listing\n";

		// Build homes metadata
		$m = [];
		$m[$zip]["Zip"] = $zip;
		$m[$zip]["Index"] = date("Ymd");

		//echo "Notice: Sleep for 3/seconds\n";
		sleep(2);

		// Result		
		$result_count = $this->driver->findElements(WebDriverBy::cssSelector("span.result-count"));
		if( count($result_count) > 0 ){
			$result = $this->driver->findElement(WebDriverBy::cssSelector("span.result-count"))->getText();
			//echo "result $result\n";
			//preg_match_all('!\d+\,*\d*!', $result, $matches);
			$results = preg_replace( '/[^0-9]/', '', $result);
			echo "result $result\n";
			//$results = $matches[0][0];
			//echo "results $results\n";
			$m[$zip]["results"] = $results;
		}
		else{
			echo "No results for zip code: $zip\n";
			$m[$zip]["results"] = 0;
			$m[$zip]['Homes'] = [];
			return $m;
		}
		
		// Listing xpath queries
		$listing_tag = [];
		$listing_tag []= "//a[@class='list-card-link list-card-img']";
		$listing_tag []= "//a[@class='list-card-link list-card-info']";
		$listing_tag []= "//a[@class='zsg-photo-card-overlay-link routable hdp-link routable mask hdp-link']";
		
		// Execute/implicit
		$listing_counter = 0;
		while(true){
			foreach($listing_tag as $tag){
				$listing_link_wrapper = $this->driver->findElements(WebDriverBy::xpath($tag));
				if( count($listing_link_wrapper) > 0 ){
					echo "Notice: link tag found $tag\n";
					$listings_found = $listing_link_wrapper;
					break;
				}
			}
			
			// Loop through each listing for details
			foreach ($listings_found as $key => $value) {
				$url = $value->getAttribute('href');
				echo "URL attribute: $url\n";
				$m[$zip]['Homes'][] = $url;
				
				// Open window
				$this->getListingDetails($url);
				
				// Get listing details
				//getListingDetails($driver, $full_link, $zip);
				$listing_counter++;
			}
			
			echo "Listing count: $listing_counter\n";
			
			$next_page = [];
			$next_page []= "//a[text()='NEXT']";
			$next_page []= "//a[text()='Next']";
			
			$paginate = false;
			foreach($next_page as $link){
				$next = $this->driver->findElements(WebDriverBy::xpath($link));
				if( count($next) > 0 ){
					//echo "Notice: Next page found\n";
					$paginate = true;
					$this->driver->findElement(WebDriverBy::xpath($link))->click();
					break;
				}
			}
			
			//echo "Notice: Sleep for 3/seconds\n";
			sleep(2);
			if($results == $listing_counter) {
				echo "all homes scrapped from $zip\n";
			}
			if(!$paginate) return $m;
		}
	}
	
	// Set inital zip boundry page
	protected function setZipSearch($check, $url){
		$this->driver->get($url);
		try {			
			$this->driver->wait()->until(
				WebDriverExpectedCondition::urlContains($check)
			);
			return;
		} catch (Exception $e) {
			echo 'Caught exception: ',  $e->getMessage(), "\n";
		}
	}
	
	// details by window open
	protected function getListingDetails($url){
		$this->driver->executeScript("window.open('');");
		$handles = $this->driver->getWindowHandles();
		$this->driver->switchTo()->window($handles[1]);
		$this->driver->get($url);
	}
		
	// get details
	protected function getDetails(){		
		$data = array();
		
		// price
		$price = $this->driver->findElements(WebDriverBy::cssSelector("span.ds-value"));
		if( count($price) > 0 ){
			//echo "price found\n";
			//echo $price[0]->getText() . "\n";
			$data["price"] = $price[0]->getText();
		}else{
			echo "off market\n";
			$data["price"] = false;
			return $data;
		}

		// basics
		$living = $this->driver->findElements(WebDriverBy::cssSelector("span.ds-bed-bath-living-area span"));
		if( count($living) > 2 ){
			$bed = $living[0]->getText() . '/' . $living[1]->getText();
			$bath = $living[2]->getText() . '/' . $living[3]->getText();
			$square = $living[4]->getText() . '/' . $living[5]->getText();
			//echo "$bed $bath $square\n";
			$data["basics"] = array("bed" => $bed, "bath"=> $bath, "square" => $square);
		}


		// address
		$address = $this->driver->findElement(WebDriverBy::cssSelector("h1.ds-address-container"))->getText();
		//echo "address $address\n";
		$data["address"] = $address;
		
		// status
		$status = $this->driver->findElements(WebDriverBy::cssSelector("span.zsg-tooltip-launch_keyword"));
		if( count($status) > 0 ){
			$data["status"] = $status[0]->getText();
		}

		// overview
		$overview = $this->driver->findElement(WebDriverBy::cssSelector("div.ds-overview-section > div"))->getText();
		//echo "overview $overview\n";
		$data["overview"] = $overview;

		// features
		$list_items = $this->driver->findElements(WebDriverBy::cssSelector("ul.ds-home-fact-list > li"));
		$features = [];
		foreach($list_items as $element) {
			$item = $element->getText();
			$pieces = explode("\n", $item);
			$pieces[0] = str_replace(":", "", $pieces[0]);
			$features[$pieces[0]] = $pieces[1];
		}
		//print_r($more_data);
		$data["features"] = $features;
		
		// broker
		$broker = $this->driver->findElements(WebDriverBy::cssSelector("p.cf-listing-agent-brokerage-name"));
		if( count($broker) > 0 ){
			//echo "broker found\n";
			//echo $broker[0]->getText() . "\n";
			$data["broker"] = $broker[0]->getText();
		}
		
		// agent
		$agent = $this->driver->findElements(WebDriverBy::cssSelector("span.cf-listing-agent-display-name"));
		if( count($agent) > 0 ){
			//echo "agent found\n";
			//echo $agent[0]->getText() . "\n";
			$data["agent"] = $agent[0]->getText();
		}

		// price history
		$price_history = $this->driver->findElements(WebDriverBy::cssSelector("table > tbody > tr > td"));

		$price_hist = array();
		for($i=0;$i<(count($price_history)-1);$i++){
			$element = $price_history[$i]->getText();
			//echo "$i $element\n";
			if(strpos($element, "/")){
				//echo "date $element";
				//echo "\w event \"" . $price_history[++$i]->getText() . "\"";
				//echo "\w price \"" . $price_history[++$i]->getText() . "\"";
				//echo "\n";
				$date = $element;
				$event = $price_history[++$i]->getText();
				$price = $price_history[++$i]->getText();
				$price_hist[] = array("date" => $date, "event" => $event, "price" => $price);
			}
		}
		$data["price history"] = $price_hist;
		
		return $data;
	}
}
