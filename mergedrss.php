<?php
class MergedRSS {
	private $myFeeds = null;
	private $myTitle = null;
	private $myLink = null;
	private $myDescription = null;
	private $myImage = null;
	private $myPubDate = null;
	private $myCacheTime = null;
	private $fetch_timeout = null; //timeout for fetching urls in seconds (floating point)

	// create our Merged RSS Feed
	public function __construct($feeds = null, $channel_title = null, $channel_link = null, $channel_description = null, $channel_image = null, $channel_pubdate = null, $cache_time_in_seconds = 3600, $fetch_timeout = 2.0) {
		// set variables
		$this->myTitle = $channel_title;
		$this->myLink = $channel_link;
		$this->myDescription = $channel_description;
		$this->myImage = $channel_image;
		$this->myPubDate = $channel_pubdate;
		$this->myCacheTime = $cache_time_in_seconds;
		$this->fetch_timeout = $fetch_timeout;

		// initialize feed variable
		$this->myFeeds = array();

		if (isset($feeds)) {
			// check if it's an array.  if so, merge it into our existing array.  if it's a single feed, just push it into the array
			if (is_array($feeds)) {
				$this->myFeeds = array_merge($feeds);
			} else { 
				$this->myFeeds[] = $feeds;
			}
		}
	}

	// exports the data as a returned value and/or outputted to the screen
	public function export($return_as_string = true, $output = false, $limit = null, $community = 'all') {
		// initialize a combined item array for later
		$items = array();
		
		// loop through each feed
		foreach ($this->myFeeds as $key => $feed_array) {
			if ($community !== 'all' && ! in_array($community, $feed_array[3])) {
				continue;
			}

			$results = null;
			$feed_url = $feed_array[0];
			// determine my cache file name.  for now i assume they're all kept in a file called "cache"
			$cache_file = "cache/" . $this->__create_feed_key($feed_url);

			// determine whether or not I should use the cached version of the xml
			$use_cache = false;
			if (file_exists($cache_file)) { 
				if (time() - filemtime($cache_file) < $this->myCacheTime) { 
					$use_cache = true;
				}
			}

			if ($use_cache) {
				// retrieve cached version
				$sxe = $this->__fetch_rss_from_cache($cache_file); 
				$results = $sxe->channel->item;
			} else { 
				// retrieve updated rss feed
				$sxe_raw = $this->__fetch_rss_from_url($feed_url);
				if (!$sxe_raw) {
					continue;
				}
				$sxe = $this->__convert_to_rss($sxe_raw);
				if ( is_object($sxe) ) {
					$results = $sxe->channel->item;
				}

				if (!isset($results)) { 
					// couldn't fetch from the url. grab a cached version if we can
					if (file_exists($cache_file)) { 
						$sxe = $this->__fetch_rss_from_cache($cache_file); 
						$results = $sxe->channel->item;
					}
				} else { 
					// we need to update the cache file
					if (is_object($sxe)) {
						$sxe->asXML($cache_file);
					}
				}
			}

			if (isset($results)) {
				// add each item to the master item list
				foreach ($results as $item) {
					if (trim($item->title) == '') {
						continue;
					}
					//convert title to utf-8 (i.e. from facebook feeds)
					$item->title = html_entity_decode($item->title, ENT_QUOTES,  'UTF-8');
					$source = $item->addChild('source', '' . $feed_array[1]);
					$source->addAttribute('url', $feed_array[2]);
					$items[] = $item;
				}
			}
		}


		// set all the initial, necessary xml data
		$xml =  "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<rss version=\"2.0\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:wfw=\"http://wellformedweb.org/CommentAPI/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:sy=\"http://purl.org/rss/1.0/modules/syndication/\" xmlns:slash=\"http://purl.org/rss/1.0/modules/slash/\" xmlns:itunes=\"http://www.itunes.com/DTDs/Podcast-1.0.dtd\" xmlns:media=\"http://search.yahoo.com/mrss/\" xmlns:psc=\"http://podlove.org/simple-chapters\" xmlns:fh=\"http://purl.org/syndication/history/1.0\" xmlns:podcast=\"https://podcastindex.org/namespace/1.0\" xmlns:cc=\"http://cyber.law.harvard.edu/rss/creativeCommonsRssModule.html\" xmlns:friends=\"wordpress-plugin-friends:feed-additions:1\" xmlns:discourse=\"http://www.discourse.org/\" >\n";
    $xml .= "<generator>Freifunk API Feed Aggregator with the help of https://atom.geekhood.net/</generator>\n";
		$xml .= "<channel>\n";
		if (isset($this->myTitle)) { $xml .= "\t<title>".$this->myTitle."</title>\n"; }
		$xml .= "\t<atom:link href=\"http://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']."\" rel=\"self\" type=\"application/rss+xml\" />\n";
		if (isset($this->myLink)) { $xml .= "\t<link>".$this->myLink."</link>\n"; }
		if (isset($this->myDescription)) { $xml .= "\t<description>".$this->myDescription."</description>\n"; }
		if (isset($this->myImage)) { $xml .= "\t<itunes:image href=\"".$this->myImage."\" />\n"; }
		if (isset($this->myPubDate)) { $xml .= "\t<pubDate>".$this->myPubDate."</pubDate>\n"; }

		// if there are any items to add to the feed, let's do it
		if (sizeof($items) >0) { 

			// sort items
			usort($items, array($this,"__compare_items"));		

			// if desired, splice items into an array of the specified size
			if (isset($limit)) { array_splice($items, intval($limit)); }

			// now let's convert all of our items to XML	
			for ($i=0; $i<sizeof($items); $i++) { 
				$xml .= $items[$i]->asXML() ."\n";
			}


		}
		$xml .= "</channel>\n</rss>";

		// if output is desired print to screen
		if ($output) { echo $xml; }

		// if user wants results returned as a string, do so
			if ($return_as_string) { 
				return $xml;
			}

	}

