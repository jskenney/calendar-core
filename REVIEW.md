# Repository Review: `calendar-core` + `calendar` (Cal4)

*Review date: 2026-11-05 · Reviewed revision: `calendar-core@42981f66` (v4.4.20240129), `calendar@9013f49`*
*Scope: all first-party PHP/HTML/shell code in both repositories. Vendored third-party libraries were checked for version and known-issue status only, not audited line by line.*

No application code was changed. Every finding marked **[verified]** was reproduced against a local instance: PHP 8.3.6 built-in server, the `calendar` repo's sample `calendar.php`, and a few extra test directories. The rest come from reading the code.

---

## 1. Project map

### Purpose
Cal4 is a file-system-driven course website. An instructor drops course material into component directories (`class/`, `lab/`, `exam/`, …), each holding numbered sub-directories (`01.Intro/`, `02.Loops/`). Cal4 walks those directories, lines them up against a semester schedule (`$DOW`, `$OVERRIDE`, `$COMBINE`), and renders a month calendar with navigation, date-based release of material, instructor-only content, and several custom HTML tags (`<inst>`, `<student>`, `<inject>`, `<codeinject>`, `<lock>`, `<host>`, `<url>`, `<calphp>`, `<ace>`, `<replace>`).

### Repositories and how they fit together

```
XX123/                       ← the "calendar" repo (course shell)
├── calendar.php             ← ENTRY POINT: all config as PHP globals, then require_once('calendar/calendar_main.php')
├── index.php -> calendar.php
├── calendar -> calendar-core/   (symlink; core is reached via the path "calendar/…")
├── calendar-core/           ← git submodule (pinned to f0cb1bda, 2018-06-26) or a fresh clone
├── home.html, policy.html, resources.html   ← loaded with ?load=<name>
├── virtual/cal4.access      ← date-release rules ($ACCESS_FILE)
├── virtual/cal4.virtual     ← "virtual file" placement rules ($CLASS_FILE)
├── class/NN.Name/…, lab/NN.Name/…           ← course content
├── */.htaccess              ← Apache Basic-Auth to stop direct downloads
└── clean-permissions.sh
```

`calendar-core` (about 100 MB working tree, 44 MB `.git`):

| File | Role |
|---|---|
| `calendar_main.php` (1,305 lines) | The whole application. It runs top to bottom with no functions-only layer: parses config, authenticates, scans directories, applies access rules, resolves the request, rewrites HTML tags, then includes the navbar and any `?show=` page. |
| `calendar_navbar.php` | Navbar, login/logout forms, per-component dropdowns. Includes `calendar_css.php`. |
| `calendar_css.php` | `<head>`: CSS/JS includes, nonce for challenge/response login, optional `$CUSTOMCSS`/`$CUSTOMJS`. |
| `calendar_display.php` | Default month-grid calendar (`?show=calendar_display`). |
| `calendar_display_alt.php`, `calendar_display_dateless.php` | List-style calendars, about 90% copies of each other. |
| `calendar_search.php`, `calendar_keyword.php` | Regex search and `<a name>` keyword search. Also about 90% copies of the list views. |
| `calendar_file_selector.php` | Instructor table of files and their access state (`?navbaronly=1`). |
| `calendar_file_editor.php` | Instructor in-browser editor (Ace) that **writes files to disk**. |
| `template_navbar.php`, `template_navbar_example.php` | Navbar framework from another project. Not used by Cal4, but reachable through `?show=`. |
| `instructor.html`, `error.html` | Instructor guide (the main user doc) and the error template. |
| `installer.sh` | Bootstrap script: clones `calendar`, clones `calendar-core`, `sed`s the config. |
| `js/sha256.js` | SHA-256 plus `hashPassword()` for challenge/response login. |
| Vendored libraries | Bootstrap 3.4.1, jQuery 3.6.3, Ace 1.4.7, Chart.js 2.9.3, DataTables 1.13.2 (+ JSZip 2.5.0), Font-Awesome 4.7.0, highlight.js 11.7.0, MathJax 3.2.2 (in a folder named `MathJax-3.3.2`), Skeleton 2.0.4, bootstrap3-ie10-viewport-bug-workaround. |

