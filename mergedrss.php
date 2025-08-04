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
		$xml .= "<rss version=\"2.0\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:wfw=\"http://wellformedweb.org/CommentAPI/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:sy=\"http://purl.org/rss/1.0/modules/syndication/\" xmlns:slash=\"http://purl.org/rss/1.0/modules/slash/\" xmlns:itunes=\"http://www.itunes.com/DTDs/Podcast-1.0.dtd\" xmlns:media=\"http://search.yahoo.com/mrss/\" xmlns:psc=\"http://podlove.org/simple-chapters\" xmlns:fh=\"http://purl.org/syndication/history/1.0\" xmlns:podcast=\"https://podcastindex.org/namespace/1.0\" xmlns:cc=\"http://cyber.law.harvard.edu/rss/creativeCommonsRssModule.html\" xmlns:friends=\"wordpress-plugin-friends:feed-additions:1\" xmlns:discourse=\"http://www.discourse.org/\" >\n";
		$xml .= "<generator>Freifunk API Feed Aggregator with the help of https://atom.geekhood.net/</generator>\n";
		$xml .= "<channel>\n";
		$xml .= "\t<title>{$this->myTitle}</title>\n";
		$xml .= "\t<atom:link href=\"http://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']."\" rel=\"self\" type=\"application/rss+xml\" />\n";
		$xml .= "\t<link>{$this->myLink}</link>\n";
		$xml .= "\t<description>{$this->myDescription}</description>\n";
		if (isset($this->myImage)) { $xml .= "\t<itunes:image href=\"".$this->myImage."\" />\n"; }
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
		$results = [];
		$pending_urls = $urls;
		$max_concurrent = 10; // Limit concurrent requests to avoid overwhelming servers
		
		while (!empty($pending_urls)) {
			$batch = array_slice($pending_urls, 0, $max_concurrent);
			$pending_urls = array_slice($pending_urls, $max_concurrent);
			
			$batch_results = $this->__fetch_batch_with_redirects($batch, $max_redirects);
			$results = array_merge($results, $batch_results);
		}
		
		return $results;
	}
	
	// Fetch a batch of URLs in parallel with proper redirect handling
	private function __fetch_batch_with_redirects($urls, $max_redirects = 5)
	{
		$multiHandle = curl_multi_init();
		$batchState = $this->__initialize_batch_state($urls, $multiHandle);
		
		$active_handles = count($urls);
		
		while ($active_handles > 0) {
			$this->__execute_multi_curl($multiHandle);
			$active_handles = $this->__process_completed_requests($multiHandle, $batchState, $max_redirects, $active_handles);
		}
		
		curl_multi_close($multiHandle);
		return $batchState['results'];
	}
	
	// Initialize the state for a batch of requests
	private function __initialize_batch_state($urls, $multiHandle)
	{
		$curlHandles = [];
		$url_to_original = [];
		$redirect_count = [];
		$results = [];
		
		foreach ($urls as $url) {
			$ch = $this->__create_curl_handle($url);
			curl_multi_add_handle($multiHandle, $ch);
			
			$curlHandles[$url] = $ch;
			$url_to_original[$url] = $url;
			$redirect_count[$url] = 0;
		}
		
		return [
			'curlHandles' => $curlHandles,
			'url_to_original' => $url_to_original,
			'redirect_count' => $redirect_count,
			'results' => $results
		];
	}
	
	// Create a configured cURL handle
	private function __create_curl_handle($url)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSLVERSION, 6);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->fetch_timeout);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		
		return $ch;
	}
	
	// Execute the multi-curl operations
	private function __execute_multi_curl($multiHandle)
	{
		$running = null;
		do {
			curl_multi_exec($multiHandle, $running);
			curl_multi_select($multiHandle);
		} while ($running > 0);
	}
	
	// Process all completed requests
	private function __process_completed_requests($multiHandle, &$batchState, $max_redirects, $active_handles)
	{
		while ($info = curl_multi_info_read($multiHandle)) {
			if ($info['msg'] == CURLMSG_DONE) {
				$active_handles = $this->__handle_completed_request($multiHandle, $batchState, $max_redirects, $info['handle'], $active_handles);
			}
		}
		
		return $active_handles;
	}
	
	// Handle a single completed request
	private function __handle_completed_request($multiHandle, &$batchState, $max_redirects, $ch, $active_handles)
	{
		$current_url = array_search($ch, $batchState['curlHandles']);
		$original_url = $batchState['url_to_original'][$current_url];
		
		$response = curl_multi_getcontent($ch);
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
		if ($this->__is_redirect($http_code, $header)) {
			return $this->__handle_redirect($multiHandle, $batchState, $max_redirects, $ch, $current_url, $original_url, $header, $active_handles);
		} else {
			return $this->__handle_final_response($multiHandle, $batchState, $ch, $current_url, $original_url, $http_code, $body, $active_handles);
		}
	}
	
	// Check if response is a redirect
	private function __is_redirect($http_code, $header)
	{
		return in_array($http_code, [301, 302]) && preg_match('/^Location:\s*(.*)$/mi', $header, $matches);
	}
	
	// Handle redirect response
	private function __handle_redirect($multiHandle, &$batchState, $max_redirects, $ch, $current_url, $original_url, $header, $active_handles)
	{
		preg_match('/^Location:\s*(.*)$/mi', $header, $matches);
		$new_url = trim($matches[1]);
		
		if ($batchState['redirect_count'][$original_url] < $max_redirects) {
			$batchState['redirect_count'][$original_url]++;
			$this->__replace_handle_with_redirect($multiHandle, $batchState, $ch, $current_url, $new_url, $original_url);
			return $active_handles; // Don't decrease active_handles
		} else {
			error_log("Max Redirects für $original_url erreicht.");
			$batchState['results'][$original_url] = false;
			$this->__cleanup_handle($multiHandle, $batchState, $ch, $current_url);
			return $active_handles - 1;
		}
	}
	
	// Replace handle with redirected URL
	private function __replace_handle_with_redirect($multiHandle, &$batchState, $ch, $current_url, $new_url, $original_url)
	{
		// Remove old handle
		curl_multi_remove_handle($multiHandle, $ch);
		curl_close($ch);
		unset($batchState['curlHandles'][$current_url]);
		
		// Create new handle for redirected URL
		$ch = $this->__create_curl_handle($new_url);
		curl_multi_add_handle($multiHandle, $ch);
		
		$batchState['curlHandles'][$new_url] = $ch;
		$batchState['url_to_original'][$new_url] = $original_url;
	}
	
	// Handle final (non-redirect) response
	private function __handle_final_response($multiHandle, &$batchState, $ch, $current_url, $original_url, $http_code, $body, $active_handles)
	{
		if ($http_code >= 200 && $http_code < 300) {
			$batchState['results'][$original_url] = simplexml_load_string($body);
		} else {
			error_log("Fehler beim Laden von $original_url mit HTTP-Code: $http_code");
			$batchState['results'][$original_url] = false;
		}
		
		$this->__cleanup_handle($multiHandle, $batchState, $ch, $current_url);
		return $active_handles - 1;
	}
	
	// Clean up a cURL handle
	private function __cleanup_handle($multiHandle, &$batchState, $ch, $current_url)
	{
		curl_multi_remove_handle($multiHandle, $ch);
		curl_close($ch);
		unset($batchState['curlHandles'][$current_url]);
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
