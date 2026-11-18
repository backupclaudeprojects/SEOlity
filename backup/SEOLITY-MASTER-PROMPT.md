# SEOlity — Master Product & Architecture Prompt

> Paste everything below into a capable AI (coding agent, architect, or designer).
> It describes **what** SEOlity is, **why** each part exists, **where** it must have access, the
> **SEO knowledge** it must embody, the **AI analysis** it performs, and the **complete UI design
> system**. Non-UI implementation code is intentionally left out: you choose the code, but every
> behavior, guarantee and screen described here is a requirement.

---

## 0. Your role and how to work

You are three senior people in one:

1. **WordPress plugin architect** — 15 years of PHP, WordPress internals, WooCommerce, REST, cron,
   shared hosting limits, security and performance.
2. **Head of SEO** — technical, on-page, content, e-commerce, off-page, local and AI-search SEO;
   fluent in Google's documentation and in how Google really behaves; experienced in the Iranian
   market as well as international markets.
3. **Product designer** — RTL-first, accessible, calm, data-dense interfaces for non-technical
   owners; motion with purpose.

Think like **an ordinary site owner who happens to have all the SEO knowledge in the world**: every
screen must answer "what is wrong, how much does it matter, what do I do now, and did it work?" in
plain language, with real numbers.

When you build or extend SEOlity:

- Read this whole document first. Treat every **MUST** as a requirement and every **SHOULD** as a
  strong default.
- Before writing code, produce: (a) the module map, (b) the data model, (c) the REST surface,
  (d) the screen list with states (loading / empty / error / success / partial), (e) the AI task
  list with JSON output schemas, (f) the test plan.
- Never invent data, metrics, URLs or results. If data is missing, the UI says exactly what is
  missing and links to where it is connected.
- Ship in small, verified steps. Each step: lint, unit/logic tests, UI tests with mocked API,
  translations complete, changelog, version bump.

---

## 1. Product in one paragraph

**SEOlity** is an AI SEO plugin for WordPress (with first-class WooCommerce, Rank Math, Yoast,
SEOPress, AIOSEO, Elementor, Gutenberg, Classic Editor and ACF support) whose single goal is to
**take the site to position 1 on Google for every search that matters, using real data**. It
crawls and understands the whole site, syncs Google Search Console, knows the competitors, learns
what works on *this* site, and turns everything into one prioritized road to #1. It prepares
every change with AI, but **nothing is changed until the owner approves**; every applied change
is verified on the live site, measured afterwards, and can be undone. Its default language is
Persian (RTL) and it works equally well in English and other languages.

### North star and mindset (MUST shape every feature and every AI answer)

- **Goal:** position 1 on Google per query, query by query, with honest feasibility.
- **Real data only:** Search Console, the site's own database, live HTTP checks, Google result
  pages from legitimate sources. Estimates are always labeled "estimate".
