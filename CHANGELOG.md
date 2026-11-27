# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Fixed
- Updating from 1.0.0 to 1.2.0 or later failed while a test was running (a migration read the tests with the new code before the database had its new columns), and Craft rolled the update back. Sites still on 1.0.0 can now update directly.

## [1.3.1] - 2026-11-26

### Changed
- Redesigned the results page. It opens with a plain-language verdict ("The variant converts 23.45% better", "Too early to call", "Paused by the safety guardrail"), a confidence meter marking the threshold, and each version's conversion rate drawn with its 95% range on one scale, so you can see how far apart they are.
- The results page's five colored end-test buttons are now a single "End test" menu, plus one highlighted button for the recommended step once a winner can be called.
- Charts use a calmer palette (the original in slate, the variant in indigo), with fewer, horizontal date labels. Green and red are kept for outcomes.
- Visitor counts throughout the results page and Tests list now have thousands separators.
- The Tests list shows each test's status with a colored dot, rates as "406 of 3,412", and the difference in gray until it's significant.
- The test edit page shows the test's name as its title, and its sidebar buttons no longer sit in a gray strip.

## [1.3.0] - 2026-11-26

### Added
- **Scheduling**: tests can start and end at set times. Schedules are checked during site traffic (at most once a minute) and by the new `./craft abtestcraft/tests/run-scheduled` command for cron. A scheduled start that can't happen clears the schedule and emails why.
- **Show the winner to everyone**: when the variant wins, it can be shown to everyone on the original page's URL (no cookies or tracking, cacheable, child pages included), by hand from the results page or when ending a test. Stop showing it at any time.
- **When a Winner Is Found** (per test): email only (default), end the test, or end it and show the winning variant to everyone. Only acts once a winner can be called.
- **Safety Guardrail** (per test, optional): pauses a test (or ends it with the original as the winner) when the variant converts at least a chosen percentage worse (25% by default) on a chosen goal, the drop is very unlikely to be chance (99% confidence by default, on a sequential test that stays reliable when checked continuously), and there's enough data (250 visitors per version and 25 conversions by default). The percentage, goal, strictness and minimums are adjustable. The results page shows its status, and the form explains that on lower-traffic sites it often won't trigger until each version has a few thousand visitors.
- **Consent mode** (setting, off by default): only visitors who've given consent take part; everyone else sees the original page, gets no cookies and isn't counted, and visitors who withdraw consent are taken out of tests. Consent comes from Google Consent Mode (`analytics_storage` by default), a consent banner's cookie (Cookiebot, CookieYes, Complianz, OneTrust or custom), or `ABTestCraft.setConsent()`.
- Emails when the safety guardrail acts, when a winner is applied automatically, and when a schedule starts, ends or can't start.
- **Weekly summary** email on Monday mornings (off by default).
- A warning on ABTestCraft's control panel pages when emails are switched on but no notification address is set.
- Microsoft Clarity sessions are tagged with each visitor's version (`abtestcraft_<test handle>`), however Clarity is added to the page, including later through a tag manager or after cookie consent.

### Changed
- The "has a winner" email is sent once per test. It could repeat every hour while a test kept getting conversions.
- Saving consent settings in the control panel refreshes the Blitz cache, since the script on every page changes.

### Fixed
- Fixed the trashed date, conversion dates and the `cleanup` cutoff being written in the site's timezone while dates are read as UTC.
- Fixed the Tests page ignoring the site selected in the control panel on multi-site installs. Tests on other sites couldn't be listed, and new tests were always created on the primary site. The Tests page now has a site switcher, and the entry pickers only offer entries from the test's site.
- Fixed control panel errors when the plugin's settings, Tests list or results templates were opened directly by URL (such as `admin/abtestcraft/settings`). They now return a 404.
- Fixed the 1.2.0 results migration taking hours on large PostgreSQL sites upgrading from 1.0.x (about 12 seconds for a million visitors now).
- Fixed page visit goals not matching on sites with a path in their base URL (such as `/fr/`). Goal paths are now matched relative to the test's site as well as the full path.
- Fixed the results page's test buttons running off the edge of narrow windows. They now wrap onto a second line.

