#!/usr/bin/env python3
"""
clean-import.py
---------------
Merges the products + variations XML exports from the live Divi site,
strips all Divi/junk data, and outputs a single clean WXR file ready
to import into the new Gutenberg-based site.

Usage:
    python3 scripts/clean-import.py

Output:
    scripts/output/clean-import.xml
"""

import xml.etree.ElementTree as ET
import re
import os
import copy
from html import unescape

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

PRODUCTS_XML  = os.path.expanduser('~/Downloads/theblankbrand.WordPress.2026-06-03.xml')
VARIATIONS_XML = os.path.expanduser('~/Downloads/variations.xml')
OUTPUT_DIR    = os.path.join(os.path.dirname(__file__), 'output')
OUTPUT_FILE   = os.path.join(OUTPUT_DIR, 'clean-import.xml')

# Source of truth — only these 33 slugs will be included
SITEMAP_SLUGS = {
    "womens-pullover-fleece", "mens-tee-tie-dye", "mens-polo-tee",
    "kids-longsleeve-tee", "groms-ls-tee", "jogger-trackpants",
    "mens-tank", "mens-tank-pigment", "womens-tank", "womens-170g-boxy-tee",
    "womens-scoop-neck", "womens-tanner-tee", "womens-andi-tee",
    "womens-trackpant", "groms-classic-tee", "kids-classic-tee",
    "mens-tee-solid", "mens-tee-pigment", "mens-pocket-tee",
    "mens-longsleeve-tee", "mens-zip-thru", "mens-unisex-fleece-trackpant",
    "relax-pullover", "groms-pullover-fleece", "womens-boxy-tee",
    "boyfriend-tee", "mens-pullover-no-drawcord", "mens-boxy-hooded-pullover",
    "mens-pullover-fleece", "mens-crew-fleece", "mens-tee-heather",
    "mens-boxy-tee", "womens-crew-fleece",
}

# Meta keys to keep on products
PRODUCT_META_KEEP = {
    '_price',
    '_regular_price',
    '_sale_price',
    '_stock',
    '_stock_status',
    '_manage_stock',
    '_backorders',
    '_sold_individually',
    '_virtual',
    '_downloadable',
    '_download_limit',
    '_download_expiry',
    '_tax_status',
    '_tax_class',
    '_thumbnail_id',
    '_product_image_gallery',
    '_product_attributes',
    '_default_attributes',
}

# Meta keys to keep on variations
VARIATION_META_KEEP = {
    '_price',
    '_regular_price',
    '_sale_price',
    '_stock',
    '_stock_status',
    '_manage_stock',
    '_backorders',
    '_sold_individually',
    '_virtual',
    '_downloadable',
    '_download_limit',
    '_download_expiry',
    '_tax_status',
    '_tax_class',
    '_thumbnail_id',
    '_variation_description',
    'attribute_pa_colour',
    'attribute_pa_size',
}

# ---------------------------------------------------------------------------
# Namespaces
# ---------------------------------------------------------------------------

NS = {
    'content': 'http://purl.org/rss/1.0/modules/content/',
    'wp':      'http://wordpress.org/export/1.2/',
    'excerpt': 'http://wordpress.org/export/1.2/excerpt/',
    'dc':      'http://purl.org/dc/elements/1.1/',
    'wfw':     'http://wellformedweb.org/CommentAPI/',
}

# Register namespaces so ElementTree doesn't mangle them on output
for prefix, uri in NS.items():
    ET.register_namespace(prefix, uri)
ET.register_namespace('', 'http://www.w3.org/2005/Atom')

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def wp(tag):
    return f"{{{NS['wp']}}}{tag}"

def content_tag(tag):
    return f"{{{NS['content']}}}{tag}"

def excerpt_tag(tag):
    return f"{{{NS['excerpt']}}}{tag}"

def get_text(el, tag, namespaces=NS):
    child = el.find(tag, namespaces)
    return (child.text or '').strip() if child is not None else ''

def strip_divi(content):
    """Remove all Divi [et_pb_*] shortcodes and return clean content."""
    if not content or '[et_pb_' not in content:
        return content
    # Strip shortcode tags (opening, self-closing, closing)
    cleaned = re.sub(r'\[/?et_pb_[^\]]*\]', '', content)
    # Collapse excessive whitespace
    cleaned = re.sub(r'\n{3,}', '\n\n', cleaned).strip()
    return cleaned

def clean_content(content):
    """Strip Divi shortcodes and tidy up spacing/encoding artefacts."""
    content = strip_divi(content)
    # Remove non-breaking spaces used for visual padding in the pricing tables
    content = content.replace('\xa0', ' ')
    # Collapse runs of spaces
    content = re.sub(r'  +', ' ', content)
    # Tidy spacing inside tags
    content = re.sub(r'>\s+<', '>\n<', content)
    return content.strip()

