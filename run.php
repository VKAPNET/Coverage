<?php

// ---------- DO NOT REMOVE ----------
echo "------------------------\n   DAPNET Propagation   \n------------------------\n\n";
if (php_sapi_name() !== "cli") die("[CRIT] This script must be run from the command line. Aborting...\n");
if (php_uname("s") !== "Linux") die("[CRIT] This script must be run on a Linux system. Aborting...\n");
if (file_exists("./.lock")) die ("[CRIT] This script is already running. Aborting...\n");
touch("./.lock");
// -----------------------------------

// ---------- CONFIGURATION ----------
require_once("./config.php");
// -----------------------------------

// ---------- GET SERVER DATA ----------
$serverResult = loadServerData();
if (!$serverResult) die("[CRIT] Unable to get data from server. Aborting...\n");
// -------------------------------------

// ---------- GET LOCAL DATA ----------
$localResult = loadLocalData($serverResult);
// ------------------------------------

// ---------- CHECK PARAMETERS ----------
if (count($_SERVER["argv"]) == 2 && $_SERVER["argv"][1] === "--force") $localResult["firstRun"] = true;
// --------------------------------------

// ---------- COMPARE SERVER AND LOCAL DATA ----------
compareAndRun($serverResult, $localResult);
// ---------------------------------------------------

// ---------- WRITE SERVER DATA ----------
writeLocalData($serverResult);
// ---------------------------------------

// ---------- DO NOT REMOVE ----------
unlink("./.lock");
echo "\n----------\n   DONE   \n----------\n";
// -----------------------------------

// load transmitter data from DAPNET Core via CURL
function loadServerData() {
	$ch = curl_init(DAPNET_URL . '/transmitters');
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Authorization: ' . DAPNET_AUTH
	));
	return curl_exec($ch);
}

// load transmitters' "lastUpdate" value from local file
function loadLocalData($data) {
	$outputData = json_decode(@file_get_contents(LOCAL_FILE), true);
	if (!$outputData) {
		$outputData = array();

		echo "[WARN] No " . LOCAL_FILE . " found. This seems to be the first run...\n";
		$outputData["firstRun"] = true;

		foreach (json_decode($data, true) as &$transmitter) {
			$outputData[$transmitter["name"]] = NULL;
		}
	}
	return $outputData;
}

// compare server and local data and run the propagation script if needed
function compareAndRun($serverData, $localData) {
	// create log-directory
	if (!is_dir("./logs") && !mkdir("./logs", 0744)) die("[CRIT] Unable to create log-directory. Aborting...\n");

	// check every transmitter and process it
	$decodedServerData = json_decode($serverData, true);
	$currentItem = 0;
	foreach ($decodedServerData as &$transmitter) {
		// prepare progress-string
		$currentItem++;
		$progress = str_pad($currentItem, strlen(count($decodedServerData)), "0", STR_PAD_LEFT) . "/" . count($decodedServerData);

		// get lastUpdate string from server
		$dateServer = strtotime($transmitter["lastUpdate"]);

		// if new transmitter: set local time to now to force processing
		if (!array_key_exists($transmitter["name"], $localData)) {
			$dateLocal = strtotime("now");
		} else {
			$dateLocal = strtotime($localData[$transmitter["name"]]);
		}

		// on first run: run script on every transmitter
		$firstRun = array_key_exists("firstRun", $localData);

		// compare dates if not first run
		if (!$firstRun && $dateServer === $dateLocal) {
			echo "[INFO] [" . $progress . "] [SKIP] " . $transmitter["name"] . "\n";
		} else {
			// check for invalid settings
			if ($transmitter["power"] == 0) {
				echo "[INFO] [" . $progress . "] [INVA] " . $transmitter["name"] . "\n";
				continue;
			}

			echo "[INFO] [" . $progress . "] [RUN ] " . $transmitter["name"] . "\n";

			// convert power from W to dBm
			$power = 10 * log10(1000 * $transmitter["power"] / 1);

			// calculate range based on transmitter properties
			$rangehelp = ($power + $transmitter["antennaGainDbi"] + DEFAULT_GAIN_RECEIVER - DEFAULT_CABLE_LOSS + 59.6 - 20 * log10(DEFAULT_FREQUENCY)) / 20;
			$range = ceil(pow(10, $rangehelp) * 1000);
			if ($range > 60000) $range = 60000;

			// build and call the processing script
			$command = "nice -n 19 " .
				PATH_TO_SIMULATION . " " .
				"-D '" . PATH_TO_SIMFILES . "DEM/' " .
				"-T '" . PATH_TO_SIMFILES . "ASTER/' " .
				"-A '" . PATH_TO_SIMFILES . "Antennafiles/' " .
				"-Image '" . PATH_TO_SIMFILES . "CoverageFiles/' " .
				"-n " . $transmitter["name"] . " " .
				"-N " . $transmitter["latitude"] . " " .
				"-O " . $transmitter["longitude"] . " " .
				"-p " . $power . " " .
				"-ht " . $transmitter["antennaAboveGroundLevel"] . " " .
				"-gt " . $transmitter["antennaGainDbi"] . " " .
				"-r " . $range . " " .
				"-R " . DEFAULT_RESOLUTION . " " .
				"-f " . DEFAULT_FREQUENCY . " " .
				"-ant " . DEFAULT_ANTENNA_TYPE . " " .
				"-c " . DEFAULT_CABLE_LOSS . " " .
				"-az " . $transmitter["antennaDirection"] . " " .
				"-al " . DEFAULT_ELEVATION_ANGLE . " " .
				"-hr " . DEFAULT_HEIGHT_RECEIVER . " " .
				"-gr " . DEFAULT_GAIN_RECEIVER . " " .
				"-th " . DEFAULT_THREADS . " " .
				">> ./logs/" . $transmitter["name"] . ".log";

			exec("echo \"" . $command . "\" > ./logs/" . $transmitter["name"] . ".log");
			exec($command);

			// combine images into one
			exec("convert -size 4000x4000 xc:none " .
				"./CoverageFiles/" . $transmitter["name"] . "_red.png -geometry +0+0 -composite " .
				"./CoverageFiles/" . $transmitter["name"] . "_yellow.png -geometry +0+0 -composite " .
				"./CoverageFiles/" . $transmitter["name"] . "_green.png -geometry +0+0 -composite " .
				"./CoverageFiles/" . $transmitter["name"] . ".png");

			// remove red/yellow/green images
			unlink("./CoverageFiles/" . $transmitter["name"] . "_red.png");
			unlink("./CoverageFiles/" . $transmitter["name"] . "_yellow.png");
			unlink("./CoverageFiles/" . $transmitter["name"] . "_green.png");
		}
	}
}

// write transmitters' "lastUpdate" value to local file
function writeLocalData($data) {
	$outputData = array();
	foreach (json_decode($data, true) as &$transmitter) {
		$outputData[$transmitter["name"]] = $transmitter["lastUpdate"];
	}
	file_put_contents(LOCAL_FILE, json_encode($outputData, JSON_PRETTY_PRINT), LOCK_EX);
}