## [1.2.0] - 2026-11-26

> [!IMPORTANT]
> Results are now calculated per visitor, so the numbers on existing tests change when you update. Conversion rates used to divide conversions from all goals by page views (every load of a tested page, including reloads and child pages), which understated rates and overstated confidence. A winner is now only called once a test has reached its planned sample size and run at least 7 days, so a test that showed "significant" before may now show "Collecting Data". Bots and logged-in control panel users are no longer counted from now on. Run `./craft up` after updating: the migration rebuilds visitor counts from your existing visitor records.

### Added
- Each test has a **Primary Goal**: the goal its result is judged on. "Any goal" (the default) counts a visitor who completed any enabled goal. The other goals are still reported in the goal breakdown.
- A winner is only called, and the significance email only sent, once each version has reached the planned sample size and the test has run the new **Minimum Test Duration** setting (7 days by default). Until then the results page shows the confidence as provisional and how many days are left.
- A warning on the results page when visitors split noticeably differently from the configured traffic split, which usually means a page cache, redirect or bots are affecting one version.
- Bots, crawlers, link previews, uptime monitors and HTTP libraries see the control and aren't counted (**Exclude Bots**, on by default).
- People logged in with control panel access see the control and aren't counted (**Exclude Control Panel Users**, on by default). They can preview a variant on the real page with `?abtestcraft=variant` or `?abtestcraft=control`; those views are never counted.
- Console `stats`, `recalculate-stats` and `export` accept a test handle as well as an ID.

### Changed
- Conversion rates are the share of visitors who converted on the primary goal, and significance is tested on visitors (each visitor is one trial). Page views and raw conversion counts are shown alongside.
- The significance test uses an exact p-value instead of a lookup table, and the planned sample size uses the configured significance threshold (it assumed 95%).
- Visitor counts are kept in the daily statistics, so `cleanup` no longer changes a test's results. `cleanup` also removes the deleted visitors' goal records.
- The conversion rate chart shows the cumulative rate, and the traffic chart shows new visitors per day.
- `export` prints only the CSV to standard output, so `> results.csv` gives a clean file, and includes per-goal results.
- Conversion Counting now only changes the raw conversion totals, not the result.
- Chart.js is bundled with the plugin instead of loaded from a CDN.

### Fixed
- Fixed previews (Live Preview and preview links) assigning a variant and counting an impression.
- Fixed two running tests being able to target the same page, in which case one of them silently got no traffic. A test can't be started on a page that's already part of a running test, including as a child page.
- Fixed test start and end dates being read in the site's timezone instead of UTC, which shifted test durations and the dates shown in the control panel by the site's UTC offset.
- Fixed the progress column on the Tests page always showing 0%.
- Fixed the significance email linking to `/admin` on sites with a different control panel path.
- Fixed the conversion endpoint giving visitors who weren't in a test a visitor ID cookie.
- Fixed the README's sample size examples, which were about ten times too low.

## [1.1.0] - 2026-11-26

> [!IMPORTANT]
> Conversions now count wherever on the site a visitor completes them after being assigned to a test, not only on the tested page. This is standard A/B test attribution and counts landing page → contact page → thank-you page journeys that were missed before. If you use Blitz or a CDN, refresh the cache once after updating so cached pages include the new page-visit beacon.

### Added
- Easy-mode form goals are counted on the server when Freeform or Formie accepts a submission (Freeform 5, Formie 3 and 4). AJAX and page-reload forms both work, forms that redirect to a thank-you page are now counted, and page caching doesn't affect them.
- Page-visit goals count on any page of the site, including after a redirect, through a small beacon script on every front-end page. The beacon is the same for every visitor, so it's safe on Blitz- and CDN-cached pages, and only sends a request for visitors who've been assigned to a test (marked by a new `_abtestcraft_on` cookie).
- A selected form now counts wherever a visitor in the test submits it, not only on the tested page.
- Page-visit conversions are pushed to the dataLayer (when enabled) on whichever page they happen.

