# PROJECT_MAP — SEOlity (ARIAGH.SEOLITY)

## 1. هدف پروژه
- افزونه وردپرس سئوی هوش‌مصنوعی (فارسی/RTL، سایت نمونه کاربر: benlifood.ir — فروشگاه محصولات تخمیری با Rank Math + Elementor + WooCommerce).
- PHP (PSR-4 `SEOlity\`)، رابط SPA بدون build در `seolity/assets/admin/*.js`، REST `seolity/v1`، صف کار داخلی.
- هوش مصنوعی: API (Anthropic/OpenAI/Gemini/…)، اشتراک Claude/ChatGPT از طریق CLI روی همین سرور یا اجراکننده GitHub Actions، رله Cloudflare.

## 2. وضعیت فعلی
- نسخه **1.19.1** (`seolity/seolity.php:6`)، DB schema **1.8.0** (`seolity.php:23`). شاخه `claude/beautiful-ride-7ilxpf`.
- 1.19.0: صفحه «مسیر رتبه ۱» (`Rank/RankOne.php`، `pages-rank1.js`: هر کوئری جدا، شانس صادقانه، برنامه هوش مصنوعی در برابر رتبه اول، پیشرفت فقط با داده سرچ کنسول)؛ ۴۰۴: دسته‌بندی با شواهد واقعی و پنهان‌کردن کاوش‌های اسکنر (`NotFoundTriage::classify`، ستون‌های verdict)، انتخاب و ارسال بسته به چت (`review_404s`)؛ زنجیره ریدایرکت گرافیکی + اصلاح با تأیید (`Redirects/Chains.php`، شامل قوانین رنک‌مث).
- 1.18.0: «بهینه‌سازی با هوش مصنوعی سئولیتی» کل صفحه را یکجا می‌سازد: محتوا (یا نوشتن از صفر)، عنوان سئو، توضیحات متا، کلمه کلیدی Rank Math/Yoast و H1 اختیاری؛ دستور پیش‌فرض (`ai_optimize_prompt`) + دستور کاربر؛ رفع اعمال‌نشدن در صفحه دسته‌بندی (`editor.js`: `termDescriptionAdapter`).
- 1.17.1: رفع «OAuth token revoked» بعد از خروج از همه دستگاه‌ها: ذخیره ورود جدید، Secret را در مخزن اجراکننده و مخزن دانش جایگزین و اجرای قدیمی را لغو می‌کند (`SubscriptionRunner::sync_secret`، `GitHubClient::cancel_run`).
- 1.17.0: «مسیر رتبه ۱» = یک مسیر واحد نقشه‌گنجی (`pages-roadmap.js`)؛ «ذهن سئوی سایت» (`Experiments/SiteBrain.php`، یادگیری تجمیعی آزمایش‌ها)؛ تنظیم لحن `ai_tone`؛ ذهنیت «سخت‌گیر ولی مثبت‌بین، هدف رتبه ۱»؛ سازنده دانش اول توکن اشتراک را استفاده می‌کند.
- 1.16.0: صفحه «پوشش ایندکس» (`GSC/Coverage.php`، `pages-coverage.js`): خروجی‌های Pages سرچ کنسول، پیام‌های سرچ کنسول، پایش روزانه URL Inspection، بازرسی ریدایرکت‌ها؛ «اثر تغییرات ۲.۰» (`Watch/FollowUps.php`: روز ۷/۱۴/۲۸/بلندمدت، کنترل روند کل سایت، اطمینان، تحلیل AI و درس‌ها)؛ بازار سئو ایران/بین‌المللی + ۱۴ سند دانش جدید (`knowledge/fa/iran/`).
- ناوبری 1.15.0 مثل AI Writer: هر صفحه یک آیتم جدا در منوی وردپرس و سایدبار، زیر عنوان گروه؛ بدون «تب بخش» بالای صفحه. چت و نقشه راه دو کاشی بزرگ بالای سایدبار. تنظیمات به ۷ صفحه جدا (`settings`، `settings-ai`، …) شکسته شد.
- کار می‌کند (با آزمایش در سایت آزمایشی؛ با حساب واقعی کاربر تأیید نشده):
  - CLIهای محلی: کشف Node.js، اجراکننده مرکزی، طبقه‌بندی خطا، ورود Codex با کد دستگاه، نصب Codex بدون npm، صفحه «موتورهای هوش مصنوعی» (#/engines) با انتخاب مدل.
  - مسیر خودکار: CLI محلی فقط بعد از «آزمایش اتصال» موفق؛ وگرنه گیت‌هاب؛ خرابی سروری ← بازگشت به گیت‌هاب.
  - اجراکننده گیت‌هاب در مخزن **عمومی** (دقیقه رایگان)، دانش و داده در مخزن **خصوصی** جدا.
  - چت: داده سطر به سطر سرچ کنسول (ابزار `gsc_opportunities`)، و «پیشنهاد اجرایی» (کار نقشه راه / تغییر برای بررسی و اعمال / پیگیری / پیام).
  - پیگیری‌ها، مرکز اعلان‌ها با ایمیل (فوری/خلاصه روزانه)، اهمیت صفحه.
- تأییدنشده در دنیای واقعی: ارسال واقعی ایمیل، درخواست واقعی Codex/Claude روی هاست کاربر، اجرای گیت‌هاب بعد از عمومی شدن مخزن.

## 3. نقشه فایل‌ها (فقط بخش‌های مهم؛ مسیرها نسبت به `seolity/`)
| مسیر | کار | نمادها (خط) |
|---|---|---|
| `seolity.php` | ورودی افزونه، نسخه | Version:6، SEOLITY_DB_VERSION:23 |
| `src/Admin/Admin.php` | منو: routes() (شامل settings-*)، nav() گروه‌ها (منبع واحد منوی وردپرس و سایدبار JS از `C.nav`)، ordered() | — |
| `src/Plugin.php` (296) | نقشه سرویس‌ها (lazy `seolity()->x`)، هوک‌ها | factories، hook `seolity_operation_finished`:226 |
| `src/Api/Router.php` (1631) | همه مسیرهای REST (`route()` با tier view/ai/manage) | cli/*، alerts/*، follow-ups، github/setup-all |
| `src/Queue/Jobs.php` (322) | handlerهای صف + `daily_pipeline`:234 | follow_ups_check:26، alerts_digest:30، cli_login:34، cli_install:37 |
| `src/AI/Tasks.php` (741) | تعریف تسک‌های AI + schema | `chat`:657 (chat@1.3 با `proposals`) |
| `src/AI/Gateway.php` (455) | مسیرها direct/cloudflare/github | current():129 (برای sub_* مسیر واقعی) |
| `src/AI/Remote/ProcessRunner.php` (308) | تنها محل اجرای برنامه؛ env، سقف حافظه | blocked:26 limits:45 vmem_mb:59 raise_limits:74 env:115 run:165 private_dir:249 |
| `src/AI/Remote/NodeLocator.php` (199) | کشف Node.js | search_paths:26 scan:70 probe:102 select:140 dirs:181 |
| `src/AI/Remote/CliErrors.php` (191) | کدهای خطا، تشخیص، پاک‌سازی اسرار | classify:156 redact:179 error:135 |
| `src/AI/Remote/CliAuth.php` (355) | ورودها، قفل، وضعیت auth | codex_before:155 codex_after:179 sync_runner:201 lock:218 verified:238 run_login:299 |
| `src/AI/Remote/LocalCli.php` (362) | اجرای درخواست با CLI | binary:49 native_of:87 needs_node:103 version:137 complete:206 models:302 |
| `src/AI/Remote/CliSetup.php` (487) | تشخیص، نصب، گزارش | install:142 install_codex:214 install_state:236 doctor:298 engine_status:362 report:446 |
| `src/AI/Remote/CodexInstaller.php` (260) | نصب Codex بدون npm از رجیستری | target:38 installed:59 plan:127 install:166 |
| `src/AI/Remote/SubscriptionRunner.php` (1117) | اشتراک‌ها: روش cli/runner، اجراکننده گیت‌هاب | method:140 complete:226 local_test:308 setup:734 sync_secret:794 make_public:948 holds_data:962 |
| `src/Storage/GitHubHub.php` / `GitHubClient.php` | ساخت مخزن‌ها، فایل‌ها، Secrets | defaults:66 setup_all:462 / create_repo(…, $private) set_visibility |
| `src/Chat/ChatService.php` (370) | پاسخ چت؛ اجرای proposals | answer:60 created:102 with_tools:219 |
| `src/Chat/Toolbox.php` (372) | ابزارهای داده چت + مسیریابی کلمات | page_importance:35 gsc_opportunities:156/281 by_keywords:348 |
| `src/Chat/ChatActions.php` (207) | proposals → کار/تغییر/پیگیری/پیام | apply:39 task:89 change:119 follow_up:183 |
| `src/Watch/FollowUps.php` | اثر تغییرات ۲.۰ (option `seolity_follow_ups`، درس‌ها `seolity_impact_learnings`) | add, check, evaluate, verdict (static), after_checkpoint, window, learn, learnings, ai_review, from_operation |
| `src/GSC/Coverage.php` | پوشش ایندکس (option `seolity_coverage`، پایش `seolity_index_watch`) | import, analyze, redirect_audit, trace, fix, add_message, ai_review, watch |
| `src/Alerts/AlertService.php` (317) | اعلان‌ها + ایمیل | message:62 kind_of:70 recipients:94 send:123 digest:177 test_email:197 query:273 |
| `src/Content/PageImportance.php` (244) | اهمیت صفحه | of:44 resolve:187 in_menu:218 template_used:224 |
| `src/Recommendations/Engine.php` (1092) | نقشه راه (recommendations) | add:275 (گیت اهمیت + category override)، ORDER:826 (offpage)، category_route:841 |
| `assets/admin/pages-engines.js` | صفحه موتورهای هوش مصنوعی | App.pages.engines:233 |
| `assets/admin/pages-intel.js` | اعلان‌ها (تب‌ها، پیگیری، ایمیل) | App.pages.alerts:731 |
| `assets/admin/pages-chat.js` | چت + نمایش created | — |
| `assets/admin/pages-map.js` | نقشه سایت (کارت بزرگ) | App.pages.sitemap:421 |
| `assets/admin/app.js` | پوسته: shell() سایدبار (navTile/navItem)، route() (نگاشت `settings?tab=x` ← `settings-x`)، engines() متن زیر کاشی‌ها | — |
| `assets/admin/app.css` | همه استایل‌ها (.sl-mapcard الگوی کارت بزرگ، .sl-nav__tile کاشی سایدبار) | — |
| `tools/i18n/fa-*.tsv` + `build.py` | ترجمه فارسی | آخرین: fa-21.tsv |
| `docs/ARCHITECTURE.md` | معماری؛ بخش‌های 15b (CLI) و 15c (چت/اعلان) | — |
| `readme.txt` | changelog | — |
| `runner/runner.mjs` | کد اجراکننده گیت‌هاب (رمزنگاری AES-GCM) | — |
| `../backup/*.md` | بکاپ کامل: شروع، نمای کلی + نمودار mermaid، نقشه کد، درس‌ها، تاریخچه، changelog | — |
| `../docs/SESSION-ROADMAP.md` | نقشه قدیمی کوتاه (جایگزین با همین فایل) | — |
| `../CLAUDE.md`, `../.mcp.json`, `../.claude/` | تنظیمات code-review-graph (کامیت شده) | — |

## 4. معماری و جریان داده
- درخواست AI: `Engine::run(task)` → provider → برای `sub_*`: `SubscriptionRunner::complete` → `method()`:
  - `cli` (اگر `sub_method_*`=cli، یا مسیر direct، یا auto + `CliAuth::verified`) → `LocalCli::complete` → `ProcessRunner::run`
  - وگرنه → صف GitHub (`remote_jobs`) → `Deferred` → اجراکننده Actions → callback/relay/collect.
  - auto + خطای سروری محلی → همان درخواست به GitHub.
- چت: `ChatService::answer` → `with_tools` (کلمات کلیدی؛ planner فقط برای API مستقیم) → task `chat` → `ChatActions::apply(proposals)` → recommendations / `Agent::start` (operation awaiting_approval → dock) / FollowUps / AlertService.
- اعمال تغییر: operation done → `seolity_operation_finished` → `FollowUps::from_operation` (۲۸ روز).
- روزانه: `daily_pipeline` → gsc_sync … `follow_ups_check` → `alerts_digest`.
- جدول‌ها: `gsc_page_query` (page_hash=`Database::url_hash`, query_hash=`Database::hash`)، `recommendations`، `operations`، `activity`، `alerts`، `urls`.
- optionها: `seolity_cli_auth`، `seolity_cli_install_{engine}`، `seolity_follow_ups`، `seolity_mail_last`، `seolity_node_scan` (transient).
- پوشه خصوصی CLI: `<بالای وردپرس>/seolity-cli/` (codex-native/, codex-home/, claude-home/, runs/, locks/).

## 5. تصمیم‌های کلیدی
- Codex بدون npm (دانلود مستقیم تاربال پلتفرم + sha512) ← npm در سقف حافظه مجازی LiteSpeed می‌میرد. رد: npm با NODE_OPTIONS.
- auto فقط CLI آزموده‌شده ← CLI نصب‌شده ولی ناکارآمد همه درخواست‌ها را می‌بلعید.
- اجراکننده عمومی + داده خصوصی ← خطای billing گیت‌هاب؛ دانش/داده رمزنگاری نشده‌اند.
- تبدیل خودکار مخزن به عمومی فقط با بررسی fail-safe (فقط 404 قطعی = نبودِ داده).
- تغییرات چت هرگز خودکار اعمال نمی‌شوند؛ همیشه «بررسی و اعمال».
- اعلان‌ها روی جدول موجود `alerts` (نوع از `kind_of`)، نه جدول جدید.
- پیگیری‌ها در option (حداکثر ۳۰۰) به‌جای جدول جدید (بدون migration).
- رشته‌های جمع (`_n`) استفاده نشود؛ build ترجمه آن‌ها را نمی‌گیرد.
- ناوبری: هیچ تب بخش (hub/subtabs) نباشد؛ صفحه جدید = route در `Admin::routes()` + جای آن در `Admin::nav()`. تب‌های فیلتر داخل خود صفحه (یک سطح) مجاز است. ظاهر = کپی AI Writer (کاربر فایل `ARIAGH-AIWRITER-1.9.1` را مرجع داد).

## 6. راه‌های امتحان‌شده و شکست‌خورده
- مسیر راه در دو بخش جدا (برد مشکلات + تشخیص AI) ← کاربر نپسندید؛ باید یک مسیر واحد باشد. یادگیری فقط از آزمایش‌های قطعی ← در سایت کم‌ترافیک هیچ‌وقت یاد نمی‌گرفت.
- مقایسه پیگیری با «۲۸ روز آخر» در زمان ایجاد و زمان بررسی، بدون کنترل روند سایت ← همه چیز «بهتر» دیده می‌شد و برای ادغام‌ها گمراه‌کننده بود؛ در 1.16 جایگزین شد.
- `npm install @openai/codex` زیر سقف ۱GB حافظه مجازی ← heap out of memory.
- اجرای Codex با ۵۱۲ و ۷۶۸MB ← «memory allocation failed»؛ حداقل ~۱GB لازم است. TOKIO_WORKER_THREADS کمکی نکرد.
- poll کامل صفحه هر ۵ ثانیه حین نصب ← اجرای مکرر CLI و پر شدن حافظه (حالا فقط `cli/status`).
- `flock` بی‌سقف ← قفل‌شدن کارگرها (حالا ۲۰ ثانیه).
- planner چت روی مسیر گیت‌هاب/اشتراک ← کند؛ به‌جایش مسیریابی کلمه کلیدی.
- `Gateway::current()` همیشه github برای sub_* ← برچسب غلط (اصلاح شد).
- `rm -rf` با متغیر در bash -c ← بلاک امنیتی؛ از `mktemp -d` استفاده شود.
- ناوبری «بخش + زیرتب» (HUBS در app.js، نسخه 1.9–1.14) ← کاربر گیج می‌شد (تب در تب)؛ در 1.15.0 حذف شد. دوباره برنگردان.

## 7. قوانین و ترجیحات کاربر
- پاسخ همیشه **فارسی**؛ کاربر غیربرنامه‌نویس است، توضیح ساده.
- UI: کارت‌های بزرگ افقی (الگوی `.sl-mapcard`) مثل داشبورد؛ RTL؛ موبایل.
- کامیت روی همین شاخه؛ پایان پیام کامیت: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` و `Claude-Session: <لینک گفتگوی فعلی>`. شناسه مدل در کد/کامیت ننویس. PR نساز مگر خواسته شود.
- هر نسخه: `seolity.php` (Version + SEOLITY_VERSION)، `readme.txt` (Stable tag + changelog)، ترجمه تا `missing: 0`، ZIP: `git archive --format=zip --prefix=seolity/ -o <scratch>/seolity-X.zip HEAD:seolity` و ارسال؛ ZIP کامیت نشود.
- صرفه‌جویی توکن؛ گزارش صادقانه (چه آزمایش شد، چه نشد).
- هرگز: جمع‌آوری رمز/کوکی، اسکرپ گوگل، دور زدن محدودیت شبکه، اعمال تغییر بدون تأیید کاربر.

## 8. باگ‌های حل‌شده (احتمال تکرار)
- مقدار پیش‌فرض `sub_method_*` برابر `'auto'` است نه `''`.
- `settings->secret()` (نه get_secret) ممکن است آرایه برگرداند.
- ستون نوع در جدول activity `kind` است نه `type`؛ `chat->ask()` شناسه را در `['message']['id']` برمی‌گرداند.
- اسکن Node.js وقتی proc_open بسته است نباید cache شود.
- redact مسیر home فقط در مرز مسیر (نه `/opt/alt/.../root`).
- `build.py` همه رشته‌های بین `var LABELS` و `App.pages.settings` در pages-admin.js را رشته ترجمه می‌گیرد؛ کد غیرمتنی (مثل `'settings-'`) آنجا ننویس.

## 9. مشکلات باز و قدم‌های بعدی
1. روی سایت کاربر تأیید شود: ایمیل آزمایشی، «آزمایش اتصال» Codex/Claude، اجرای گیت‌هاب در مخزن عمومی.
2. اگر سقف حافظه مجازی هاست < ۱GB: Codex محلی ممکن نیست → مسیر گیت‌هاب.
3. اگر مخزن قدیمی کاربر داده دارد: با «ساخت با یک کلیک» و نام جدید، اجراکننده عمومی جدا بسازد.
4. ایده‌های انجام‌نشده: کارت بزرگ برای صفحه ۴۰۴ و تاریخچه آزمایش‌ها؛ منبع بک‌لینک؛ planner چت برای CLI محلی آزموده‌شده.
5. محیط آزمایش (scratchpad: سایت WP آزمایشی، fixtureها، اسکریپت‌های تست مثل actions-test/imp-test/cli-arch-test) در این کانتینر **پاک شده**؛ در صورت نیاز باید دوباره ساخته شود.

## 10. راهنمای کار با این نقشه
- بکاپ کامل و توضیح جامع: پوشه `backup/` (شروع از `backup/00-START-HERE.md`؛ نمودارها در `01`، «کجا را بخوانم» در `02`، اشتباهات و باگ‌ها در `03`، تاریخچه در `04`). فقط هنگام نیاز بخوان.
- اول این نقشه را بخوان؛ فایل‌های کد را فقط هنگام نیاز و فقط خطوط لازم بخوان (grep نماد / offset+limit).
- هیچ‌کدام از موارد بخش ۶ را تکرار نکن.
- بعد از هر تغییر مهم (نسخه جدید، فایل جدید، تصمیم، باگ حل‌شده) همان بخش این نقشه را با یک Edit کوچک در همان نوبت به‌روز کن.
- وقتی گفتگو طولانی شد، دوباره project-handoff را اجرا کن و نقشه را تازه کن.