def filter_meta(item_el, keep_keys):
    """Remove all wp:postmeta elements whose key is not in keep_keys."""
    to_remove = []
    for meta in item_el.findall(wp('postmeta')):
        key_el = meta.find(wp('meta_key'))
        if key_el is None or (key_el.text or '') not in keep_keys:
            to_remove.append(meta)
    for meta in to_remove:
        item_el.remove(meta)

def rewrite_url(url):
    """Swap live domain for local placeholder — importer will sideload images."""
    return url.replace('https://theblankbrand.co.za', 'https://theblankbrand.co.za')  # keep as-is; importer fetches live

# ---------------------------------------------------------------------------
# Parse source files
# ---------------------------------------------------------------------------

print("Parsing products XML…")
prod_tree = ET.parse(PRODUCTS_XML)
prod_root = prod_tree.getroot()
prod_channel = prod_root.find('channel')

print("Parsing variations XML…")
var_tree = ET.parse(VARIATIONS_XML)
var_root = var_tree.getroot()
var_channel = var_root.find('channel')

# ---------------------------------------------------------------------------
# Collect products — filter to sitemap slugs only
# ---------------------------------------------------------------------------

all_items = prod_channel.findall('item')
kept_products = []
kept_product_ids = set()
kept_attachments = []
excluded = []

for item in all_items:
    post_type = get_text(item, 'wp:post_type')
    if post_type == 'product':
        slug = get_text(item, 'wp:post_name')
        if slug in SITEMAP_SLUGS:
            kept_products.append(item)
            kept_product_ids.add(get_text(item, 'wp:post_id'))
        else:
            excluded.append((get_text(item, 'title'), slug))
    elif post_type == 'attachment':
        kept_attachments.append(item)

print(f"\nProducts:    {len(kept_products)} kept, {len(excluded)} excluded")
print(f"Attachments: {len(kept_attachments)} kept")
for title, slug in excluded:
    print(f"  EXCLUDED: {title} [{slug}]")

# ---------------------------------------------------------------------------
# Collect variations — only those whose parent is a kept product
# ---------------------------------------------------------------------------

all_variations = var_channel.findall('item')
kept_variations = []
skipped_variations = 0

for item in all_variations:
    post_type = get_text(item, 'wp:post_type')
    if post_type != 'product_variation':
        continue
    parent_id = get_text(item, 'wp:post_parent')
    if parent_id in kept_product_ids:
        kept_variations.append(item)
    else:
        skipped_variations += 1

print(f"Variations: {len(kept_variations)} kept, {skipped_variations} skipped")

# ---------------------------------------------------------------------------
# Clean products
# ---------------------------------------------------------------------------

print("\nCleaning products…")
divi_cleared = 0
pricing_kept = 0
empty_content = 0

for item in kept_products:
    # --- Clean post content ---
    content_el = item.find(content_tag('encoded'))
    if content_el is not None:
        raw = content_el.text or ''
        cleaned = clean_content(raw)
        content_el.text = cleaned
        if '[et_pb_' in raw:
            divi_cleared += 1
        elif cleaned:
            pricing_kept += 1
        else:
            empty_content += 1

    # --- Ensure excerpt is clean ---
    excerpt_el = item.find(excerpt_tag('encoded'))
    if excerpt_el is not None and excerpt_el.text:
        excerpt_el.text = clean_content(excerpt_el.text)

    # --- Strip junk meta ---
    filter_meta(item, PRODUCT_META_KEEP)

    # --- Ensure status is publish ---
    status_el = item.find(wp('status'))
    if status_el is not None:
        status_el.text = 'publish'

print(f"  Divi content cleared:  {divi_cleared}")
print(f"  Pricing HTML kept:     {pricing_kept}")
print(f"  Empty (no content):    {empty_content}")

# ---------------------------------------------------------------------------
# Clean variations
# ---------------------------------------------------------------------------

print("Cleaning variations…")
for item in kept_variations:
    filter_meta(item, VARIATION_META_KEEP)

    # Ensure status is publish
    status_el = item.find(wp('status'))
    if status_el is not None:
        status_el.text = 'publish'

# ---------------------------------------------------------------------------
# Collect taxonomy terms from products XML
# (categories, pa_colour, pa_size, product_type, product_cat)
# ---------------------------------------------------------------------------

print("Collecting taxonomy terms…")
terms = prod_channel.findall(wp('term'))
categories = prod_channel.findall(wp('category'))
tags = prod_channel.findall(wp('tag'))

# Also pull terms from variations XML in case any are missing
var_terms = var_channel.findall(wp('term'))
var_categories = var_channel.findall(wp('category'))

# ---------------------------------------------------------------------------
# Build output XML — clean WXR structure
# ---------------------------------------------------------------------------

