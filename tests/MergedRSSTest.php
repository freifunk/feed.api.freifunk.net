<?php

use PHPUnit\Framework\TestCase;

// Include the MergedRSS class
require_once __DIR__ . '/../mergedrss.php';

class MergedRSSTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock $_SERVER superglobal
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['PHP_SELF'] = '/feed.php';

        // Clear the cache directory
        $cacheDir = __DIR__ . '/../cache/';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '*'); // get all file names
            foreach ($files as $file) { // iterate files
                if (is_file($file)) {
                    unlink($file); // delete file
                }
            }
        }
    }

    public function testExport()
    {
        // Mock feeds
        $feeds = [
            ["http://example.com/feed1.xml", "Feed 1", "http://example.com", ["all"]],
            ["http://example.com/feed2.xml", "Feed 2", "http://example.com", ["all"]],
        ];

        // Create an instance of MergedRSS
        $mergedRSS = new MergedRSS($feeds, "Test Title", "http://example.com", "Test Description");

        // Mock the __fetch_rss_from_cache and __fetch_rss_from_urls methods
        $mergedRSS = $this->getMockBuilder(MergedRSS::class)
            ->setConstructorArgs([$feeds, "Test Title", "http://example.com", "Test Description"])
            ->onlyMethods(['__fetch_rss_from_cache', '__fetch_rss_from_urls'])
            ->getMock();

        // Mock the cache and URL fetch results
        $mockCacheResult = simplexml_load_string('<rss><channel><item><title>Cached Item</title></item></channel></rss>');
        $mockUrlResult = [
            "http://example.com/feed1.xml" => simplexml_load_string('<rss><channel><item><title>Fetched Item 1</title></item></channel></rss>'),
            "http://example.com/feed2.xml" => simplexml_load_string('<rss><channel><item><title>Fetched Item 2</title></item></channel></rss>')
        ];
        $mockNewUrlResult = [
            "http://example.com/feed3.xml" => simplexml_load_string('<rss><channel><item><title>New Fetched Item</title></item></channel></rss>')
        ];

        // First call: no cache, only fetched items
        $mergedRSS->method('__fetch_rss_from_cache')->willReturn(null);
        $mergedRSS->method('__fetch_rss_from_urls')->willReturn($mockUrlResult);

        $result = $mergedRSS->export(true, false, null, 'all');
        $this->assertStringContainsString('<title>Fetched Item 1</title>', $result);
        $this->assertStringContainsString('<title>Fetched Item 2</title>', $result);
        $this->assertStringNotContainsString('<title>Cached Item</title>', $result);

        // Add a new feed for the second call
        $feeds[] = ["http://example.com/feed3.xml", "Feed 3", "http://example.com", ["all"]];
        $mergedRSS = new MergedRSS($feeds, "Test Title", "http://example.com", "Test Description");

        // Second call: with cache for the first two feeds, and fetched item for the new feed
        $mergedRSS = $this->getMockBuilder(MergedRSS::class)
            ->setConstructorArgs([$feeds, "Test Title", "http://example.com", "Test Description"])
            ->onlyMethods(['__fetch_rss_from_cache', '__fetch_rss_from_urls'])
            ->getMock();

        $mergedRSS->method('__fetch_rss_from_cache')->willReturnCallback(function ($cache_file) use ($mockCacheResult) {
            return strpos($cache_file, 'feed3.xml') === false ? $mockCacheResult : null;
        });
        $mergedRSS->method('__fetch_rss_from_urls')->willReturnCallback(function ($urls) use ($mockUrlResult, $mockNewUrlResult) {
            $results = [];
            foreach ($urls as $url) {
                if (isset($mockUrlResult[$url])) {
                    $results[$url] = $mockUrlResult[$url];
                } elseif (isset($mockNewUrlResult[$url])) {
                    $results[$url] = $mockNewUrlResult[$url];
                }
            }
            return $results;
        });

        $result = $mergedRSS->export(true, false, null, 'all');
        $this->assertStringContainsString('<title>Cached Item</title>', $result);
        $this->assertStringNotContainsString('<title>Fetched Item 1</title>', $result);
        $this->assertStringNotContainsString('<title>Fetched Item 2</title>', $result);
        $this->assertStringContainsString('<title>New Fetched Item</title>', $result);
    }
}