	// checks if we have an atom or rss feed
	private function __convert_to_rss($feed) {
		if ($feed->getName() == 'feed') {
			$xsl = simplexml_load_file('atom2rss.xsl');
			$xslt = new XSLTProcessor();
			$xslt->registerPHPFunctions();
			$xslt->importStyleSheet($xsl);
			return simplexml_load_string($xslt->transformToXml($feed));
		  } else {
			return $feed;
		  };
  
	}

	// compares two items based on "pubDate"	
	private function __compare_items($a,$b) {
		return strtotime($b->pubDate) - strtotime($a->pubDate);
	}

	// retrieves contents from a cache file ; returns null on error
	private function __fetch_rss_from_cache($cache_file) { 
		if (file_exists($cache_file)) { 
			return simplexml_load_file($cache_file);
		}
		return null;
	}

	// retrieves contents of an external RSS feed ; implicitly returns null on error
	private function __fetch_rss_from_url($url) {
		// Create new SimpleXMLElement instance
		try {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_SSLVERSION,6);
			curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->fetch_timeout);
			$fp = $this->curl_exec_follow($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ( ! curl_errno($ch) && $code >= 200 && $code < 300) {
				$sxe = simplexml_load_string($fp);
			} else {
				error_log("cannot load feed " . $url . ", with cause: " . curl_errno($ch) . " or error code " .$code);
				$sxe = false;
			}
			curl_close($ch);
			return $sxe;
		} catch (Exception $e) {
			return null;
		}
	}

	// creates a key for a specific feed url (used for creating friendly file names)
	private function __create_feed_key($url) { 
		return preg_replace('/[^a-zA-Z0-9\.]/', '_', $url) . 'cache';
	}

	private function curl_exec_follow(/*resource*/ $ch, /*int*/ &$maxredirect = null) { 
		$mr = $maxredirect === null ? 5 : intval($maxredirect);
		if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) { 
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $mr > 0); 
			curl_setopt($ch, CURLOPT_MAXREDIRS, $mr); 
		} else { 
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 
			if ($mr > 0) { 
				$newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); 

				$rch = curl_copy_handle($ch); 
				curl_setopt($rch, CURLOPT_HEADER, true); 
				curl_setopt($rch, CURLOPT_NOBODY, true); 
				curl_setopt($rch, CURLOPT_FORBID_REUSE, false); 
				curl_setopt($rch, CURLOPT_RETURNTRANSFER, true); 
				do {
					curl_setopt($rch, CURLOPT_URL, $newurl);
					$header = curl_exec($rch);
					if (curl_errno($rch)) { 
						$code = 0; 
					} else { 
						$code = curl_getinfo($rch, CURLINFO_HTTP_CODE); 
						if ($code == 301 || $code == 302) { 
							preg_match('/[Ll]ocation:(.*?)\n/', $header, $matches); 
							$newurl = trim(array_pop($matches)); 
						} else { 
							$code = 0; 
						} 
					} 
				} while ($code && --$mr); 
				curl_close($rch); 
				if (!$mr) { 
					if ($maxredirect === null) { 
						trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING); 
					} else { 
						$maxredirect = 0; 
					} 
					return false; 
				}
				curl_setopt($ch, CURLOPT_URL, $newurl); 
			} 
		} 
		return curl_exec($ch); 
	}

}

