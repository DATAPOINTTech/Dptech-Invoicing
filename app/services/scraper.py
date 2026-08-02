import json
import re

import requests
from bs4 import BeautifulSoup

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
    ),
    "Accept-Language": "en-US,en;q=0.9,ur;q=0.8",
}

MAX_ITEMS = 200

_PRICE_TEXT_RE = re.compile(
    r"(?:PKR|Rs\.?|\u20a8|\u20b9|\u0631\u0627\u06cc\u0627\u0644|\u0631\s?\u0633|USD|\$|\u00a3|\u20ac)\s*([\d][\d,]*(?:\.\d+)?)",
    re.IGNORECASE,
)
_NUM_RE = re.compile(r"([\d][\d,]*(?:\.\d+)?)")
_PRICE_CLASS_RE = re.compile(r"\b(price|amount|mrp|rate|pricing|cost)\b", re.I)
_NAME_HINT_RE = re.compile(r"\b(title|name|heading|model)\b|[-_](?:name|title|heading|model)\b", re.I)
_CARD_RE = re.compile(
    r"\b(?:product-(?:card|grid-item|miniature|item|listing|container|wrapper|box|tile)"
    r"|(?:card|tile|listing|grid)-(?:item|wrapper|container)|thumbnail-container)\b",
    re.I,
)
_BANNER_RE = re.compile(
    r"\b(there are|there is|products? (found|available|total)|showing|results?|no products?"
    r"|manufacturer|sort by|filter by|compare|wishlist|out of stock|add to cart|read more|view cart)\b",
    re.I,
)
_DISCOUNT_ONLY_RE = re.compile(r"^[-+%.\s,\d]+$")
_HEADINGS = ["h1", "h2", "h3", "h4", "h5", "h6"]


def scrape_products(url: str, timeout: int = 20) -> list:
    """Fetch a URL and return a list of {name, unit_price, ...} dicts."""
    resp = requests.get(url, headers=HEADERS, timeout=timeout)
    resp.raise_for_status()
    soup = BeautifulSoup(resp.text, "html.parser")

    items = _extract_json_ld(soup)
    if len(items) >= 2:
        return _normalize(items)

    single = _extract_single_product(soup)
    if single:
        return _normalize(single)

    items = _extract_heuristic(soup)
    if items:
        return _normalize(items)

    return []


def _to_float(value) -> float:
    if value is None:
        return None
    if isinstance(value, (int, float)):
        try:
            return float(value)
        except (TypeError, ValueError):
            return None
    text = str(value).strip()
    if not text:
        return None
    m = _PRICE_TEXT_RE.search(text)
    if m:
        try:
            return float(m.group(1).replace(",", ""))
        except ValueError:
            return None
    nums = _NUM_RE.findall(text)
    if len(nums) == 1:
        try:
            return float(nums[0].replace(",", ""))
        except ValueError:
            return None
    return None


def _walk_json(node, out):
    if isinstance(node, list):
        for item in node:
            _walk_json(item, out)
    elif isinstance(node, dict):
        types = node.get("@type")
        if types == "Product" or (isinstance(types, list) and "Product" in types):
            out.append(node)
        else:
            for value in node.values():
                _walk_json(value, out)


def _offer_price(offer) -> float:
    if not isinstance(offer, dict):
        return None
    for key in ("price", "lowPrice", "highPrice"):
        price = _to_float(offer.get(key))
        if price and price > 0:
            return price
    spec = offer.get("priceSpecification")
    if isinstance(spec, dict):
        for key in ("price", "lowPrice", "highPrice"):
            price = _to_float(spec.get(key))
            if price and price > 0:
                return price
    return None


def _extract_json_ld(soup) -> list:
    products = []
    for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
        if not script.string:
            continue
        try:
            data = json.loads(script.string)
        except (json.JSONDecodeError, TypeError):
            continue
        found = []
        _walk_json(data, found)
        for p in found:
            price = _offer_price(p.get("offers"))
            name = p.get("name")
            if not name or not price:
                continue
            category = p.get("category")
            if isinstance(category, dict):
                category = category.get("name")
            if isinstance(category, list) and category:
                category = category[0]
            products.append(
                {
                    "name": name,
                    "unit_price": price,
                    "category": category if isinstance(category, str) else None,
                    "description": p.get("description"),
                }
            )
    return products