### Changed
- Easy-mode form goals with no forms selected still count any Freeform or Formie form only when it's submitted on the tested page.
- On the tested page, page-visit goals are only counted from link clicks when the link goes to another site; visits to pages on this site are counted by the beacon.
- The tracking endpoint ignores `form` conversions for Easy-mode form goals, so a submission is never counted twice (including from pages cached with an older tracking script).

### Fixed
- Fixed form submissions that redirect to a thank-you page outside the test never being counted.
- Fixed Formie page-reload submissions that the server rejected as spam still being counted: the form plugin's own spam verdict is now used.
- Fixed page-visit goals not counting when the goal page was reached by a redirect or by browsing other pages first.

## [1.0.2] - 2026-11-26

### Added
- Tests can now be deleted from the control panel: **Delete test** on a test's settings page moves it to a new **Trash** tab on the Tests page. Trashed tests stop running and keep their data; from the Trash tab you can restore them or delete them permanently, which also removes their results.
- Shorter console commands: `./craft abtestcraft/tests/list`, `stats`, `recalculate-stats`, `cleanup` and `export`. The previous `./craft abtestcraft/a-b-test-craft/<command>` form still works.

### Changed
- Restoring a test that was running when it was deleted brings it back paused, so visitors aren't sent to the variant again until you start it. Its pages are cleared from the Blitz cache when you start it, rather than when it's restored.

### Fixed
- Fixed there being no way to delete a test, or to restore or permanently delete one in the trash, from the control panel. A trashed test's handle couldn't be reused, and the error message pointed to options that didn't exist.
- Fixed tests in the trash being editable, startable or completable by opening their URLs directly; their pages now send you to the Trash tab.
- The tab counts on the Tests page now update right away after a test is saved, completed, deleted or restored, instead of up to a minute later.

## [1.0.1] - 2026-11-25

