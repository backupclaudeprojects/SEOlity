---
title: Search Console Page indexing report — every reason and what to do
lang: en
topic: gsc-coverage
tags: search console, page indexing, coverage, redirect error, page with redirect, crawled currently not indexed, discovered, noindex, robots, 404, soft 404, canonical, validate fix, indexing, redirects, change impact
sources: https://support.google.com/webmasters/answer/7440203, https://developers.google.com/search/docs/crawling-indexing/301-redirects
updated: 2026-11-09
---

# Search Console Page indexing report

## Not indexed is not automatically bad
Cart, checkout, account, feeds, add-to-cart links, tracking-parameter URLs, theme internals and internal search should stay out of the index. The question is: which **important** page is not indexed, and why? Export the summary (timeline + reasons) and each reason's URL list, split URLs into intentional vs real pages, fix only the real ones, then Validate fix.

## Reasons
- **Redirect error:** loop, too long chain, empty/broken destination or too-long URL. Common after changing URLs twice (e.g. moving posts under /blog/ and back) or merging posts. Trace live, make every old URL reach the final page in one 301, delete rules that redirect a page that should be live, then validate.
- **Page with redirect:** normal for old URLs, http/www and trailing-slash variants. A problem only when the URL is in the sitemap, is a published post whose own address redirects (unpublish merged posts), or is still linked internally.
- **Not found (404):** redirect valuable URLs (links/traffic) to the closest page; 410 for gone content; ignore junk (fake URLs, robots.txt patterns like `/*`). A U+FFFD character means a link to a non-Latin URL was truncated somewhere.
- **Soft 404:** 200 status with empty/"not found" content — add content, return 404/410 or redirect.
- **Excluded by noindex:** correct for system pages; check landing/category pages.
- **Blocked by robots.txt:** correct for add-to-cart, feeds, admin, filters; never block a page you want removed — use noindex.
- **Crawled – currently not indexed:** feeds and API URLs are normal; for real pages improve uniqueness and internal links, ensure sitemap inclusion, request indexing.
- **Discovered – currently not indexed:** crawl budget / server speed / weak links.
- **Alternate page with proper canonical:** normal.
- **Duplicate, Google chose different canonical:** pages too similar — merge or differentiate.
- **5xx / 403 / 401 / other 4xx:** server or firewall blocking Googlebot — highest priority if important pages are affected.

## Reading the timeline
A jump in indexed pages followed by a drop usually means duplicates were created (e.g. two URLs per post) and later consolidated — compare with the count before the change, not the peak. Match every jump with your own change log.

## Validate fix
Validation fails if any URL still has the reason — expected for intentional exclusions; validate only after fixing real pages.

## Measuring a change
Compare equal windows after vs before the change, use the site-wide trend as control (expected = before × site after / site before), measure merged/moved URLs as one group plus the queries they ranked for, and require enough impressions before judging.