### Request flow (`calendar_main.php`)
1. **Config → components** (L7-29): directory names come from the values of `$DOW`, `$OVERRIDE` and `$COMBINE`.
2. **Parse virtual and access files** (L33-86).
3. **Auth** (L508-555): plaintext `?password=` or `sha256(ADMIN+nonce)`. Sets `$_SESSION`/cookie to `sha1(SECRET.ADMIN.COURSE.nonce.session_id())`.
4. **Scan** (`get_files`, L138-238): recursive walk of every component directory. Each file gets `key = sha1(SECRET.l0.l1.l2)`.
5. **Schedule** (`build_events` L408, `calendar_events` L322): the N-th sorted directory of a type goes on the N-th scheduled day of that type.
6. **Access trimming** (L601-645): `get_file_security()` is applied only to events that landed on a calendar day.
7. **Resolve request** (L648-760): `type`+`event` → title file. `key` → any file in that event. `load` → a root `.html`/`.pdf`. Non-HTML files are streamed by `provide_file()`.
8. **Tag rewriting** (L797-1092), **header extraction** (L1106-1206), navbar, `?show=` include (L1240-1245), `<calphp>` execution (L1250-1270), debug dump (L1273).

### Tooling present
- **Tests:** none. **Linters/static analysis:** none configured. **CI:** none (no `.github/`). **Dependency manifest:** none (no `composer.json`/`package.json` for the app; libraries are copied in).

---

## 2. Test suite and lint results

| Check | Result |
|---|---|
| Test suite | **None exists.** No unit, integration or smoke tests in either repo. |
| `php -l` on every first-party `.php` file (PHP 8.3.6) | **Pass.** No syntax errors. |
| Smoke run of common pages (home, calendar, alt/dateless lists, class page, lab page, keyword search, print view) as student and instructor with `error_reporting=E_ALL` | **No warnings** on the normal path. |
| Edge-case requests | **Several fatal errors and warnings.** See C-1, H-4, H-5 and M-3. |

There is no PHPStan, Psalm or PHPCS config to run. Running PHPStan at level 0 would flag the undefined constant `map` (H-4) right away.

---

## 3. Findings

Severity guide:
- **Critical:** exploitable by a student or an unauthenticated visitor to read protected material or change files.
- **High:** a security weakness that needs a precondition, or a bug that breaks a documented feature.
- **Medium:** a correctness, robustness or maintainability problem with a real but limited impact.
- **Low:** hygiene and polish.

### CRITICAL

#### C-1. Access rules are skipped for any event that is not placed on a calendar day **[verified]**
- **Where:** `calendar_main.php:601-645` (trimming only walks `$events_list`), `calendar_main.php:719-723` (title served from `$events` without a security check).
- **What:** `get_file_security()` only runs for events that were mapped to a date. If a component has more directories than scheduled days, the title file (`index.html`, `slides.pdf`, …) of every extra directory is served to anyone. Examples: three `exam/` folders but two `exam` days in `$OVERRIDE`; a `final-exam/` directory (see H-6); content prepared for next semester.
- **Repro:** the sample config has two `exam` days. With `exam/03.Final Secret/index.html` present, `calendar.php?type=exam&event=3` returned `SECRET EXAM 3 CONTENT` to an anonymous user, even though `cal4.access` has `exam 99 99 9999`.
- **Why it matters:** exams and solutions are exactly the material this system exists to hide.
- **Fix:** enforce access at the point of serving, not while building the calendar. Before serving `$actual` (L719-740), call `get_file_security()` with the event's mapped date, or with a "never" default when it is unmapped. Default-deny unmapped events for non-instructors.

#### C-2. Per-event access rules can be bypassed by writing the event number differently **[verified]**
- **Where:** `calendar_main.php:649-673`, especially L651 (`$other[$_REQUEST['event']-1]`) and L662 (rule lookup uses the raw string `$_REQUEST['event']`).
- **What:** PHP arithmetic treats `02`, `2 ` and `2.0` as 2, so the event's files are loaded. The access lookup, however, builds the key `class_02/…`, which does not match a `class_2` rule. The lookup then falls through to broader rules or the default "visible".
- **Repro:** with `class_2 99 99 9999` added to `cal4.access`, `?key=<pic key>&type=class&event=2` returned the page shell (blocked), while `event=02`, `event=2%20` and `event=2.0` each returned the protected file.
- **Fix:** normalize once at the top: `$event = filter_var($_REQUEST['event'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])`. Reject the request if that fails, and use the integer everywhere.

