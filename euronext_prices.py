#!/usr/bin/env python3
"""
Fetch Euronext (MATIF) futures prices by delivery month and print them as JSON,
in the same shape as the "marketPrices" block on artagro.lt.

Usage:
    python3 euronext_prices.py                    # wheat + rapeseed, print JSON
    python3 euronext_prices.py -o prices.json     # write to file
    python3 euronext_prices.py --html page.html --instrument wheat   # parse a saved response (for testing)

Only the Python standard library is needed.

IMPORTANT
- The endpoint below is the one Euronext's own website uses to fill its futures
  price table. It is NOT a documented public API, so it can change without notice.
  If the script returns empty quotes, open the product page in your browser,
  press F12 -> Network -> Fetch/XHR, reload, and copy the real request URL into
  ENDPOINT below.
- Euronext data is licensed. The public site shows delayed data for personal use;
  republishing it on a website may require a data licence from Euronext or a
  vendor. Check their terms before using this commercially.
"""

import argparse
import json
import re
import sys
import urllib.request
from datetime import datetime
from html.parser import HTMLParser

# Euronext product codes (Paris market).
INSTRUMENTS = {
    "wheat":    {"label": "Kviečiai", "code": "EBM-DPAR"},   # Milling Wheat No.2
    "rapeseed": {"label": "Rapsai",   "code": "ECO-DPAR"},   # Rapeseed
}

ENDPOINT = ("https://live.euronext.com/en/ajax/getPricesFuturesBlock/"
            "commodities-futures/{code}")

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; price-fetch/1.0)",
    "X-Requested-With": "XMLHttpRequest",
    "Accept": "text/html,application/json;q=0.9,*/*;q=0.8",
}

MONTHS = {"jan": "Jan", "feb": "Feb", "mar": "Mar", "apr": "Apr", "may": "May",
          "jun": "Jun", "jul": "Jul", "aug": "Aug", "sep": "Sep", "oct": "Oct",
          "nov": "Nov", "dec": "Dec"}


class TableParser(HTMLParser):
    """Collects every <tr> as a list of cell texts."""

    def __init__(self):
        super().__init__()
        self.rows, self._row, self._cell, self._in_cell = [], None, [], False

    def handle_starttag(self, tag, attrs):
        if tag == "tr":
            self._row = []
        elif tag in ("td", "th"):
            self._in_cell, self._cell = True, []

    def handle_endtag(self, tag):
        if tag in ("td", "th") and self._row is not None:
            self._row.append(" ".join("".join(self._cell).split()))
            self._in_cell = False
        elif tag == "tr" and self._row is not None:
            if self._row:
                self.rows.append(self._row)
            self._row = None

    def handle_data(self, data):
        if self._in_cell:
            self._cell.append(data)


def fetch(url):
    req = urllib.request.Request(url, headers=HEADERS)
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def parse_delivery(text):
    """'Dec 2026' / 'DEC-26' / 'Dec 26' -> 'Dec 2026'. Returns None if not a month."""
    m = re.search(r"\b([A-Za-z]{3})[a-z]*[\s\-/']*((?:20)?\d{2})\b", text)
    if not m or m.group(1).lower() not in MONTHS:
        return None
    year = m.group(2)
    if len(year) == 2:
        year = "20" + year
    return f"{MONTHS[m.group(1).lower()]} {year}"


def parse_price(text):
    """'234.25' / '234,25' / '1 234.25' -> '234.25'. Returns None if no number."""
    t = text.replace("\xa0", "").replace(" ", "")
    m = re.search(r"\d+(?:[.,]\d+)?", t)
    return m.group(0).replace(",", ".") if m else None


def extract_quotes(html):
    """
    Read the futures table. Looks at the header row to find the delivery-month
    column and the best price column (Last, otherwise Settlement).
    """
    parser = TableParser()
    parser.feed(html)
    rows = parser.rows
    if not rows:
        return []

    header = [c.lower() for c in rows[0]]
    month_col = next((i for i, c in enumerate(header) if "delivery" in c or "month" in c), 0)
    price_col = None
    for want in ("last", "settl"):
        price_col = next((i for i, c in enumerate(header) if want in c), None)
        if price_col is not None:
            break
    if price_col is None:
        price_col = 1

    quotes = []
    for row in rows[1:]:
        if max(month_col, price_col) >= len(row):
            continue
        delivery = parse_delivery(row[month_col])
        price = parse_price(row[price_col])
        if delivery and price:
            quotes.append({"delivery": delivery, "price": f"{float(price):.2f}"})
    return quotes


def build_entry(key, html, limit):
    info = INSTRUMENTS[key]
    quotes = extract_quotes(html)[:limit]
    return {
        "instrument": key,
        "label": info["label"],
        "currency": "EUR",
        "priceDate": datetime.now().strftime("%Y-%m-%d") if quotes else None,
        "quotes": quotes,
    }


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--instrument", choices=list(INSTRUMENTS), action="append",
                    help="wheat and/or rapeseed (default: both)")
    ap.add_argument("--limit", type=int, default=5, help="delivery months to keep (default 5)")
    ap.add_argument("--html", help="parse this saved HTML file instead of fetching")
    ap.add_argument("-o", "--output", help="write JSON to this file instead of stdout")
    args = ap.parse_args()

    keys = args.instrument or list(INSTRUMENTS)
    entries = []
    for key in keys:
        try:
            if args.html:
                with open(args.html, encoding="utf-8") as f:
                    html = f.read()
            else:
                html = fetch(ENDPOINT.format(code=INSTRUMENTS[key]["code"]))
        except Exception as exc:  # network error, 403, etc.
            print(f"[{key}] fetch failed: {exc}", file=sys.stderr)
            html = ""
        entry = build_entry(key, html, args.limit)
        if not entry["quotes"]:
            print(f"[{key}] no quotes found - check ENDPOINT / table layout", file=sys.stderr)
        entries.append(entry)

    result = {
        "marketPrices": entries,
        "marketPricesUpdatedAt": datetime.now().strftime("%Y-%m-%d %H:%M"),
    }
    out = json.dumps(result, ensure_ascii=False, indent=2)
    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(out + "\n")
    else:
        print(out)


if __name__ == "__main__":
    main()
