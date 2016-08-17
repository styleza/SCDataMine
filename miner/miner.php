<?php

class Miner {
	private $conf;
	private $activeSessionTokens;
	private $queue;
	private $result;
	
	public function __construct(){
		$this->conf = (include "../config/config.global.php") + (include "../config/config.local.php");
		$this->activeSessionTokens = [];
		$this->queue = [];
		$this->result = [];
	}
	
	public function start(){
		$this->generateSessions();

		while(!empty($this->queue)){
			echo "Queue status: ". count($this->queue) ."\n";
			$this->processQueue();
			
			shuffle($this->queue);
		}
		
		
	}
	
	private function processQueue(){
		$action = array_shift($this->queue);
		if($action[0] == "getSession"){
			echo "Creating session... ";
			list($success, $result) = $this->getSession($action[1], $action[2], $action[3], $action[4], $action[5]);	

			if($success){
				$this->activeSessionTokens[] = $result;
				$this->queue[] = ["getResults", $result];
				echo "Done, $result added to queue\n";
			} else {
				var_dump($result, json_decode($result));
			}
		} else if($action[0] == "getResults"){
			echo "Getting results from {$action[1]}... ";
			$file = $this->getResults($action[1]);
			$this->queue[] = ["parseResult", $file];
			echo "Done\n";
		} else if($action[0] == "parseResult"){
			echo "Parsing {$action[1]}... ";
			$this->parseResult($action[1]);
			$this->findCheap($this->conf["flightsundereur"]);
			echo "Done\n";
		}
			
		
		file_put_contents("{$this->conf["datadir"]}/tokens.json", json_encode($this->activeSessionTokens));
	}
		
		
	
	private function generateSessions(){
		foreach($this->conf["origins"] as $origin){
			foreach($this->conf["destinations"] as $destination){
				$firstOutbound = $this->conf["firstoutbound"];
				$lastArrival = $this->conf["lastarrival"];
				$min = $this->conf["minlength"];
				$max = floor((strtotime($lastArrival) - strtotime($firstOutbound))/(24*60*60));

				for($start = 0; $start < $max-$min; $start++){
					for($end = $min; $end < $max; $end++){
						$out = date("Y-m-d", strtotime("$firstOutbound +$start days"));
						$in = date("Y-m-d", strtotime("$firstOutbound +$end days"));
						
						$this->queue[] = ["getSession", $origin, $destination, $out, $in, $this->conf["adults"]];
					}
				}
			}
		}
	}
	
	private function getResults($sessionUrl){
		$context = stream_context_create([
			"http" => [
				"header" => "Content-type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
				"method" => "GET"
			]
		]);
		
		$result = file_get_contents($sessionUrl . "?" . http_build_query(["apiKey" => $this->conf["apikey"]]), false, $context);
		
		$decoded = json_decode($result);
		$sessionId = $decoded->SessionKey;
		
		$file = "{$this->conf["datadir"]}/{$sessionId}.json";
		
		file_put_contents($file, $result);
		
		return $file;
	}
	
	private function getSession($origin, $destination, $outDate, $inDate, $adults = 1){
		$tokenForSession = $this->createSession([
			"originplace" => $origin,
			"destinationplace" => $destination,
			"outbounddate" => $outDate,
			"inbounddate" => $inDate,
			"adults" => $adults
		]);
		
		return $tokenForSession;
	}
	
	private function createSession($query){
		$query["apiKey"] = $this->conf["apikey"];
		$query["country"] = $this->conf["country"];
		$query["currency"] = $this->conf["currency"];
		$query["locale"] = $this->conf["locale"];
		$query["locationschema"] = "Iata";
		
		$context = stream_context_create([
			"http" => [
				"header" => "Content-type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
				"method" => "POST",
				"content" => http_build_query($query)
			]
		]);
				
		$result = file_get_contents($this->conf["apibase"], false, $context);
		
		foreach($http_response_header as $header){
			if(strpos($header,"Location: ") === 0){
				return [true,substr($header,10)];
			}
		}
		return [false, $result];
	}
	
	public function parseResult($datafile){
		$data = json_decode(file_get_contents($datafile));
		
		$result = [
			"agents" => [],
			"carriers" => [],
			"itineraries" => [],
			"legs" => [],
			"places" => [],
			"segments" => []
		];
		
		foreach($data->Agents as $agent){
			$result["agents"][$agent->Id] = $agent;
		}
		
		foreach($data->Carriers as $carrier){
			$result["carriers"][$carrier->Id] = $carrier;
		}
		
		foreach($data->Currencies as $currency){
			$result["currencies"][$currency->Code] = $currency;
		}
		
		foreach($data->Itineraries as $itinerary){
			$result["itineraries"][$itinerary->OutboundLegId] = $itinerary;
		}
		
		foreach($data->Legs as $leg){
			$result["legs"][$leg->Id] = $leg;
		}
		
		foreach($data->Places as $place){
			$result["places"][$place->Id] = $place;
		}
		
		foreach($data->Segments as $segment){
			$result["segment"][$segment->Id] = $segment;
		}
		
		$result["query"] = $data->Query;
		
		$this->results[$data->SessionKey] = $result;
	}
	
	public function findCheap($maxPrice){
		$results = [];
		foreach($this->results as $sessionId => $result){
			foreach($result["itineraries"] as $itineraryKey => $itinerary){
				foreach($itinerary->PricingOptions as $pricing){
					if($pricing->Price <= $maxPrice){
						$results[] = [$sessionId, $itineraryKey, $pricing->Price, $pricing->DeeplinkUrl, $result["query"]];
					}
				}
			}
		}

		$resultFile = "result-".microtime().".json";
		
		file_put_contents("{$this->conf["datadir"]}/{$resultFile}", json_encode($results));
	}
}