#### C-3. File keys can be forged because `$SECRET` is guessable **[verified: brute-force cost]**
- **Where:** `installer.sh:21` (`impossible to guess $RANDOM`), `calendar/calendar.php:93` (public default phrase), key derivation at `calendar_main.php:171` and `:218`.
- **What:** keys are `sha1(SECRET . type . dir . filename)`. The installer only appends `$RANDOM` (0-32767) to a phrase that is published on GitHub, and manual installs keep the phrase as is. A student copies one key from any visible link, knows the type, directory and filename, and recovers `$SECRET` offline. All 32,768 candidates took **8 ms** to hash locally. With the secret, the student can compute the key of any file whose name they can guess (`answers.html`, `labanswers.html` and similar names are in the public sample config). Combined with C-2, or with any file that ends up in `$other`, that opens hidden files.
- **Fix:** generate the secret with `php -r 'echo bin2hex(random_bytes(32));'` in the installer. Better, stop using keys as a capability at all: keep them as opaque IDs and always re-check access when serving (see C-1). Fail closed if `$SECRET` still equals the sample value.

#### C-4. The instructor file editor writes arbitrary content on a GET request and has no CSRF protection, which leads to remote code execution **[verified]**
- **Where:** `calendar_file_editor.php:17-22`, `calendar_main.php:726` (bypassed with `navbaronly`), `calendar_main.php:1234-1237`.
- **What:** `$_REQUEST['editor']` is written with `file_put_contents($actual_file, …)`. Because the code reads `$_REQUEST`, a plain GET works. There is no CSRF token, and the session/auth cookies have no `SameSite` attribute. Adding `navbaronly=1` skips the "stream non-HTML file" branch, so **any** file in an event can be overwritten, not just HTML.
- **Repro:** logged in as instructor, `GET ?edit=1&navbaronly=1&key=<pic.png key>&type=class&event=2&editor=<?php echo "PWNED";` replaced `pic.png` with PHP source. A student already knows valid keys (they appear in every link), so they can send the instructor a link or an `<img>` tag. A written `.php` file then runs through `<calphp>` or directly through the web server.
- **Fix:** accept edits only on `POST`. Add a per-session CSRF token checked with `hash_equals`. Restrict edits to `.html`/`.htm`. Set cookies with `SameSite=Strict; HttpOnly; Secure`.

### HIGH

#### H-1. Reflected XSS through `event`, `type` and `key`; the 2024 `strip_tags` fix did not close it **[verified]**
- **Where:**
  - `calendar_main.php:801-815`: link rewriting. The link goes into `<img src="…">`, `<a href="…">` and similar attributes.
  - `calendar_navbar.php:89-98`: the answers toggle, in single-quoted `href`.
  - `calendar_navbar.php:140-147`: instructor file list.
  - `calendar_file_editor.php:38-41`: `value="…"`.
  - `calendar_file_selector.php:12-30`: **raw** `$_REQUEST['type']`/`['event']`, with no filtering at all.
  - `<replace>` values from the request (L763-773, escaped at L826-827), which escape `<` and `>` but not quotes.
  - `<url>` tag (L1080-1089), same problem.
- **What:** `strip_tags()` (commit `ed58227f`) removes tags but leaves quotes alone, and every one of these sinks is an HTML attribute.
- **Repro:** `?key=<key>&type=class&event=2" onerror="alert(1)" x="` rendered `<img src="calendar.php?key=…&type=class&event=2" onerror="alert(1)" x="">`. PHP 8 accepts the leading-numeric `event` with only a warning. The editor and selector variants run in the **instructor's** session, which turns them into stored compromise through C-4.
- **Fix:** add `function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_HTML5, 'UTF-8'); }` and use it on every echo of request data. Build query strings with `http_build_query()`. Validate `type` against `$COMPONENTS` and `event` as an integer (C-2). Add a `Content-Security-Policy` header.

#### H-2. Direct file access is only blocked on Apache with `.htaccess` enabled
- **Where:** `calendar/class/.htaccess`, `calendar/lab/.htaccess`, `calendar/virtual/.htaccess`, `calendar/clean-permissions.sh:8-9`.
- **What:** every protected file sits under the web root, for example `/class/03.X/answers.html`. The access system only applies to requests that go through `calendar.php`. On nginx, Caddy, or Apache with `AllowOverride None`, every file is downloadable by URL. That includes `virtual/cal4.access`, which lists what is hidden and until when. The sample has no `.htaccess` for `exam/`. `clean-permissions.sh` instead runs `chmod 600` on exam files, which also stops PHP (the web user) from reading them.
- **Fix:** keep content outside the document root and pass the path through config (`$CONTENT_ROOT`). At minimum, ship a deny-all `.htaccess` for every component plus a documented nginx `location` block, and have `calendar.php` check that a direct request to a known file returns 403.

