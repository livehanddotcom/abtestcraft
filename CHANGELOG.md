# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - Unreleased

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
