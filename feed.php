<?php
include_once("mergedrss.php");
include_once("JsonpHelper.php");
if ( ! empty($_GET["category"]) ) {
	$category = $_GET["category"];
} else {
	$category = "blog";
}
if ( ! empty($_GET["format"]) && $_GET["format"] === "json" ) {
	$format = $_GET["format"];
} else {
	$format = "xml";
}
$configs = file_get_contents("config.json");
$configs = json_decode($configs, true);
$communities = $configs['directoryUrl'];
$limit = $configs['defaultLimit'];
if ( ! empty($_GET["items"]) ) {
	$limit = $_GET["items"];
}
$feeds = array();

//load combined api file
$api = file_get_contents($communities);
$communities = json_decode($api, true);

// get additional feeds from config
foreach($configs['additionalFeeds'] as $additionalFeed) {
	if ($additionalFeed['category'] == $category) {
		$feeds[$additionalFeed['name']] = array($additionalFeed['url'], $additionalFeed['name'], $additionalFeed['homepage'], array($additionalFeed['name']));
	}
}

// get feeds from API
foreach($communities as $indexName => $community)
{
	if ( ! empty($community['feeds'] ) ) {
		foreach($community['feeds'] as $feed )
		{
			if ( ! empty($feed['category']) && $feed['category'] == $category && !empty($feed['type']) && $feed['type'] == "rss" )  {
				if ( array_key_exists($feed['url'], $feeds) ) {
					array_push($feeds[$feed['url']][3], $indexName);
				} else {
					$feeds[$feed['url']] = array($feed['url'],$community['name'], $community['url'], array($indexName));
				}
			}
		}
	}
}

// set an arbitrary feed date
$feed_date = date("r", mktime(10,0,0,9,8,2010));

// Create new MergedRSS object with desired parameters
$MergedRSS = new MergedRSS($feeds, "Weimarnetz Feed", "http://weimarnetz.de/", "Das ist der Feed vom Weimarnetz", "http://wiki.freifunk.net/images/7/78/175x170_freifunknet.png", $feed_date);

//Export the first 10 items to screen
$result = $MergedRSS->export(true, false, (array_key_exists('limit', $_GET) ? $_GET['limit'] : $limit), (array_key_exists('source', $_GET) ? $_GET['source'] : 'all'));

JsonpHelper::output($result, $format);