#### H-3. Authentication weaknesses
- **Where:** `calendar_main.php:508-555`, `calendar/calendar.php:84,93`, `js/sha256.js:181-196`.
- **Issues:**
  1. The default password `mypassword` and the public `$SECRET` are committed in the sample `calendar.php`. The README's manual install path never tells the user to change them.
  2. A plaintext password is accepted (L510). `hashPassword()` sends the hash with jQuery's default **GET**, so credentials end up in web server logs and in `$ACCESS_LOG` (L559-568, which logs the query string).
  3. The comparisons are loose `==` and not constant-time (L510, L516, L522, L539, L542). `"1e3" == "1000"` is true in PHP.
  4. The nonce comes from `rand()` (L545), which is not a CSPRNG.
  5. There is no `session_regenerate_id(true)` on login (session fixation), and `session.use_strict_mode` is not set.
  6. Cookies have no `HttpOnly`, `Secure` or `SameSite` attributes [verified from the `Set-Cookie` header].
  7. There is no rate limiting or lockout.
  8. L512-513 read `$_COOKIE[...-nonce]` without `isset`, which gives a warning when the cookie is missing.
  9. If `$ADMIN == ''`, `$INSTRUCTOR` is never defined. That produced 11 "Undefined variable" warnings on the home page [verified].
- **Fix:** store `password_hash()` output and check it with `password_verify()` over POST/TLS, and drop the home-made challenge/response. Use `random_bytes`, `hash_equals`, `session_regenerate_id`, and `session_set_cookie_params([... 'httponly'=>true,'secure'=>true,'samesite'=>'Strict'])`. Initialize `$INSTRUCTOR = false` before the `if`. Refuse to run while the sample password is still set.

#### H-4. `$REVEALHOUR` / `<postmeeting>` crashes with a fatal error, and also prints debug text **[verified]**
- **Where:** `calendar_main.php:884-921`.
- **What:**
  - `$events_list[map]` uses the bare constant `map` (L892, 898, 900, 906, 908). PHP 8 throws `Error: Undefined constant "map"` for any student request in the course year once `$REVEALHOUR` is set. It was reproduced with `$YEAR = 2026`. It only works in older years because the first condition short-circuits.
  - L914 `echo "Would show content";` writes text before `<!DOCTYPE html>`, which pushes the browser into quirks mode.
  - On pages with no `type`/`event` (the home page) the lookups also hit undefined indexes.
- **Fix:** use `'map'`, guard with `isset($events_list['map'][$type][$event])`, and delete the debug `echo`.

#### H-5. Malformed `event` values cause uncaught `TypeError`s **[verified]**
- **Where:** `calendar_main.php:651`.
- **What:** `?type=exam&event=abc` gives `Unsupported operand types: string - int`, and `event[]=1` gives `array - int`. Both are fatal and print the full server path when `display_errors` is on. An out-of-range `event=7` produces a cascade of warnings, and `$other` becomes `null`.
- **Fix:** the integer validation from C-2. Also set `display_errors=0` in production.

#### H-6. `$OVERRIDE` day types that contain spaces or capitals never match their directory **[verified]**
- **Where:** `calendar_main.php:9`, `:15` (component names are lower-cased and spaces become `-`) against `:342`/`:346`/`:351` (the raw `$day_type` is used to look up `$events`).
- **What:** `'final exam'` scans `final-exam/`, but the calendar looks up `$events['final exam']`. The calendar shows `FINAL EXAM` with no content, while `?type=final-exam&event=1` still serves the file, unprotected (C-1). The same happens to `'final class'` in the sample config, and to any capitalised type such as `'Exam'`.
- **Fix:** normalize `$day_type` the same way (`str_replace(' ', '-', strtolower(...))`) before the lookups, and keep the original string only for display.

#### H-7. Legacy `name.MM.DD.ext` date locking is documented but not enforced **[verified]**
- **Where:** `calendar_main.php:92-111` (`validate_file`). The date is parsed, `$open_month`/`$open_day` are set, and `True, True` (visible) is always returned. The documentation is `instructor.html:181-202`.
- **Repro:** `class/01.Intro/slides.12.31.pdf` was served (`Content-Type: application/pdf`) to an anonymous user on 2026-11-05.
- **Why it matters:** instructors who follow the guide, including its symlink recipe, believe these files are locked.
- **Fix:** either enforce the date (compare it with today unless the user is the instructor) or remove the feature and the documentation. The code comment already says it "should be removed".

