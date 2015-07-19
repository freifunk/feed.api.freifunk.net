<?php
include_once("mergedrss.php");
include_once("JsonpHelper.php");
if ( ! empty($_GET["category"]) ) {
	$category = $_GET["category"];
} else {
	$category = "blog";
}

$configs = file_get_contents("config.json");
$configs = json_decode($configs, true);
$communities = $configs['ffGeoJsonUrl'];
$limit = $configs['defaultLimit'];
if ( ! empty($_GET["items"]) ) {
	$limit = $_GET["items"];
}
$feeds = array();

//load combined api file
$api = file_get_contents($communities);
$json = json_decode($api, true);
$geofeatures = $json['features'];

// get additional feeds from config
foreach($configs['additionalFeeds'] as $additionalFeed) {
	if ($additionalFeed['category'] == $category) {
		$feeds[$additionalFeed['name']] = array($additionalFeed['url'], $additionalFeed['name'], $additionalFeed['homepage'], array());
	}
}

// get feeds from API
foreach($geofeatures as $feature)
{
	if ( ! empty($feature['properties']['feeds'] ) ) {
		foreach($feature['properties']['feeds'] as $feed )
		{
			if ( ! empty($feed['category']) && $feed['category'] == $category && !empty($feed['type']) && $feed['type'] == "rss" ) {
				if ( array_key_exists($feed['url'], $feeds) ) {
					array_push($feeds[$feed['url']][3], $feature['properties']['shortname']);
				} else {
					$feeds[$feed['url']] = array($feed['url'],$feature['properties']['name'], $feature['properties']['url'], array($feature['properties']['shortname']));
				}
			}
		}
	}
}

// set the header type
header("Content-type: text/xml");
// set an arbitrary feed date
$feed_date = date("r", mktime(10,0,0,9,8,2010));

// Create new MergedRSS object with desired parameters
$MergedRSS = new MergedRSS($feeds, "Freifunk Community Feeds", "http://www.freifunk.net/", "This the merged RSS feed of RSS feeds of our community", "http://wiki.freifunk.net/images/7/78/175x170_freifunknet.png", $feed_date);

//Export the first 10 items to screen
$result = $MergedRSS->export(true, false, (array_key_exists('limit', $_GET) ? $_GET['limit'] : $limit), (array_key_exists('source', $_GET) ? $_GET['source'] : 'all'));

JsonpHelper::outputXML($result);