print("Building output XML…")

# Start from a fresh channel built off the products file's header
output_root = ET.Element('rss', {
    'version': '2.0',
})

channel = ET.SubElement(output_root, 'channel')

# Channel metadata
def add_text(parent, tag, text):
    el = ET.SubElement(parent, tag)
    el.text = text
    return el

add_text(channel, 'title', 'The Blank Brand')
add_text(channel, 'link', 'https://theblankbrand.co.za')
add_text(channel, 'description', 'Premium blank apparel suppliers & printing house.')
add_text(channel, 'language', 'en-US')
add_text(channel, wp('wxr_version'), '1.2')
add_text(channel, wp('base_site_url'), 'https://theblankbrand.co.za')
add_text(channel, wp('base_blog_url'), 'https://theblankbrand.co.za')

# Author
author = ET.SubElement(channel, wp('author'))
add_text(author, wp('author_id'), '1')
add_text(author, wp('author_login'), 'TheBlankBrand')
add_text(author, wp('author_email'), 'info@theblankbrand.co.za')
add_text(author, wp('author_display_name'), 'TheBlankBrand')
add_text(author, wp('author_first_name'), '')
add_text(author, wp('author_last_name'), '')

# Copy taxonomy terms from source
seen_term_ids = set()
for term in terms + var_terms:
    term_id_el = term.find(wp('term_id'))
    if term_id_el is not None:
        tid = term_id_el.text
        if tid in seen_term_ids:
            continue
        seen_term_ids.add(tid)
    channel.append(copy.deepcopy(term))

# Copy woocommerce_attribute_taxonomies from options if present
# (these define pa_colour, pa_size globally)
for cat in categories + var_categories:
    channel.append(copy.deepcopy(cat))

# Append attachments first so IDs exist before products reference them
for item in kept_attachments:
    channel.append(copy.deepcopy(item))

# Append cleaned products
for item in kept_products:
    channel.append(copy.deepcopy(item))

# Append cleaned variations
for item in kept_variations:
    channel.append(copy.deepcopy(item))

# ---------------------------------------------------------------------------
# Write output
# ---------------------------------------------------------------------------

os.makedirs(OUTPUT_DIR, exist_ok=True)

tree = ET.ElementTree(output_root)
ET.indent(tree, space='\t', level=0)

with open(OUTPUT_FILE, 'wb') as f:
    f.write(b'<?xml version="1.0" encoding="UTF-8" ?>\n')
    f.write(b'<!-- WordPress WXR export cleaned for Gutenberg import -->\n')
    f.write(b'<!-- Generated by clean-import.py - Divi stripped, junk meta removed -->\n')
    tree.write(f, encoding='utf-8', xml_declaration=False)

size_kb = os.path.getsize(OUTPUT_FILE) / 1024
print(f"\n✅ Output written to: {OUTPUT_FILE}")
print(f"   File size: {size_kb:.1f} KB")
print(f"   Products:  {len(kept_products)}")
print(f"   Variations: {len(kept_variations)}")

# ---------------------------------------------------------------------------
# Sanity report
# ---------------------------------------------------------------------------

print("\n--- Sanity check ---")
check_tree = ET.parse(OUTPUT_FILE)
check_root = check_tree.getroot()
check_items = check_root.findall('.//item')
check_products   = [i for i in check_items if i.findtext(f"{{{NS['wp']}}}post_type") == 'product']
check_variations = [i for i in check_items if i.findtext(f"{{{NS['wp']}}}post_type") == 'product_variation']
check_attachments = [i for i in check_items if i.findtext(f"{{{NS['wp']}}}post_type") == 'attachment']

print(f"Products in output:    {len(check_products)}")
print(f"Variations in output:  {len(check_variations)}")
print(f"Attachments in output: {len(check_attachments)}")

# Check no Divi remains
divi_remaining = sum(1 for i in check_products
                     if '[et_pb_' in (i.findtext(f"{{{NS['content']}}}encoded") or ''))
print(f"Divi shortcodes remaining: {divi_remaining}")

# Check no junk meta remains
junk_meta_remaining = 0
for i in check_products + check_variations:
    for meta in i.findall(f"{{{NS['wp']}}}postmeta"):
        key = meta.findtext(f"{{{NS['wp']}}}meta_key") or ''
        if key.startswith('_et_') or key.startswith('et_') or key.startswith('product_adjustment'):
            junk_meta_remaining += 1
print(f"Junk meta remaining:       {junk_meta_remaining}")

# Variations per product
from collections import Counter
parent_counts = Counter(
    i.findtext(f"{{{NS['wp']}}}post_parent") for i in check_variations
)
print(f"Unique parents in variations: {len(parent_counts)} (expected 33)")
print("\nDone.")