#### H-8. `?show=` includes any PHP file in `calendar-core` for anyone
- **Where:** `calendar_main.php:1240-1245`.
- **What:** `basename()` blocks path traversal, but every `.php` in the core directory becomes reachable: `template_navbar`, `template_navbar_example`, the editor, the selector. Today the dangerous pages check `$INSTRUCTOR`, so the exposure is limited to warnings (`show=template_navbar` gives `foreach() argument must be of type array` [verified]). But any file added to core later is exposed automatically.
- **Fix:** use an allow-list such as `$SHOW_PAGES = ['calendar_display','calendar_display_alt','calendar_display_dateless','calendar_search','calendar_keyword','calendar_file_selector','calendar_file_editor']`, and check `$INSTRUCTOR` for the last two in `calendar_main.php` itself.

#### H-9. Vulnerable or end-of-life front-end libraries
- **Bootstrap 3.4.1:** EOL since 2019. Has an unpatched XSS issue, CVE-2024-6485 (button plugin `data-loading-text`). The navbar depends on Bootstrap 3 markup and glyphicons.
- **Chart.js 2.9.3:** CVE-2020-7746 (prototype pollution), fixed in 2.9.4. The 2.x line is EOL.
- **JSZip 2.5.0** (inside DataTables): CVE-2021-23413 (prototype pollution) and CVE-2022-48285 (path traversal in `loadAsync`), fixed in 3.7.0 and 3.8.0.
- **Ace 1.4.7** (2019), **Font-Awesome 4.7.0** (2016), **DataTables 1.13.2** (2.x is current), **jQuery 3.6.3** (3.7.1/4.x available), **Skeleton 2.0.4** (abandoned). **bootstrap3-ie10-viewport-bug-workaround** is obsolete.
- **Why it matters:** most of these issues need attacker-controlled input to reach the vulnerable API. Cal4 mostly feeds them instructor content, so real-world risk is moderate. Still, a scanner will flag the site, and the fixes are cheap.
- **Fix:** update Chart.js to 2.9.4 or move to 4.x. Build DataTables without JSZip if Excel export is unused. Plan a move from Bootstrap 3 to Bootstrap 5, or keep 3.4.1 and avoid the carousel and button plugins. Track versions in one manifest (see M-10).

### MEDIUM

#### M-1. Search results stop after the first match, and single matches are never found **[verified]**
- **Where:** `calendar_search.php:42-43`, `:71`; `calendar_keyword.php:44`, `:74`.
- **What:**
  - `$key` (the search term) is overwritten by a file key inside the result loop. Every later page is then searched for a SHA-1 string. With two pages containing `zzz zzz`, only `Class 1` was listed.
  - `count(...) > 1` requires at least **two** matches. A page with one `zzz` was never listed.
- **Fix:** rename the inner variable (`$fkey`) and change the check to `> 0`. Better still, merge both files into one function (see M-8).

#### M-2. User-supplied regex in search: ReDoS, warnings and delimiter injection
- **Where:** `calendar_search.php:42` (`preg_match_all("/$key/", …)`).
- **What:** any `/` in the search term breaks the pattern. `search=/e` produced `preg_match_all(): Unknown modifier` warnings [verified]. Catastrophic patterns run against every page on every search. PCRE's backtrack limit caps a single match, but not the total cost.
- **Fix:** use `preg_quote($key, '/')` and offer a plain substring search (`stripos`). If regex really is a feature, restrict it to instructors and check `preg_last_error()`.

#### M-3. Access-file and virtual-file parsing has no comment syntax and no validation
- **Where:** `calendar_main.php:44-54`, `:73-84`.
- **What:** lines are split on whitespace and accepted when they have exactly 2, 3 or 4 tokens. Comment lines in the shipped samples (`# Our First Class always visible` and others) only escape by luck of their word count. A 4-word comment in `cal4.virtual` becomes a virtual-file rule, and a 3-word comment becomes an access rule keyed `#`. File names with spaces (`class/01.Course Overview/intro.html`, which `instructor.html:211` itself uses as an example) cannot be expressed. Invalid dynamic values such as `+frday` reach `DateTime::modify()` without checks (L286, L294).
- **Fix:** skip lines that start with `#` or are blank. Split on tabs only (as the docs say). Validate each field, and show parse errors to the instructor.

#### M-4. `?load=` exposes every root-level `.html`/`.pdf` and the instructor guide to students
- **Where:** `calendar_main.php:748-760`.
- **What:** `basename()` prevents traversal, but `?load=instructor` serves `calendar/instructor.html` to anonymous users [verified]. That page shows the configured access-file path and how hiding works. Any root-level `.pdf` or `.html` an instructor leaves next to `calendar.php` (for example `final_key.pdf`) is served without access checks. `file_exists('calendar/'.$actual)` is also called on the un-`basename`d value, which is inconsistent.
- **Fix:** allow-list the loadable pages (`home`, `policy`, `resources`, `error`, `lock`, plus `instructor` when `$INSTRUCTOR`).

