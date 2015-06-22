<?php
include_once("mergedrss.php");

if ( ! empty($_GET["category"]) ) {
	$category = $_GET["category"];
} else {
	$category = "blog";
}

if ( (! empty($_GET["items"]) ) && is_numeric($_GET["items"]) ) {
  $items = $_GET["items"];
} else {
  $items = 18;
}

$configs = file_get_contents("config.json");
$configs = json_decode($configs, true);
$communities = $configs['ffGeoJsonUrl'];

//load combined api file
$api = file_get_contents($communities);
$json = json_decode($api, true);
$geofeatures = $json['features'];

// place our feeds in an array for categories with static feeds
switch ($category) {
	case "blog":
		$feeds = array(
			array('http://blog.freifunk.net/rss.xml','blog.freifunk.net','http://blog.freifunk.net'),
			array('http://freifunkstattangst.de/feed/', 'freifunk statt Angst','http://freifunkstattangst.de'),
			array('http://radio.freifunk-bno.de/freifunk_radio_feedfeed.xml', 'Freifunk Radio', 'http://wiki.freifunk.net/Freifunk.radio')
		);
		break;
	case "podcast":
		$feeds = array(
			array('http://radio.freifunk-bno.de/freifunk_radio_feedfeed.xml', 'Freifunk Radio', 'http://wiki.freifunk.net/Freifunk.radio'),
			array('http://rss.freifunk.net/tags/podcast.rss', 'Freifunk - zusammengetragene Audiobeiträge', 'http://rss.freifunk.net/tags/podcast')
		);
		break;
	default:
		$feeds = array();
}
		

foreach($geofeatures as $feature)
{
	if ( ! empty($feature['properties']['feeds'] ) ) {
		foreach($feature['properties']['feeds'] as $feed )
		{
			if ( ! empty($feed['category']) && $feed['category'] == $category && !empty($feed['type']) && $feed['type'] == "rss" ) {
				$feeds[$feature['properties']['shortname']] = array($feed['url'],$feature['properties']['name'], $feature['properties']['url']);
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
$MergedRSS->export(false, true, 1, $_GET['source']);