- **Strict but positive:** meticulous and demanding about problems, *and* it shows wins and
  progress, but only wins proven by data. No flattery, no false hope, no scare tactics (e.g. do
  not claim 300 404s hurt a small site's crawl budget).
- **Focus:** spend the owner's time only on what moves rankings or revenue; say explicitly what is
  *not* worth doing.
- **Owner in control:** preview → approve → apply → verify → measure → undo.
- **Teach while doing:** explain like a patient teacher by default (tone is configurable).

---

## 2. Non-negotiable principles

1. **Safety of the live site**
   - Every write goes through a change record or an operation with a stored "before" value.
   - Apply → verify on the live site (fetch the URL, check status, redirects, robots, canonical,
     the rendered value) → mark verified or "not confirmed" with the reason.
   - One-click undo for everything (content, meta, redirects, settings, statuses).
   - Never redirect or 410 a URL that answers 200; never create loops or chains; never redirect
     to the home page as a substitute for a real equivalent.
2. **Honesty**
   - Never fabricate numbers, competitors, rankings, reviews, prices or facts.
   - AI reasoning must cite the evidence it used (URLs, clicks, impressions, dates).
   - Confidence is shown (0–100 or high/medium/low) and low confidence is never presented as fact.
3. **Legality and platform rules**
   - Do not scrape Google from the server (it breaks Google's terms and gets CAPTCHA-blocked).
     Google results come from official/commercial APIs or from the owner's own browser
     (a bookmarklet that reads the result page the owner opened).
   - Respect robots, rate limits and the site's own server load.
4. **Privacy and secrets**
   - Secrets (API keys, OAuth tokens, subscription tokens, GitHub tokens) are stored encrypted,
     never logged, never sent to the browser, never put in diagnostics or the AI prompt.
   - Visitor data: IPs anonymized, retention configurable, nothing personal sent to AI.
5. **Permissions**
   - Capability tiers: **view** (see data), **ai** (spend AI credit), **apply** (approve changes),
     **manage** (settings, connections, destructive operations).
   - Every REST route declares its tier; every object write checks the user can edit that object.
6. **Works everywhere**
   - Shared hosting with 30 s requests and no shell.
   - Long work runs in a resumable background queue with checkpoints and retries.
   - Never block a page load.
7. **RTL-first and fully translatable**
   - Logical CSS properties; every string through the translation layer; Persian translation
     complete (0 missing) before release.
8. **Idempotent and observable**
   - Jobs are unique and resumable.
   - Every AI call, job and operation is visible in an Activity log with route, model, duration,
     tokens and result.

---

## 3. Access scope — what the plugin MUST be able to read and write

SEOlity is the site's in-house SEO department, so it needs the whole site.

### 3.1 Inside WordPress (read + write through WordPress APIs)

| Area | What SEOlity reads | What it may change (after approval) |
|---|---|---|
| Posts, pages, any public CPT | title, slug, content (all builders), excerpt, status, dates, author, template, featured image, revisions | title, content, excerpt, slug, status (publish/draft), featured image, internal links, FAQ blocks |
| WooCommerce products | name, descriptions, short description, price, stock, SKU, attributes, variations, categories, tags, gallery, reviews/ratings | descriptions, short description, tags, image ALT/titles, product SEO meta, schema |
| Taxonomies (categories, tags, product categories/brands, custom) | name, slug, description, parent, counts, archive URL | name, description, SEO meta, noindex |
| Users/authors | bio, display name, profile links | bio (E-E-A-T), author schema |
| Media library | file, size, dimensions, ALT, title, caption, description, attachment page | ALT, title, caption, description, file name suggestion |
| Menus & widgets | structure and links (for internal link analysis) | read only by default |
| Options | permalink structure, home/blog pages, WooCommerce shop page, reading settings ("discourage search engines"), site language | only plugin-owned options; risky WP options only through an explicit operation |
| Rewrite / robots / sitemaps | robots.txt (virtual or file), WP core sitemap, SEO plugin sitemaps | virtual robots.txt rules, sitemap inclusion/exclusion |
| Comments & reviews | content, rating, status | read only (review schema, sentiment insights) |
| SEO plugin data | Rank Math / Yoast / SEOPress / AIOSEO titles, descriptions, focus keywords (incl. secondary), robots, canonical, social, schema, redirects, pillar/cornerstone | written **through the active SEO plugin's own storage/model**, so its UI shows the same values and its "Update" never writes old values back |
| Page builders | Elementor data, Gutenberg blocks, Classic content, ACF text/WYSIWYG fields | inserted into the open editor (editor mode) or saved as a versioned change |
| Revisions | content history | used for "change impact" and rollback |

### 3.2 Database

- Read access to all WordPress core tables (posts, postmeta, terms, term relationships,
  termmeta, options, users, usermeta, comments) and to WooCommerce and SEO-plugin tables (e.g.
  the Rank Math redirections table).
- Always use prepared statements and the WordPress database layer.
- SEOlity owns its own tables. Conceptual data model:
  - **projects** (multi-site ready)
  - **urls** (crawl inventory: status, indexable, canonical, title, meta, H1, word count,
    inlinks/outlinks, depth, template, last crawl)
  - **issues/recommendations** (canonical issue list with category, priority, impact, effort,
    confidence, evidence, action, state)
  - **changes** (object, field, before, after, source, risk, scores before/after, status:
    pending / applied / applied_editor / rejected / reverted, batch)
  - **operations** (type, plan lines, preview items, approvals, results, rollback log)
  - **redirects** (source, target, type 301/302/307/308/410/451, regex, hits, origin, owner:
    SEOlity or "external" mirrored from Rank Math)
  - **not_found** (404 log: url, referrer, user agent, bot flag, hits, first/last seen,
    suggested target, score, status open/noise/redirected/ignored, verdict, verdict reason)
  - **gsc_daily**, **gsc_page_query** (date × page × query × clicks/impressions/position),
    **gsc_breakdowns** (device, country)
  - **serp_snapshots** and **serp_results** (query, engine, source, position, url, domain,
    title, snippet, own position)
  - **competitors** and **serp_analyses**
  - **experiments** and **follow_ups** (change, baseline, windows 7/14/28 days/long term,
    site-trend control, verdict, lesson)
  - **knowledge** (SEO knowledge documents with topic, market, language, sources, updated date)
  - **content_plan**, **clusters**, **roadmaps**
  - **jobs** (queue: hook, args, unique key, priority, attempts, checkpoint, status)
  - **activity** (every job/AI call/edit with steps, progress, route, model, tokens)
  - **chat** (conversations, messages, attached packages, created proposals)
  - **audit_log** (who did what, when)

### 3.3 Outside WordPress (connections the owner sets up once)

- **Google Search Console** (OAuth)
  - Search Analytics: daily sync of page × query, plus device/country breakdowns.
  - URL Inspection API (daily quota-aware watch).
  - Sitemaps status.
  - Import of the "Pages" (coverage) export ZIPs and of Search Console messages.
- **Google PageSpeed / Core Web Vitals** (field + lab).
- **Google results sources**
  - Serper, SearchApi, SerpApi, DataForSEO, Google Programmable Search.
  - A **no-key "your own browser" bookmarklet** that reads the Google results page the owner
    opened (incognito recommended) and sends titles/URLs/snippets/People-Also-Ask to SEOlity.
- **AI providers**, any of:
  - API keys: Anthropic, OpenAI, Gemini, OpenRouter, Ollama, any OpenAI-compatible endpoint,
    GitHub Models.
  - **Subscription mode**: the official Claude Code / Codex CLIs with the owner's subscription
    token, run either on the owner's private **GitHub Actions runner** (queue branch, encrypted
    jobs and results) or on the server itself when it allows it.
- **Cloudflare Worker relay** (optional) for faster, more reliable AI routing from restricted
  networks/hosts.
- **GitHub**
  - Runner repository (subscription AI).
  - Knowledge repository (a scheduled builder that collects SEO knowledge, competitor pages and
    the site brief).
  - Archive repository (backups of reports/snapshots).
- **Email** (WordPress mail) for alerts and reports.
- **Live self-fetch** of the site's own URLs (with a verification user agent and cache-busting)
  for verifying every applied change.

---

## 4. Architecture (behavioral, language-agnostic)

### 4.1 Layers

1. **Core**: settings (typed schema, defaults, validation, encrypted secrets), capabilities,
   database + migrations (versioned, additive), crypto, HTTP client with retries/timeouts,
   audit log, installer/upgrader.
2. **Services**, lazily constructed and individually testable. Groups:
   - **Data**: GSC sync & analytics, crawler & page parser, SERP service & sources, competitor
     intel, PageSpeed, knowledge base.
   - **Understanding**: content analyzer (local, no AI), page importance (critical / important /
     normal / minor / support block / unused), problem areas, full-site analysis, site map,
     coverage, indexability, duplicates & cannibalization, internal link graph, focus keywords,
     keyword research, health score, site brain (learned facts).
   - **Action**: change manager, optimizer (content/meta/bulk), product SEO, image SEO, schema,
     redirects manager, 404 monitor & triage, redirect chains, sitemap & robots, content
     planner, roadmap, rank-#1 service, agent (prepares changes for an issue), operations
     engine.
   - **Learning**: experiments, follow-ups (change impact with site-trend control), learning
     (pooled effects), site brain.
   - **Communication**: alerts (in-app + email by kind), daily briefing, reports, chat (with data
     tools and the ability to create proposals), activity & task dock.
   - **AI**: engine, task registry, provider manager, gateway/routing, remote subscription
     runner, local CLI, guardrails.
3. **Queue & scheduler**
   - Resumable jobs with unique keys, priorities, delays, checkpoints, max attempts and timeouts.
   - Kicked after the HTTP response (FastCGI finish) or by loop-back/cron.
   - "Deferred" AI results (subscription via GitHub) are retried by the queue until the answer
     arrives.
4. **REST API** (one namespace)
   - Every route declares its capability tier, validates and sanitizes input, and returns
     JSON errors with an HTTP status.
   - Expensive reads are cached for a short time.
5. **Admin app**
   - A single-page application without a build step (plain ES5/ES2015 modules attached to a
     global), one module per area, hash routing, strings from the translation layer.
6. **Editor integrations**
   - Buttons and panels inside Gutenberg, Classic, Elementor, term screens, user profile, media
     modal and ACF fields.

### 4.2 Core flows (MUST)

- **Change flow**: propose (before/after, reason, keywords, score before/after, risk) → owner
  reviews (diff, edit) → apply (or "apply to editor" when the editor is open; finalized on save)
  → verify live → follow-up measurement scheduled → undo available.
- **Operation flow**: create (type, title, owner instructions, input) → prepare in background
  (evidence gathering + AI) → staged preview (items each with checks, reasons, confidence,
  evidence) → owner ticks items and approves → apply item by item with a rollback log → verify
  each → results shown per item → undo all or part.
- **AI job flow**: start returns immediately with an activity id → a small task card ("dock")
  follows progress on any screen, survives reloads → finished card opens the report → owner
  applies or rejects → the outcome is written back to the card.
- **Learning loop**: every applied change gets baselines → measured at 7/14/28 days and long
  term against the whole site's trend (control) → verdict positive/negative/neutral/insufficient
  with confidence → lessons pooled by change type → fed into the site brain and into every AI
  prompt.

### 4.3 AI engine contract

- **Task registry**: each task has a version string, max tokens, knowledge topics, cache policy,
  instructions, and a **strict JSON output schema**. Code never parses free text.
- **System prompt builder** includes:
  - Site identity, market and content language.
  - The owner's chosen **tone**: teacher (default: simple, explains everything), friendly,
    professional or expert.
  - The **mindset line** (#1 on Google, check competitors, strict but positive, real data
    only).
  - The brand voice and editorial rules.
  - Relevant knowledge-base excerpts (with references shown to the owner).
  - Learned insights from the site brain and follow-ups.
  - Per-run owner instructions (which win over defaults, never over factual rules).
- **Guardrails**:
  - HTML cleaned to a safe whitelist.
  - Facts, prices, links, shortcodes and brand names are preserved.
  - Warnings for anything the owner must verify.
  - No H1 in body content.
  - Length appropriate to the field.
- **Routing**: direct API, Cloudflare relay, GitHub runner, or local CLI, chosen per settings and
  health. Budget limits (monthly) and response caching are configurable. Auth failures are
  recorded per credential fingerprint and stop further calls until a new login is saved.
- **Every answer** records route, model, prompt version, tokens, duration and knowledge refs.

---

## 5. The SEO knowledge SEOlity must embody

This is the expertise behind every check, score, recommendation and AI instruction. Encode it in
the knowledge base, in task instructions, and in local analyzers.

### 5.1 How Google really works (apply, never contradict)

- Crawling → rendering → indexing → ranking. Indexing is selective; "Crawled – currently not
  indexed" is a quality/duplication signal, not a technical error.
- Positions in Search Console are **impression-weighted averages** and lag 2–3 days.
  Seasonality, SERP features and personalization move them.
- **404 and 410** remove a URL from the index and do not hurt other pages. 410 is only a slightly
  faster explicit signal. Junk at 404 is fine.
- **Crawl budget** matters only for very large sites (≈10k+ frequently changing URLs) or when a
  large share of Googlebot requests hits dead URLs. Say so honestly.
- **301** passes signals only to a close equivalent (same topic/product/intent). An unrelated
  target or the home page is treated as a soft 404.
- **Redirect chains** cost one request per hop and dilute a little; loops are dead ends. Fix
  them, but they are rarely an emergency.
- **Canonical** is a hint; consistency across canonical, internal links, sitemap and
  hreflang decides.
- **noindex** in robots meta / X-Robots-Tag removes a page; robots.txt "Disallow" blocks
  crawling, not indexing.
- Helpful content, E-E-A-T (experience, expertise, authoritativeness, trust), search intent
  match, and **information gain** decide quality. Core updates re-evaluate sites as a whole.
- Duplicate and near-duplicate pages split signals (cannibalization). Consolidate, differentiate
  intent, or canonicalize.

### 5.2 Technical SEO checklist

- Status codes, soft 404s, redirect types and hygiene, chains and loops, 404 triage with value
  evidence (Search Console history, external referrers, internal links, Googlebot requests,
  deleted posts), scanner probe detection.
- Indexability: noindex, canonical mismatches, robots.txt, "discourage search engines", orphan
  pages, crawl depth, pagination, faceted navigation and parameter URLs, internal search pages,
  attachment pages, tag archives.
- Sitemaps: only indexable 200 canonical URLs; lastmod accuracy; split by type; submitted in
  Search Console.
- Performance: Core Web Vitals (LCP, INP, CLS) field vs lab, image weight and formats, lazy
  loading, caching, render-blocking resources, mobile usability.
- Structured data: Product (offers, availability, price, rating), Article/BlogPosting,
  FAQPage, HowTo where valid, BreadcrumbList, Organization/LocalBusiness, WebSite (sitelinks
  search), Person/Author, Review/AggregateRating. Validity and eligibility rules, no fake
  reviews.
- International: hreflang pairs, language/region signals, Persian/Arabic RTL, URL encoding of
  non-Latin slugs.
- Security and trust: HTTPS, mixed content, contact/about/policies pages.

### 5.3 On-page and content

- Search intent classes: informational / commercial investigation / transactional /
  navigational. Build the page type Google shows for that query (category vs article vs product
  vs comparison).
- SEO title ≤ 60 characters (pixel width aware), primary query near the start, benefit/qualifier,
  brand at the end if space allows.
- Meta description 120–158 characters: answers the intent, value proposition, soft CTA, no
  keyword stuffing.
- One H1. Logical H2/H3 outline. Intro that answers immediately. Lists/tables where useful.
  FAQ for real sub-questions.
- Semantic coverage: entities, related terms, sub-topics, People-Also-Ask questions.
- Internal linking: from relevant, authoritative pages, descriptive anchors, pillar ↔ cluster,
  no orphans, link equity to money pages.
- Images: descriptive ALT (not stuffed), file names, dimensions, compression.
- Freshness where the query deserves it. Author and experience signals.
- Topic clusters and pillar pages. Content gaps vs competitors. Consolidation of thin and
  overlapping posts. Differentiation of competing pages.
- Focus keywords: chosen from real queries (impressions, position 5–20 = striking distance), one
  primary + secondary per page, no two pages targeting the same cluster.

### 5.4 E-commerce SEO

- Category pages are the main ranking asset for "buy X" queries. They need an intro that helps
  choose, filters that don't create index bloat, and links to top products.
- Product pages: unique descriptions, specs, FAQs, reviews, schema with offers, out-of-stock
  handling (keep the page, show alternatives; 301 only for discontinued items with a true
  successor).
- Faceted navigation: index only valuable combinations.
- Marketplaces as competitors (e.g. Digikala, Torob in Iran): compete with depth, trust, speed
  and specialization.

### 5.5 Off-page, brand and local

- Backlinks: relevance and authority over volume. Digital PR, reportage (common in Iran),
  partnerships. Avoid link schemes.
- Brand searches and navigational demand as trust signals.
- Local SEO: Google Business Profile, NAP consistency, local schema, reviews.

### 5.6 Measurement and experimentation

- Expected CTR by position (≈ 28% at #1, 15% #2, 10.5% #3, 7.5% #4 … ≈ 2% #10) to estimate
  potential, always labeled as an estimate.
- Compare windows (28 vs previous 28 days). Control for site-wide trends. Minimum data
  thresholds before verdicts. Pooled learning across many small experiments.
- Striking distance (positions 4–15), CTR gaps vs the curve, cannibalization (two pages swapping
  for one query), query/page swaps over time.

### 5.7 AI search visibility

- Track whether AI Overviews / answer engines cite the site for tracked queries.
- Optimize for being quoted: clear definitions, facts with sources, structured answers, entity
  clarity.

### 5.8 Iranian market specifics (when market = Iran)

- Persian orthography:
  - Zero-width non-joiner (نیم‌فاصله).
  - Arabic vs Persian ی/ک.
  - Multiple spellings (find the dominant one in Search Console/Trends).
  - Finglish and English product names; colloquial vs formal phrasing.
- Google Ads Keyword Planner and many paid tools are unavailable or hard to pay for. Use Search
  Console, Trends (region IR), autocomplete, People-Also-Ask, and marketplace search
  suggestions (Torob, Digikala).
- Hosting and network realities: international connectivity issues and sanctions-limited
  services; prefer resilient routes (relay, runner).
- Local buying signals: "خرید", "قیمت", "ارزان", "فروش", "سفارش"; comparison signals: "بهترین",
  "مقایسه", "فرق".
- International market: standard global practice, hreflang, global competitors.

---

## 6. The AI's analysis of the site (what "understanding the site" means)

SEOlity MUST be able to produce, refresh and explain these views:

1. **Full-site analysis** (staged, resumable, progress shown per stage):
   1. Inventory: all URLs from the database, sitemaps and the crawl (templates, depth, status,
      indexability, canonical, word count, links).
   2. Search Console sync: 16 months where available; page × query; devices; countries.
   3. Page importance for every URL (money page, important, normal, minor, building block,
      unused) with the reasons.
   4. Issues engine: one canonical issue per real problem with evidence, priority, impact, effort
      and confidence; each issue knows the action that fixes it.
   5. Roadmap: the AI turns issues + opportunities into **one ordered path to #1**.
2. **Site map** — a visual map of the whole site:
   - Folders/sections with counts.
   - Indexable vs excluded share.
   - Templates.
   - Orphan pages.
   - Depth.
   - A live URL check for any address.
3. **Index coverage**:
   - Search Console "Pages" exports and messages, explained per reason (noindex, redirect
     error, not found, crawled-not-indexed, duplicate canonical…).
   - The fix for each.
   - Daily URL Inspection watch.
   - Redirect audit.
4. **Duplicates & cannibalization**:
   - Similar articles (content similarity + shared queries).
   - Merge (301 + draft the loser) or differentiate (new intent, title, H1 and focus keyword).
5. **Site brain** (what the AI has learned about *this* site):
   - Measured observations: growth, best weekday, CTR per position vs the curve, query types,
     position-1 and top-3 counts, striking distance, what the best pages have in common, devices.
   - AI beliefs with evidence and confidence, wins, open questions, experiments worth running,
     strategy.
   - Refreshed weekly and on demand; injected into every AI prompt.
6. **Road to #1, one search at a time**:
   - Every query with real position, trend, an honest chance (already #1 / very close /
     realistic / hard / long shot), intent and estimated gain.
   - Focus on one query: the AI compares the page with the result that ranks first and returns
     an honest verdict, weeks range, gaps, ≤ 6 steps (each preparable in one click), what to
     ignore, and how success will be measured.
   - Progress and wins only from Search Console after the focus started.
7. **Competitor intelligence**:
   - Tracked queries' SERP snapshots over time.
   - Competitor domains' share.
   - Page-vs-page comparison for a query ("can we overtake?").
   - Discovery of the queries competitors win.
8. **404 intelligence**:
   - Evidence-based sorting; scanner probes hidden as "not important".
   - AI triage (301 to a checked equivalent / 410 / keep 404 / fix the internal link) with an
     honest crawl-budget verdict.
9. **Redirect chains**:
   - A graph of chains and loops (including SEO-plugin rules).
   - Flattened to the final page, or the AI picks the best final page among checked live
     candidates (topic first, then business importance).
10. **Change impact**: every change measured against the site trend; lessons learned.
11. **Chat** with all site data:
    - Tools: page audit, query history, competitors, importance, settings, navigation links.
    - Can attach a package of selected items (e.g. 404 URLs) and create real proposals:
      roadmap task, prepared change, follow-up, notification, review of the attached items.

---

## 7. Feature catalogue (navigation and pages)

The navigation follows the "AI Writer" pattern: **every page is its own item** in both the
WordPress admin menu and the in-app sidebar, grouped under small group titles. There are **no
tabs-inside-tabs**. The two most important entry points are large tiles at the top of the
sidebar.

**Top tiles**
- **AI SEO Chat** — ask anything; answers grounded in site data; conversations persist; can act
  through proposals.
- **Roadmap ("What should I do today?")** — the treasure-map road to #1 (see §8.6).

**Overview**
- **Dashboard** — health score ring, Search Console KPIs (clicks, impressions, CTR, position
  with deltas), wins, top issues, recent activity, the daily briefing.
- **Notifications** — alerts by kind (critical, warning, success, info), email settings, links
  straight to the fix.
- **Activity** — every job/AI call/edit with steps, route, model, tokens, duration; retry/cancel.
- **AI Changes** — all pending/applied/rejected/reverted changes with diff, filters, bulk
  apply/reject, version history, undo.
- **Experiments** — experiments list, pooled learning, the **site brain** card, "think again".
- **Reports** — scheduled/emailed PDF-like summaries.

**Data collection**
- **Site Audit** — crawl status, issues by category, health score, page-level detail.
- **Search Console** — connect, sync status, queries/pages tables, movers, new/lost keywords,
  CTR and position opportunities, distribution, devices/countries.
- **Competitors** — Google results source, tracked queries and budget, competitor domains,
  query comparisons, AI visibility, the no-key bookmarklet.
- **Knowledge Base** — SEO knowledge documents (market and language aware), own rules,
  collection from GitHub, sync status.

**Content**
- **Content** — every page/post/product/term with SEO score, focus keyword, issues, bulk AI.
- **Duplicate & similar articles** — pairs and groups, merge or differentiate as operations.
- **Products** (WooCommerce) — product SEO scores, AI Product Optimizer, bulk.
- **Images** — missing/weak ALT, oversized files, bulk AI ALT/title/caption.

**Keywords & links**
- **Road to #1** — one search at a time (see §6.6).
- **Keywords** — research, clusters, intent, mapping keyword → page.
- **Rank Math & focus keywords** (or "Focus keywords") — coverage, choose from real Search
  Console data, write into the SEO plugin.
- **Internal Links** — link graph, orphans, suggestions with anchor and relevance, one-click
  insert.

**Technical SEO**
- **Index coverage** — exports, messages, findings, AI analysis, URL Inspection watch.
- **Redirects** — list (SEOlity + mirrored SEO-plugin rules), add/import/export, hits, **chains
  graph**.
- **404 Monitor** — evidence-sorted list (To review / Hidden: not important / Redirected /
  Ignored), bulk redirect to own targets, hide/bring back, AI decides for a selection, send a
  selection to chat, AI review of all.
- **Indexability & crawl budget** — noindex/canonical/robots conflicts, honest crawl-budget
  verdict.
- **Schema** — validation by URL, missing opportunities, generator.
- **Sitemap** — visual site map and sitemap health.
- **Robots.txt** — virtual robots editor with checks.

**Connections**
- **AI Engines** — installation & diagnostics of local CLIs.
- **Subscription AI** — one-click deploy of the GitHub runner, token save with sync to every
  repository copy, test.
- **GitHub & data** — repositories, knowledge collection, archive.
- **Cloudflare relay** — deploy and test.
- **Server & speed** — region, routes, warm runner minutes.
- **Connection guide** — step-by-step, bilingual, with troubleshooting tables.

**Settings** (each its own page)
- **General**
- **AI**: provider, models, effort, max tokens, timeout, monthly budget, cache, content
  language, **tone**, **SEO market** (Iran / international), brand voice, **default instruction
  for "Optimize with SEOlity AI"**, knowledge on/off, ALT on upload.
- **Crawler & audits**
- **Alerts & reports**
- **Technical**: redirects on/off, 404 logging, bots, front-end output.
- **Schema & entity**
- **Privacy & data**
- **Automation**: manual / semi / autonomous approval modes with rules.

**Editor integrations (inside WordPress screens)**
- **"Optimize with SEOlity AI"** on posts, pages, products, terms, author bios and ACF fields:
  - Rewrites the whole page (or writes it when empty) **and** writes the SEO title, meta
    description and focus keyword into the active SEO plugin (secondary keywords kept); an
    optional better H1.
  - Each run uses the default instruction plus the owner's instruction typed in the window.
  - Result: AI-suggested / original / compare (diff) / edit HTML tabs; before/after score;
    metrics; what changed and why; checkboxes for the extra fields.
  - "Apply to the page" fills the open editor and the SEO plugin's own fields, then reminds the
    owner to press Update.
- Meta box: live score ring, SERP preview, checks, GSC keywords, AI title & description, FAQ,
  internal links, E-E-A-T analysis, product optimizer, version history.
- Media modal: "Write ALT, title, caption & description with AI".

---

## 8. UI design system (MUST be followed exactly)

### 8.1 Principles

- Calm, trustworthy, data-dense but never cluttered. One primary action per area.
- **RTL-first**: logical properties (`margin-inline-start`, `inset-inline-end`, `border-inline-start`);
  icons that imply direction are mirrored; numbers, URLs and code are LTR islands
  (`dir="ltr"`); user text is `dir="auto"`.
- Every screen has: header (title + one-line purpose + actions), content, and explicit
  loading (skeleton/spinner with what is happening), empty (illustration icon + what to do +
  button), error (message + retry + link to fix) and partial-data states.
- Plain language first, jargon second (tooltip or "learn more").
- The owner is never left wondering: every long task shows a live step text and progress.

### 8.2 Design tokens

**Light theme**
| Token | Value |
|---|---|
| brand | `#1D4ED8` (600 `#1E40AF`, 2 `#3B82F6`, 50 `#EFF6FF`, 100 `#DBEAFE`) |
| bg | `#F5F6FA` |
| surface / surface-2 | `#FFFFFF` / `#F9FAFC` |
| border / border-strong / line | `#E6E8F0` / `#D7DBE7` / `#F0F2F7` |
| text / text-2 / muted / faint | `#0F172A` / `#334155` / `#64748B` / `#94A3B8` |
| good (+50) | `#059669` (`#ECFDF5`) |
| warn (+50) | `#D97706` (`#FFFBEB`) |
| orange | `#EA580C` |
| bad (+50) | `#DC2626` (`#FEF2F2`) |
| info (+50) | `#2563EB` (`#EFF6FF`) |
| chart series | `#1D4ED8`, `#0EA5E9`, `#10B981`, `#F59E0B`, `#EF4444` |
| radius / radius-sm | `16px` / `10px` |
| shadow | `0 1px 2px rgba(15,23,42,.04), 0 4px 16px rgba(15,23,42,.04)` |
| shadow-lg | `0 12px 40px rgba(15,23,42,.14)` |
| glass | `rgba(255,255,255,.88)` (sticky header with backdrop blur) |

**Dark theme** (toggle in the header, remembered per user; also follows the OS preference)
| Token | Value |
|---|---|
| brand | `#3B82F6` (600 `#93C5FD`, 2 `#60A5FA`, 50 `rgba(59,130,246,.14)`, 100 `rgba(59,130,246,.28)`) |
| bg / surface / surface-2 | `#0B1120` / `#111A2E` / `#16213A` |
| border / border-strong / line | `#23304D` / `#2E3D5F` / `#1C2842` |
| text / text-2 / muted / faint | `#E5E9F2` / `#CBD5E1` / `#94A3B8` / `#64748B` |
| good-50 / warn-50 / bad-50 / info-50 | 14% alpha tints of green / amber / red / blue |
| shadow | `0 1px 2px rgba(0,0,0,.4), 0 8px 24px rgba(0,0,0,.3)` |

Color is never the only signal: pair it with icon, text or shape. All text/background pairs meet
WCAG AA (4.5:1 body, 3:1 large and UI).

**Typography**
- Font stack: `"Vazirmatn", "IRANSans", Tahoma, system-ui, -apple-system, "Segoe UI", Roboto,
  sans-serif`. The web font is optional (setting) and falls back gracefully.
- Scale: page title 22–26 px / 800; section title 16–19 px / 700; body 14–15.5 px / 400, line
  height 1.8–1.9 for Persian; small 12–12.5 px; KPI numbers 22–28 px / 800 with tabular
  figures.
- Persian digits in Persian UI; Latin digits inside LTR islands (URLs, code).
- Wordmark: bold Latin "SEOLITY" on a brand-colored pill next to the white-label brand name.

**Spacing**: 4-px grid (4, 6, 8, 10, 12, 14, 16, 18, 20, 24, 28). Cards 16–20 px padding, 18 px
gap between cards.

**Icons**: one inline-SVG set, 24×24 grid, 1.75–2 px stroke, `currentColor` (dashboard, wand,
prompt/chat, pulse, chart, key, content, bag, image, link, unlink, corner (redirect), swords
(competitors), flask, undo, braces, map, bot, bell, zap, book, settings, target, lifebuoy,
sparkles, github, cloud, server, cpu, activity, eye-off, layers, copy, trending, trending-down,
rocket, search, external, info, check, check-circle, x-circle, clock, trash, refresh, send,
plus, filter, download, upload, lock, shield, globe, type, wrench …).

### 8.3 Layout

- **App shell**:
  - Sticky glass header: wordmark + version; status chips (approval mode, AI connected,
    route, Search Console connected, GitHub user); activity button; theme toggle; the primary
    CTA "What should I do today?".
  - Right sidebar in RTL (left in LTR): the two large tiles, then grouped items with icon +
    label, active item highlighted with a brand-50 background and an inline-start indicator bar.
  - Main column max ≈ 1200 px.
- **Mobile (< 782 px)**: the sidebar becomes a drawer (menu button in the header), cards stack,
  tables scroll horizontally inside their card, primary actions stay visible, tap targets ≥ 40 px.
- Content widths: forms ≤ 720 px; tables full width; KPI rows `repeat(auto-fit, minmax(170px, 1fr))`.

### 8.4 Components (all share the tokens)

- **Card / Panel**: surface, 1 px border, radius 16, soft shadow; optional title row with actions.
- **KPI tile**: label (muted, small), value (big, tabular), delta arrow ▲▼ with good/bad color,
  optional sparkline; hover lift 1–2 px.
- **Score ring**: conic gradient 0–100 (red < 50, amber < 80, green ≥ 80), number centered;
  before → after rings side by side with an arrow.
- **Badges**: `good`, `warn`, `bad`, `brand`, `critical`, `high`, `medium`, `low`, default;
  pill-shaped, 11–12 px, semibold.
- **Chips**: keywords, entities, filters; removable chips with ×; status chips with a dot.
- **Buttons**:
  - Primary (brand fill), default (surface + border), ghost, small, large, icon-only (with
    aria-label).
  - "Async button" shows a spinner and disables itself while its promise runs.
- **Inputs**: rounded 10 px, 1 px border, focus ring 3 px brand-100; textarea auto `dir`;
  select styled consistently; inline help text below.
- **Tables**:
  - Sticky header; zebra-free with hairline separators; first column can hold a checkbox with
    "select all".
  - A bulk-action bar appears above when items are selected ("N selected").
  - Pagination; empty state row.
- **Segmented control** (e.g. Today / After the fix): pill container, active segment lifted with
  surface + shadow.
- **Modal**:
  - Centered, wide variant up to 1100 px; header title + close.
  - Body scrolls; footer with actions aligned to the inline end and a hint aligned to the
    inline start.
  - Escape closes; focus is trapped and restored.
- **Toast**: bottom, auto-hide 4–6 s, kinds success/info/error, aria-live polite.
- **Task dock**:
  - Floating stack of small cards (bottom inline-start), max 3 visible + "+N more",
    minimizable.
  - Each card: title, subject, live step text, progress bar, elapsed time; finished cards
    show a summary + "Open report"; persists across screens and reloads.
- **Operation view**:
  - Plan lines at the top, owner instructions echoed.
  - Each item: checkbox (unticked by default when its checks failed), title, risk badge, link,
    before/after or diff, AI reasoning + confidence, evidence line, check result
    (✓ "Checked: the target is a live page and no loop is created" or ⚠ problems), and after
    applying a verified/not-confirmed result.
  - "Approve selected" and "Undo".
- **Diff view**: side-by-side or inline word diff; HTML preview in a sandboxed iframe.
- **Stepper / progress stages** for long analyses.
- **Alerts** (inline banners): info, success, warn, error, with an icon and an optional action.
- **Charts**:
  - Lightweight SVG line/area/bar charts with an axis in muted 11 px.
  - Tooltips on hover/focus.
  - Sparklines for weekly positions (rising line = better position); legends always text.
- **Empty states**: icon in a soft circle, title, one sentence, one button.
- **Command snippets**: monospace block with a copy button (for CLI steps in guides).

### 8.5 Microcopy and tone

- Default tone **teacher**: short sentences, explain why, define terms once, no blame.
- Always state the data source and freshness ("Search Console, last 28 days, ~2–3 days late").
- Estimates carry the word "estimate". Wins say what was measured.
- Buttons are verbs: "Prepare the fix", "Apply to the page", "Bring back", "Focus on this".
- Safety copy near every write: "Nothing changes until you approve."

### 8.6 Signature visuals and motion

- **Treasure-map roadmap**:
  - One winding SVG road from "Start" (data & analysis) through one station per area (ordered
    as the work must be done) to the "Goal" (position 1), with an alternating zig-zag layout.
  - Completed road segments are gold and draw themselves; pending segments are dotted.
  - Stations are dots with an icon and a counter; the current station pulses and carries a
    bobbing "you are here" pin; the goal glows.
  - Clicking a station opens its task panel.
  - Above the road: a mission hero with a progress ring, wins and the AI guide's diagnosis.
- **Redirect chain graph**: nodes as pills (path in mono, page title, importance badge), animated
  dashed arrows; final page outlined green; loops outlined red with ↺; "After the fix" shows
  every old address going straight to the final page with solid green arrows.
- **Road to #1 focus card**:
  - Start position → last 7 days → goal (#1) tiles.
  - A news strip colored by real progress (won / up / flat / down / waiting for data).
  - Weekly sparkline, "Google check" box, the AI plan with numbered steps and "Prepare it"
    buttons, "Not worth your time for this search", and Done / Skip buttons.
- **Site brain**: observation list with good/bad tinting, a wins box, AI beliefs with confidence.
- Motion rules:
  - 150–350 ms ease-out; no motion longer than 1 s except progress loops.
  - Everything respects `prefers-reduced-motion` (animations off, final state shown).

### 8.7 Accessibility (MUST)

- Keyboard reachable everything; visible focus ring; logical tab order in RTL.
- Landmarks (header, nav, main), headings in order, `aria-live` for progress and toasts,
  `aria-label` on icon buttons, table headers with scope, form labels bound to inputs.
- Hit targets ≥ 40 px on touch; contrast AA in both themes; no information by color alone.
- All charts have a text alternative (title, summary, or table).

---

## 9. AI behavior contract (system-level rules for every AI task)

1. Goal: position 1 on Google for the page's main intent; check what the top result does better.
2. Use only the provided data plus established SEO practice; cite numbers and URLs; when data is
   missing, say what is missing and how to get it.
3. Be strict and specific about problems, and name real wins when the data shows them.
4. Never invent facts, prices, reviews, statistics, certifications, competitors or URLs. Redirect
   targets and final pages must come from the checked candidates provided.
5. Output **only** the JSON required by the task schema; reasons in the site's language (Persian
   for Persian sites); simple words in the "teacher" tone.
6. Propose; never apply. Every proposal carries a reason, a confidence, and the evidence.
7. Prefer the few actions that move rankings; list what to ignore.
8. Respect owner instructions over defaults, never over safety and factual rules.
9. Respect page importance: no SEO work proposed for building blocks or unused templates.
10. Be honest about feasibility, time frames (weeks, not days) and uncertainty.

### Representative AI tasks (each with a strict schema)

- Content: optimize content (whole page + SEO title, meta description, focus keyword, optional
  H1), meta tags (3 options each, pick best), CTR rewrite (diagnosis + expected impact).
- Products and media: product SEO, image ALT, media SEO.
- Structure: FAQ generation, content brief, content plan, topic clusters, keyword research,
  search intent, keyword plan, internal links (sentence-level insertion).
- Duplicates: cannibalization, consolidate, differentiate page.
- Competitors and Google results: competitor summary, competitor query compare/discovery, SERP
  analysis.
- Quality: E-E-A-T analysis, schema suggestions.
- Redirects: redirect target, 404 triage, redirect chains.
- Indexing and measurement: coverage review, change impact review, experiment verdict.
- Strategy: site brain, SEO roadmap (mission: target queries, leader, gap, next move; wins),
  rank-#1 plan.
- Conversation: daily briefing, chat (answer + data used + suggested routes + proposals:
  task / change / follow-up / message / review of attached 404s).

---

## 10. Quality bar and acceptance criteria

- **Correctness**: every write verified live; every redirect checked (target 200, no loop, source
  not a live page unless merging); every AI target validated against candidates.
- **Data honesty**: no metric without source and period; estimates labeled; progress only from
  post-change data with minimum thresholds.
- **Performance**: admin screens interactive < 1.5 s on shared hosting; heavy work in the queue;
  no front-end slowdown (redirect lookup O(1) by hash, regex rules cached).
- **Resilience**: AI route fallbacks; auth failures stop retries until a new login is saved;
  queue jobs resumable; partial results shown.
- **i18n**: Persian translation 0 missing; RTL screenshots reviewed on desktop and mobile.
- **Tests**: logic tests for classifiers and rules (chance of #1, intent, probe detection, chain
  detection); UI tests with a mocked REST API for every page (desktop + 390 px mobile, no
  console errors); a manual verify checklist on a real site.
- **Release hygiene**: version bump, changelog, architecture notes, lessons learned, no secrets or
  licensed third-party files in the repository, ZIP built from the clean tree.

---

## 11. What I expect back from you

1. A short restatement of the product and the north star (≤ 10 lines) to prove you understood.
2. The module map (services and responsibilities) and the data model (tables, key fields, indexes).
3. The REST surface (route, method, capability tier, input, output).
4. The screen list with every state, wireframe-level descriptions, and the exact components and
   tokens used.
5. The AI task registry: names, versions, instructions (concise), and JSON schemas.
6. The background jobs: name, trigger, uniqueness, checkpoints, retries.
7. The test plan and acceptance checklist.
8. Then implement step by step, keeping every guarantee in §2 and the design system in §8.

---

## 12. Glossary

- **Change**: a reviewable edit of one field of one object, with before/after and undo.
- **Operation**: a reviewed batch of items (redirects, changes, settings) prepared in the
  background and applied after approval.
- **Verify**: fetch the live URL and confirm the applied effect.
- **Follow-up**: scheduled measurement of a change's effect against the site trend.
- **Striking distance**: queries where the page ranks about 4–15.
- **Page importance**: how much a URL matters (money page … unused building block).
- **Site brain**: what SEOlity has measured and concluded about this specific site.
- **Dock**: the floating task cards that follow background AI work.
- **Noise (404)**: logged 404s with no value signal, hidden from the review list.
- **Chain**: a redirect whose target redirects again; **loop**: a chain that returns to itself.
