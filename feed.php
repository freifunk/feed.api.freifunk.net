<?php
include_once("mergedrss.php");

if ( ! empty($_GET["category"]) ) {
	$category = $_GET["category"];
} else {
	$category = "blog";
}

$communities = "http://freifunk.net/map/ffGeoJson.json";

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
			array('http://radio.freifunk-bno.de/freifunk_radio_feedfeed.xml', 'Freifunk Radio', 'http://wiki.freifunk.net/Freifunk.radio')
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
			if ( ! empty($feed['category']) && $feed['category'] == $category) {
				array_push($feeds, array($feed['url'],$feature['properties']['name'], $feature['properties']['url']))  ;
			}
		}
	}
}



// set the header type
header("Content-type: text/xml");

// set an arbitrary feed date
$feed_date = date("r", mktime(10,0,0,9,8,2010));

// Create new MergedRSS object with desired parameters
$MergedRSS = new MergedRSS($feeds, "Freifunk Community Feeds", "http://www.freifunk.net/", "This the merged RSS feed of RSS feeds of our community", $feed_date);

//Export the first 10 items to screen
$MergedRSS->export(false, true, 50);

