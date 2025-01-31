Freifunk Feed Merger
===========
RSS blog feed merger for Freifunk API communities

## Setup

* Clone the repo :

	```sh
	git clone https://github.com/freifunk/feed.api.freifunk.net.git
	cd feed.api.freifunk.net
	```

* Create `config.json` from `config.json.sample`, and modify it as you need.

Community Feed Merger
------------------------------

Blogfeeds: http://weimarnetz.de/fffeed/feed.php
=======
	```sh
	cp config.json.sample config.json
	```

* Create a `cache` folder to store cached feeds

* Run `feed.php` (or access it from a php web server)

	```sh
	php feed.php
	```

## How to use

From a web browser or a terminal, call `feed.php`, with these parameters : 

* `source` : shortname of the community to get feeds from. Set `source` to `all` to get feeds from all communities.

 You can find a community shortname here (keys) : https://github.com/fossasia/directory.api.fossasia.net/blob/master/directory.json
* `limit` : maximum number of results. Default to `1`.

* `category` : type of feed (blog, podcast, ics, ..). Default to `blog`.

## How it works

Information about communities feeds is read from `ffGeoJson.json`. This service retrieves blog feeds from provided links, sort by publication, send back to users as rss feed and cache them in folder `cache` at project's directory root.

## History

Our goal is to collect information about Open Source Communities and Hackspaces all over Asia. This information will be used to aggregate contact data, locations, news feeds and events.
We adopted this API from the Hackerspaces and Freifunk API, invented years before to collect decentralized data.

At the Wireless Community Weekend 2013 in Berlin there was a first meeting to relaunch freifunk.net. To represent local communities without collecting and storing data centrally, a way had to be found. Another requirement was to enable local communities to keep their data up to date easily.

Based on the Hackerspaces API (http://hackerspaces.nl/spaceapi/) the idea of the freifunk API was born: Each community provides its data in a well defined format, hosted on their places (web space, wiki, web servers) and contributes a link to the directory. This directory only consists of the name and an url per community. First services supported by our freifunk API are the global community map and a community feed aggregator.

The freifunk API is designed to collect metadata of communities in a decentral way and make it available to other users. It's not designated to be a freifunk node database or a directory of individual community firmware settings.

[Freifunk API repo](https://github.com/freifunk/api.fossasia.net)

## Contribute

Issues & Pull Requests are highly appreciated. Check out our issues for contribution opportunities.

## Requirements

* `ffGeoJson.json`
* PHP >= 5.4