#### M-5. Access-log privacy
- **Where:** `calendar_main.php:558-571`, `calendar/calendar.php:76-80`.
- **What:** the log stores IP addresses and full query strings, which include `password=` values (H-3). The sample suggests writing it to `access.log` in the web root, where it is world-readable. The log is never rotated.
- **Fix:** write it outside the web root, drop the `password` parameter before logging, and document retention.

#### M-6. `get_files()` symlink handling is broken
- **Where:** `calendar_main.php:173-187`.
- **What:**
  - L174 passes `ancient(readlink(...), $access)` as the file path and `0` as `$access`. That is an argument mix-up, and since `validate_file` always returns a truthy array, the `if` is meaningless.
  - L177 builds `'actual'` as `dir/` + `readlink()`, which is wrong for absolute link targets and for links inside sub-directories (where `$l2` contains `/`).
  - Symlinks can point anywhere on disk (`/etc/passwd`). Only the instructor can create them, but nothing confines `actual` to the content root.
- **Fix:** use `realpath()` and check that the result stays inside the course directory.

#### M-7. Course schedules cannot cross a year boundary
- **Where:** `calendar_main.php:327` and `calendar_display*.php` (`for ($month=$MONTH_START; $month <= $MONTH_END; …)` with a single `$YEAR`).
- **What:** a Nov→Jan course or a winter session renders nothing past December. The `month 12, day 31` fallback (L656-657) for unmapped events also assumes a single year.
- **Fix:** iterate over `DatePeriod` from a start date to an end date instead of month numbers.

#### M-8. Heavy duplication and one huge script (architecture)
- **Where:**
  - `calendar_display_alt.php`, `calendar_display_dateless.php`, `calendar_search.php` and `calendar_keyword.php` are about 90% the same loop (compare them with `diff`).
  - `calendar_display.php:84-131` and `:133-183` repeat the same box rendering for normal and combined events.
  - `calendar_main.php:601-645` repeats the trimming for `event` and `combine`.
  - The three auth branches (L510-525) repeat the same three statements.
  - The six-way `str_ireplace` for each tag variant (L808-869) is repeated per tag.
- **Why it matters:** this duplication is the direct cause of M-1, where the same bug was copied into two files, and of H-1, where the same unsafe pattern sits in five places.
- **Fix:**
  - Extract `render_box($eventday, $box, $INSTRUCTOR)` and `walk_semester(callable)`.
  - Parse tags once with `preg_replace_callback('/<(inject|codeinject|replace)\s+(src|value)=(["\'])(.*?)\3\s*\/?>/i', …)`.
  - Split `calendar_main.php` into `config.php`, `auth.php`, `scan.php`, `access.php`, `render.php`, with globals passed in explicitly.

#### M-9. Dead and stale code
- **What:**
  - `template_navbar.php` and `template_navbar_example.php` belong to another project (`../example/status.php`).
  - In `validate_file`, `if (True)` (L102), and its `$instructor`/`$access` parameters are unused.
  - `categorize_file`'s `$instructor`/`$filewithpath` parameters are unused.
  - The `ancient()` helper (L129) exists for PHP < 5.4.
  - In `calendar_display.php`, `$line` is assigned and never used (L89, L140).
  - The `MathJax.Hub.Config` block in `calendar_css.php:67-74` is MathJax 2 syntax. MathJax 3 ignores it, and so it ignores `?config=TeX-MML-AM_CHTML` (L75).
  - The html5shiv/respond links point at `oss.maxcdn.com` (L62-63), which is offline.
  - `hljs.initHighlightingOnLoad()` (L80) is deprecated in highlight.js 11 and removed in 12.
- **Fix:** delete them, or move them to MathJax 3 `window.MathJax = {tex:{inlineMath:[['\\(','\\)']]}}` and `hljs.highlightAll()`.

#### M-10. Vendored dependencies with no manifest, plus repository bloat (dependency health / performance)
- **Where:** repo root.
- **What:** 100 MB of third-party code is checked in, including the complete Ace build (49 MB, while only `src-noconflict/ace.js` and a few modes are used), the full MathJax (24 MB), and Font-Awesome's whole Jekyll site source (13 MB). There is no record of where each copy came from. The folder `MathJax-3.3.2` actually contains **3.2.2**. Every `git clone --recursive` downloads about 144 MB.
- **Fix:** add a `package.json` (or a `vendor.lock.md`) that lists versions, and keep only the files that are used. Alternatively, load them from a pinned CDN with SRI hashes.

