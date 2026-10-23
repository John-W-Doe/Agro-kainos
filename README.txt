EURONEXT WHEAT PRICES - upload guide
====================================

Files
-----
index.html           The web page (open this in the browser).
prices.php           Fetches the prices from Euronext on your server and returns JSON.
cache/.htaccess      Keeps the saved price files private. Upload the cache folder too.
euronext_prices.py   OPTIONAL. Python version of the fetcher, only for hosting where you can run
                     Python/cron. Not needed if your hosting supports PHP.

How to install
--------------
1. Upload ALL files and the "cache" folder to the same folder on your hosting
   (for example public_html/wheat/ ).
2. Make sure the "cache" folder is writable (permissions 755, or 775 if the page shows no prices).
3. Open https://your-domain/wheat/ in the browser.

Requirements: hosting with PHP 7.4 or newer (DOM extension, and either cURL or allow_url_fopen).
Almost all shared hosting has these.

How to know it works
--------------------
- Green label "live data" under the prices = prices came from Euronext through prices.php.
- Yellow label "embedded sample data" = the page could not load live prices and is showing
  built-in sample values (copied from artagro.lt, 22 Oct 2026). The yellow label also shows the
  reason.

If it shows sample data
-----------------------
1. Open https://your-domain/wheat/prices.php?debug=1
   - "error" not empty  -> your server cannot reach Euronext (hosting firewall) or Euronext blocked it.
   - "bytes" is 0 or small, or "quotes" is empty -> Euronext changed its page. Find the real address:
       a) open https://live.euronext.com/en/product/commodities-futures/EBM-DPAR in Chrome
       b) press F12 -> Network -> Fetch/XHR, reload the page
       c) find the request that returns the futures price table and copy its URL
       d) put that URL into ENDPOINT near the top of prices.php (keep %s where the product code goes,
          or paste the full URL for wheat only)
2. If the page itself shows an error mentioning "HTTP 404" for prices.php, the file was uploaded to a
   different folder than index.html.
3. If prices.php shows PHP source code in the browser, your hosting does not run PHP.

Notes
-----
- Prices refresh at most every 15 minutes (change CACHE_TTL in prices.php).
- If Euronext is down, the last saved prices are shown and marked as older.
- The "price date" shown is the day the prices were fetched, not necessarily the Euronext
  trading date. Euronext data is delayed on the public website.
- Euronext market data is licensed. Showing it on a public website may require a data
  licence from Euronext or a data vendor. Check their terms.
- Informational only; not an offer or trading advice.