### Added
- Blitz compatibility: pages with a running test (the control entry, its child pages and the variant entry's own URL) are excluded from the Blitz cache. Their cached copies, including query-string and paginated versions, are cleared and purged through any configured purger (e.g. Cloudflare) when a running test is saved, started or restored, and again once a queued child-page rebuild finishes. Blitz's cache generator is never assigned a variant or counted as an impression. ([#2](https://github.com/livehanddotcom/abtestcraft/issues/2), [#6](https://github.com/livehanddotcom/abtestcraft/issues/6))
- Pages with a running test are sent with `Cache-Control: private, no-cache` so CDNs and reverse proxies don't share one visitor's variant.

### Changed
- Trial installs can create and run tests, so the plugin can be fully evaluated before purchase. ([#6](https://github.com/livehanddotcom/abtestcraft/issues/6))
- The license notice (trial or license problem) now appears only on ABTestCraft's own control panel pages instead of every page, and replaces the duplicate banner on the Tests page.
- The conversion tracking endpoint no longer requires a CSRF token, which went stale on cached pages and caused conversions to be dropped. Conversions are still only recorded for the visitor's own assigned variant, and the endpoint is still rate limited.

### Fixed
- Fixed "Argument #1 ($id) must be of type int, string given" errors when saving, starting, pausing, completing or deleting tests and saving learnings. ([#1](https://github.com/livehanddotcom/abtestcraft/issues/1), [#3](https://github.com/livehanddotcom/abtestcraft/pull/3), [#5](https://github.com/livehanddotcom/abtestcraft/issues/5))
- Fixed type errors when the database driver returns integer columns as strings.
- Fixed the license status always reading as "Unknown" on Craft 5, which meant trial and license problems were never detected. The plugin now reads the status Craft actually keeps.
- Fixed a server error (HTTP 500) when saving a test with a handle already used by another test, including one in the trash. It's now a validation error on the Handle field.
- Fixed the test form losing everything you'd typed when a save failed validation.
- Test actions now reject malformed test IDs with a 400 error instead of acting on the wrong test.
- Fixed the "variant can't be a child of the control" check ignoring disabled entries, so a disabled child entry could be chosen as the variant.
- A variant's canonical link is now only added when the control entry is live, so it never points at a 404.
- Fixed a server error (HTTP 500) for visitors shown the variant when the control entry has child entries, on all Craft 5 versions.
- Fixed conversions being over-counted in the default "one conversion per goal type" mode: a visitor alternating goal types (e.g. phone, email, phone) was counted again each time (5 alternating conversions counted as 5 instead of 2). Conversion de-duplication now uses a new `abtestcraft_visitor_goals` table with a unique key, which also stops simultaneous requests from counting the same conversion twice; the upgrade migration backfills existing conversions.
- Fixed a conversion being lost permanently if updating the stats failed partway through.
- Fixed saving an existing test moving it to whichever site the control panel was viewing.
- Fixed new tests being creatable by posting directly to the save action when the license doesn't allow it.
- Fixed impressions and conversions being undercounted under concurrent traffic (in testing, 26–48% of impressions were lost at 20 concurrent requests). Counts are now atomic, and the first hit of the day can no longer create duplicate stats rows.
- Fixed the significance email being sent again on every conversion once a test was significant; the one-hour cooldown now applies.
- Fixed tested pages becoming cacheable by CDNs if a template or another plugin set a public `Cache-Control` header later in the request; the private header is now enforced on the final response.
- Fixed Freeform page-reload (non-AJAX) forms never counting in Easy (smart) mode; they're now counted when Freeform shows its success message after the reload.
- Fixed Formie page-reload forms never counting in Easy (smart) mode, and added Formie 4 support, with or without its compatibility mode. Intermediate pages of multi-page forms and Back/Save actions don't count, and submissions Formie 4 silently rejects as spam aren't counted for AJAX forms.
- Fixed the `craft.abtestcraft.buildFormSelector()` Twig helper returning selectors that don't match Freeform 5 or Formie 3/4 forms.
- Fixed tests never showing the variant when the variant (or control) entry is disabled, pending or expired. Variants are usually kept disabled so they aren't public at their own URL; they're now loaded regardless of status. ([#7](https://github.com/livehanddotcom/abtestcraft/pull/7))
- Fixed saving a test failing with "Handle must be lowercase" when the handle was auto-generated from a multi-word name: the control panel generates camelCase handles (e.g. `homepageHero`), which are now accepted alongside kebab-case, including by the conversion tracking endpoint. ([#4](https://github.com/livehanddotcom/abtestcraft/pull/4))
- Fixed tests in the trash still running: a trashed test no longer swaps variants, records conversions, adds SEO tags, or keeps its pages out of the page cache. Restoring a running test from the trash clears its pages from the Blitz cache.
- Fixed Freeform form goals in Easy (smart) mode: the success handler threw a JavaScript error on Freeform 5 and never matched selected forms, while any form submit on the page (including failed validation or unrelated forms) was counted instead. AJAX forms are now counted only when Freeform or Formie reports a successful submission of a selected form; page-reload forms are counted when a selected form is submitted and the form plugin didn't cancel the submit (e.g. failed client-side validation). For the most accurate form goals, use AJAX forms. Formie 4 form handles (`data-formie-handle`) are now recognised.

## [1.0.0] - 2026-01-11

### Added
- Initial release for Craft CMS 5
- Server-side A/B testing with entry-based variants
- Multi-goal conversion tracking:
  - Phone click tracking (`tel:` links)
  - Email click tracking (`mailto:` links)
  - Form submission tracking (with Freeform/Formie integration)
  - File download tracking (PDF, DOC, XLS, ZIP, etc.)
  - Page visit tracking (exact, starts with, contains matching)
  - Custom event tracking via JavaScript API
- Chi-squared statistical significance testing
- Configurable confidence threshold (80-99%)
- Minimum detectable effect (MDE) calculator
- Traffic split configuration (0-100%)
- GA4/GTM dataLayer integration
- Automatic SEO protection for variant entries (noindex, canonical)
- Cascade support for nested entry structures
- Soft delete support with audit trail
- Email notifications when tests reach significance
- Rate limiting for conversion tracking
- User permissions: Manage Tests, View Results
- Twig variables for template integration
- Console commands to list tests, show and export results, recalculate stats, and clean up old visitor data
- Control panel interface for test management and results