#### M-11. Every request rescans the whole tree and reads files fully into memory (performance)
- **Where:** `calendar_main.php:138-238`, `:453-497`; `calendar_display.php:110-111`; `calendar_search.php`.
- **What:**
  - Each request runs a recursive directory walk, a SHA-1 per file, and up to five `validate_file` + `readlink` calls per symlink.
  - It loops 12×31 days and makes 5 passes of `str_ireplace` per file in the event (L797-872).
  - Search reads every title file.
  - `provide_file()` uses `echo file_get_contents()`. A 500 MB lecture video will hit `memory_limit`, and since there is no `Range` support, video seeking does not work. There is also no `Content-Length`, `ETag` or `Last-Modified`.
- **Fix:** use `readfile()` (or `X-Sendfile`/`X-Accel-Redirect`) with `Content-Length` and `Range`. Cache the scan result keyed on directory mtimes (APCu or a JSON file). Make the tag loop stop when nothing changed, as the L795 comment already suggests.

#### M-12. No tests and no CI
- **What:** there is nothing in either repo. Many of the bugs above, such as the PHP 8 fatals, would have been caught by the simplest smoke test.
- **Fix:**
  1. Add a `tests/fixtures/course/` tree and a PHPUnit (or plain PHP) test that `include`s `calendar_main.php` with crafted `$_REQUEST` values and asserts on output: access-rule matrix, unmapped events, `event` normalization, `<inst>`/`<student>`/`<lock>` stripping, and search.
  2. Add `php -l` plus PHPStan level 1 in a GitHub Actions workflow on PHP 8.1-8.4.
  3. Add a Playwright smoke test of the calendar page.

### LOW

| # | Issue | Location | Fix |
|---|---|---|---|
| L-1 | `strpos(...) > 0` misses tags at position 0, so `<student>` at the very top of a file never shows the answers toggle. | `calendar_main.php:983-984` | Use `!== false`. |
| L-2 | The event name is the second `.`-segment only, so `01.Intro.Part2` displays as "Intro". | `calendar_main.php:414-419` | `explode('.', $event, 2)[1]`. |
| L-3 | Events are numbered by lexical sort position, not by the number in the folder name (`1.`, `10.`, `2.` sort out of order). Undocumented. | `calendar_main.php:144`, `:412-432` | `sort($level1, SORT_NATURAL)`, or parse the leading number, and document zero-padding. |
| L-4 | Hidden files in nested sub-directories (`sub/.env`, `.DS_Store`) are indexed and get keys. Only top-level dot-files are skipped. | `calendar_main.php:157-172` | Skip any path segment that starts with `.`; use `FilesystemIterator::SKIP_DOTS`. |
| L-5 | A stray `'` character is rendered in the lock-mode login form. | `calendar_navbar.php:38` | Remove it. |
| L-6 | `<url value="x">` without a `default` attribute gives an "Undefined array key 0" warning. Values persist in the session across pages. | `calendar_main.php:1077-1079` | Default to `''`; document the persistence. |
| L-7 | `<host>`/`<lock>` regexes only accept double-quoted attributes, and `$tag_src[1][0]` is used without `isset`. | `calendar_main.php:935-936`, `:955-956` | Accept both quote styles and guard the index. |
| L-8 | `$ace_name`/`$ace_mode` and other variables are reused as both the match array and the scalar result in the loop. It works today but is fragile. | `calendar_main.php:1005-1043` | Use distinct variable names. |
| L-9 | No `X-Content-Type-Options: nosniff` or CSP; SVG/HTML course files are served inline. | `calendar_main.php:489-493` | Add security headers. |
| L-10 | `$PAGE_TITLE`, `$COURSE` and `$COURSENAME` are echoed without escaping. Config-controlled, but `installer.sh` puts raw user input into them. | `calendar_css.php:136`, `calendar_navbar.php:28` | Escape with `h()`. |
| L-11 | Printed config comment says `$DOW` accepts `1-7`, but only weekday names work (`$DOW[$POSDOW[$first]]`). | `calendar/calendar.php:36`, `calendar_main.php:345` | Fix the comment or support numbers. |
| L-12 | Duplicate key `22=>'HOLIDAY'` in the sample `$OVERRIDE`; the sample dates are Fall 2018. | `calendar/calendar.php:19-21` | Clean up the sample. |
| L-13 | Debug output (`?debug`) dumps `$_SESSION`, all keys and all paths. Instructor-only, but a CSRF/XSS (H-1) can read it. | `calendar_main.php:1273-1301` | Gate it behind a config flag that is off by default. |
| L-14 | `findheaders()` re-parses the whole page with DOMDocument on every request just to build the navbar. | `calendar_main.php:1106-1202` | Fine for now; cache it with the scan (M-11). |