def _extract_single_product(soup) -> list:
    price_meta = soup.find("meta", property="product:price:amount") or soup.find(
        "meta", attrs={"name": "product:price:amount"}
    )
    og_title = soup.find("meta", property="og:title")
    name = og_title.get("content").strip() if og_title and og_title.get("content") else None
    price = _to_float(price_meta.get("content")) if price_meta else None
    if name and price and price > 0:
        return [{"name": name, "unit_price": price, "category": None, "description": None}]
    return []


def _clean_name(text: str) -> str:
    text = re.sub(r"\s+", " ", text or "").strip()
    return text[:200]


def _valid_name(text: str) -> bool:
    if not text or len(text) < 2 or len(text) > 200:
        return False
    if _DISCOUNT_ONLY_RE.match(text):
        return False
    if _BANNER_RE.search(text):
        return False
    if "http://" in text or "https://" in text or "www." in text:
        return False
    return True


def _is_card(el) -> bool:
    cls = " ".join(el.get("class") or [])
    return bool(_CARD_RE.search(cls))


def _find_name(price_el) -> str:
    cur = price_el
    for _ in range(12):
        if cur is None:
            return None
        name = _name_from_children(cur)
        if name:
            return name
        if _is_card(cur):
            return _name_from_subtree(cur)
        if cur.parent is None:
            break
        cur = cur.parent
    return None


def _name_from_children(el) -> str:
    for tag in el.find_all(_HEADINGS, recursive=False):
        text = _clean_name(tag.get_text(" ", strip=True))
        if _valid_name(text) and not _PRICE_TEXT_RE.search(text):
            return text
    for child in el.find_all(recursive=False):
        cls = " ".join(child.get("class") or []) + " " + (child.get("id") or "")
        if _NAME_HINT_RE.search(cls):
            text = _clean_name(child.get_text(" ", strip=True))
            if _valid_name(text) and not _PRICE_TEXT_RE.search(text):
                return text
    for img in el.find_all("img", recursive=False):
        alt = _clean_name(img.get("alt"))
        if _valid_name(alt):
            return alt
    return None


def _name_from_subtree(el) -> str:
    for tag in el.find_all(_HEADINGS):
        text = _clean_name(tag.get_text(" ", strip=True))
        if _valid_name(text) and not _PRICE_TEXT_RE.search(text):
            return text
    for child in el.find_all(True):
        cls = " ".join(child.get("class") or []) + " " + (child.get("id") or "")
        if not _NAME_HINT_RE.search(cls):
            continue
        text = _clean_name(child.get_text(" ", strip=True))
        if _valid_name(text) and not _PRICE_TEXT_RE.search(text):
            return text
    return None


def _extract_heuristic(soup) -> list:
    by_name = {}

    def consider(name, price):
        if not name or not price or price <= 0:
            return
        key = name.lower()
        if key not in by_name or price < by_name[key][1]:
            by_name[key] = (name, price)

    for el in soup.find_all(True):
        cls = " ".join(el.get("class") or [])
        el_id = el.get("id") or ""
        if _PRICE_CLASS_RE.search(cls + " " + el_id):
            text = el.get_text(" ", strip=True)
            if len(text) > 120:
                continue
            price = _to_float(text)
            if price and price > 0:
                name = _find_name(el)
                if name:
                    consider(name, price)

    for node in soup.find_all(string=True):
        if not _PRICE_TEXT_RE.search(node):
            continue
        price = _to_float(node)
        if not price or price <= 0:
            continue
        name = _find_name(node.parent)
        if name:
            consider(name, price)

    return [{"name": name, "unit_price": price} for name, price in by_name.values()]


def _normalize(items: list) -> list:
    seen = set()
    out = []
    for item in items:
        name = _clean_name(item.get("name"))
        price = _to_float(item.get("unit_price"))
        if not _valid_name(name) or not price or price <= 0:
            continue
        key = (name.lower(), price)
        if key in seen:
            continue
        seen.add(key)
        description = _clean_name(item.get("description")) or None
        out.append(
            {
                "name": name,
                "description": (description or None)[:500] if description else None,
                "category": _clean_name(item.get("category")) or "general",
                "unit": "pcs",
                "unit_price": price,
                "currency": "PKR",
            }
        )
        if len(out) >= MAX_ITEMS:
            break
    return out
