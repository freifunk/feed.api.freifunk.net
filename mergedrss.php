<?php
class MergedRSS
{
	private $myFeeds = null;
	private $myTitle = null;
	private $myLink = null;
	private $myDescription = null;
	private $myImage = null;
	private $myPubDate = null;
	private $myCacheTime = null;
	private $fetch_timeout = null; //timeout for fetching urls in seconds (floating point)

	// create our Merged RSS Feed
	public function __construct($feeds = null, $channel_title = null, $channel_link = null, $channel_description = null, $channel_image = null, $channel_pubdate = null, $cache_time_in_seconds = 3600, $fetch_timeout = 2.0)
	{
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
	public function export($return_as_string = true, $output = false, $limit = null, $community = 'all')
	{
		// initialize a combined item array for later
		$items = array();
		$urls_to_fetch = [];
		$feed_cache = [];

		// loop through each feed
		foreach ($this->myFeeds as $feed_array) {
			if ($community !== 'all' && !in_array($community, $feed_array[3])) {
				continue;
			}

			$feed_url = $feed_array[0];
			$cache_file = "cache/" . $this->__create_feed_key($feed_url);

			// determine whether or not I should use the cached version of the xml
			if (file_exists($cache_file) && (time() - filemtime($cache_file) < $this->myCacheTime)) {
				$sxe = $this->__fetch_rss_from_cache($cache_file);
				$feed_cache[$feed_url] = $sxe->channel->item;
			} else {
				$urls_to_fetch[] = $feed_url;
			}
		}

		// Fetch all feeds in parallel
		$fetchedFeeds = $this->__fetch_rss_from_urls($urls_to_fetch);

		// Process the fetched feeds and update cache
		foreach ($fetchedFeeds as $feed_url => $sxe_raw) {
			if ($sxe_raw !== false) {
				$sxe = $this->__convert_to_rss($sxe_raw);
				if (is_object($sxe)) {
					$cache_file = "cache/" . $this->__create_feed_key($feed_url);
					$sxe->asXML($cache_file);
					$feed_cache[$feed_url] = $sxe->channel->item;
				}
			}
		}

		// Combine items from cache and fetched feeds
		foreach ($this->myFeeds as $feed_array) {
			if ($community !== 'all' && !in_array($community, $feed_array[3])) {
				continue;
			}

			$feed_url = $feed_array[0];
			$results = $feed_cache[$feed_url] ?? null;

			if (isset($results)) {
				foreach ($results as $item) {
					if (trim($item->title) == '') {
						print_r($item);
						continue;
					}
					$item->title = html_entity_decode($item->title, ENT_QUOTES, 'UTF-8');
					$source = $item->addChild('source', '' . $feed_array[1]);
					$source->addAttribute('url', $feed_array[2]);
					$items[] = $item;
				}
			}
		}

		// Sort items
		usort($items, array($this, "__compare_items"));

		// If desired, splice items into an array of the specified size
		if (isset($limit)) {
			array_splice($items, intval($limit));
		}

		// Convert all items to XML
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<rss version=\"2.0\">\n<channel>\n";
		$xml .= "<title>{$this->myTitle}</title>\n";
		$xml .= "<link>{$this->myLink}</link>\n";
		$xml .= "<description>{$this->myDescription}</description>\n";
		if (isset($this->myPubDate)) {
			$xml .= "\t<pubDate>{$this->myPubDate}</pubDate>\n";
		}
		foreach ($items as $item) {
			$xml .= $item->asXML() . "\n";
		}
		$xml .= "</channel>\n</rss>";

		// If output is desired, print to screen
		if ($output) {
			echo $xml;
		}

		// If user wants results returned as a string, do so
		if ($return_as_string) {
			return $xml;
		}
	}

	// checks if we have an atom or rss feed
	private function __convert_to_rss($feed)
	{
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
	private function __compare_items($a, $b)
	{
		return strtotime($b->pubDate) - strtotime($a->pubDate);
	}

	// retrieves contents from a cache file ; returns null on error
	protected function __fetch_rss_from_cache($cache_file)
	{
		if (file_exists($cache_file)) {
			return simplexml_load_file($cache_file);
		}
		return null;
	}

	// retrieves contents of an external RSS feed ; implicitly returns null on error
	protected function __fetch_rss_from_url($url)
	{
		// Create new SimpleXMLElement instance
		try {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_SSLVERSION, 6);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->fetch_timeout);
			$fp = $this->curl_exec_follow($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if (! curl_errno($ch) && $code >= 200 && $code < 300) {
				$sxe = simplexml_load_string($fp);
			} else {
				error_log("cannot load feed " . $url . ", with cause: " . curl_errno($ch) . " or error code " . $code);
				$sxe = false;
			}
			curl_close($ch);
			return $sxe;
		} catch (Exception $e) {
			return null;
		}
	}

	// retrieves contents of multiple external RSS feeds in parallel
	protected function __fetch_rss_from_urls($urls, $max_redirects = 5)
	{
		$multiHandle = curl_multi_init();
		$curlHandles = [];
		$results = [];
		$redirects = [];

		// Initialize cURL handles and add them to the multi handle
		foreach ($urls as $url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_SSLVERSION, 6);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->fetch_timeout);
			curl_setopt($ch, CURLOPT_HEADER, true); // Header mit auslesen
			curl_setopt($ch, CURLOPT_NOBODY, false); // Body mit auslesen
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Wir folgen manuell

			curl_multi_add_handle($multiHandle, $ch);
			$curlHandles[$url] = $ch;
			$redirects[$url] = 0; // Redirect-Zähler
		}