### Docs and developer experience

| # | Issue | Location | Fix |
|---|---|---|---|
| D-1 | The `calendar` submodule is pinned to `f0cb1bda` (2018-06-26), **32 commits behind** `master`, and that range includes the 2024 XSS fix. Anyone who follows "git clone --recursive" on the shell repo gets 2018 code. | `calendar/.gitmodules`, gitlink | Bump the gitlink or tell users to clone core separately (as the README's manual path does). |
| D-2 | The README never says: required PHP version (it works on 8.3; needs the `calendar`, `dom` and `session` extensions), Apache + `AllowOverride AuthConfig` for the `.htaccess` files, that `/etc/htpasswd` must exist, nginx equivalents, or to change `$ADMIN`/`$SECRET`. | `calendar/README.md`, `calendar-core/README.md` | Add "Requirements" and "Securing your install" sections. |
| D-3 | `installer.sh` breaks on input containing `\|`, `&`, `'` or `"` (sed and PHP quoting). It only warns about this. It also clones `master` unpinned and never checks that `git` succeeded. `mv XXX/* .` misses dot-files. | `installer.sh:3-21` | Escape input, `set -euo pipefail`, generate the secret properly (C-3), and pin a tag. |
| D-4 | Release notes reference `calendar_display_summer.php`, which no longer exists. There is no CHANGELOG entry for the 2023-2024 changes, and the version in `calendar_main.php:3-4` is maintained by hand. | `release_notes.txt` | Keep a CHANGELOG and tag releases. |
| D-5 | `instructor.html` documents `<student>` as "revealable answers". Worth stating plainly that it is **not** a security control: any student can add `&answers=yes` (`calendar_main.php:987`) [verified]. Real answers belong in `<inst>` or in access-controlled files. | `instructor.html:369-379` | Add a warning. |
| D-6 | The access-file docs say "separated by a tab", but the parser splits on any whitespace, and the sample `cal4.access` uses spaces. | `instructor.html:205-213`, `calendar_main.php:76` | Align docs and parser (M-3). |
| D-7 | No `CONTRIBUTING`, no local dev instructions. A one-liner would help: `cd course && ln -s ../calendar-core calendar && php -S localhost:8000`. | — | Add a "Developing" section. |

---

## 4. Prioritized top 10

| # | Item | Effort | Why first |
|---|---|---|---|
| 1 | **C-1**: enforce `get_file_security()` when a file is served, and default-deny unmapped events. | S | Leaks exams and solutions to anonymous users today. |
| 2 | **C-2 / H-5**: validate `event` as a positive integer and `type` against `$COMPONENTS` at the top of `calendar_main.php`. | XS | One small change closes the bypass, the fatals and half of the XSS surface. |
| 3 | **C-4**: editor POST-only, CSRF token, HTML-only, `SameSite=Strict` cookies. | S | A student can get the instructor to overwrite files and run code. |
| 4 | **H-1**: add `h()` (`htmlspecialchars` with `ENT_QUOTES`) to every echo of request data, use `http_build_query` for links, and add a CSP header. | S | `strip_tags` did not fix the XSS; verified exploitable. |
| 5 | **C-3 + H-3**: random 32-byte `$SECRET`, `password_hash`, `hash_equals`, `session_regenerate_id`, refuse to run with the sample password or secret. | S | Keys and the instructor login are currently guessable or forgeable. |
| 6 | **H-2**: move content out of the web root, or ship and verify deny rules for every component on Apache and nginx. | M | Without it, everything above can be skipped by typing the file path. |
| 7 | **H-4 / H-6 / H-7**: fix `map` → `'map'` and remove the debug echo; normalize `$OVERRIDE` types; enforce or remove legacy date locking. | S | Documented features are broken or crash. |
| 8 | **H-8 / M-4**: allow-lists for `?show=` and `?load=`. | XS | Shrinks the attack surface for good. |
| 9 | **M-12**: fixture course + smoke/regression tests for items 1-8, plus `php -l`/PHPStan in GitHub Actions. | M | Keeps these fixes from regressing. None of today's bugs would have survived a basic test. |
| 10 | **H-9 / M-10 / D-1**: update Chart.js and JSZip, trim vendored libraries and record their versions, and bump the `calendar` submodule to current core. | M | Clears scanner findings, cuts clone size by roughly 70%, and gets users the security fixes. |

*Effort: XS < 1 h, S ≈ half a day, M ≈ 1-3 days.*