		$running = null;
		do {
			curl_multi_exec($multiHandle, $running);
			curl_multi_select($multiHandle);
		} while ($running > 0);

		// Verarbeite die Antworten
		foreach ($curlHandles as $url => $ch) {
			$response = curl_multi_getcontent($ch);
			$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$header = substr($response, 0, $header_size);
			$body = substr($response, $header_size);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

			// Prüfe auf Redirect (301, 302)
			if (preg_match('/^Location:\s*(.*)$/mi', $header, $matches) && in_array($http_code, [301, 302])) {
				$new_url = trim($matches[1]);
				if ($redirects[$url] < $max_redirects) {
					// Neuen Request für die weitergeleitete URL starten
					$redirects[$url]++;
					curl_multi_remove_handle($multiHandle, $ch);
					curl_close($ch);

					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $new_url);
					curl_setopt($ch, CURLOPT_SSLVERSION, 6);
					curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_TIMEOUT, $this->fetch_timeout);
					curl_setopt($ch, CURLOPT_HEADER, true);
					curl_setopt($ch, CURLOPT_NOBODY, false);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

					curl_multi_add_handle($multiHandle, $ch);
					$curlHandles[$new_url] = $ch;
					$redirects[$new_url] = $redirects[$url];

					unset($curlHandles[$url]); // Altes Handle entfernen
					continue;
				} else {
					error_log("Max Redirects für $url erreicht.");
				}
			}

			// Falls kein Redirect mehr: Parsen und abspeichern
			if ($http_code >= 200 && $http_code < 300) {
				$results[$url] = simplexml_load_string($body);
			} else {
				error_log("Fehler beim Laden von $url mit HTTP-Code: $http_code");
				$results[$url] = false;
			}

			curl_multi_remove_handle($multiHandle, $ch);
			curl_close($ch);
		}

		curl_multi_close($multiHandle);
		return $results;
	}


	// creates a key for a specific feed url (used for creating friendly file names)
	private function __create_feed_key($url)
	{
		return preg_replace('/[^a-zA-Z0-9\.]/', '_', $url) . 'cache';
	}

	private function curl_exec_follow(/*resource*/$ch, /*int*/ &$maxredirect = null)
	{
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
