# ABTestCraft Plugin for Craft CMS

A/B testing for Craft CMS 5 - test page variations and track conversions with statistical significance.

**Website:** [abtestcraft.com](https://abtestcraft.com)

## Features

- **Server-Side Testing**: No page flicker. Visitors are assigned variants before the page renders
- **Entry-Based Variants**: Use Craft's native entry system - test entire pages, not just CSS tweaks
- **Multi-Goal Tracking**: Track multiple conversion types per test (forms, phone clicks, emails, downloads, page visits)
- **Statistical Significance**: Chi-squared test with configurable confidence threshold (80-99%)
- **Smart Form Tracking**: Native integration with Freeform and Formie plugins
- **GA4/GTM Integration**: Push events to dataLayer for analytics tracking
- **SEO Protection**: Automatic noindex/canonical tags on variant entries
- **Cascade Support**: Child pages inherit parent's variant assignment
- **Privacy-Friendly**: First-party cookies only, no external trackers

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

1. Install with Composer:

```bash
composer require livehand/abtestcraft
```

2. Install the plugin from the Craft Control Panel under **Settings > Plugins**, or via CLI:

```bash
./craft plugin/install abtestcraft
```

## Quick Start

### 1. Create Your Test Pages

Create two entries in Craft CMS:
- **Control**: Your original page
- **Variant**: The variation you want to test

### 2. Create a Test

1. Go to **ABTestCraft** in the control panel sidebar
2. Click **New Test**
3. Enter a name and select your control/variant entries
4. Configure your goals (what counts as a conversion)
5. Set your traffic split (default 50/50)
6. Click **Start Test** when ready

### 3. View Results

Results update in real-time as visitors convert. The plugin calculates:
- Conversion rates per variant
- Statistical significance (Chi-squared test)
- Confidence level
- Relative improvement percentage
- Estimated days to significance

## Goal Types

### Phone Click
Tracks clicks on `tel:` links.

### Email Click
Tracks clicks on `mailto:` links.

### Form Submission
Tracks form submissions with smart detection:
- **Easy (Smart) Mode**: Counts successful submissions of the Freeform or Formie forms you select (Freeform 5; Formie 3 and 4). Submissions are counted on the server when the form plugin accepts them, so AJAX and page-reload forms both work, and a redirect to a thank-you page doesn't matter. Failed validation, intermediate pages of multi-page forms, submissions the form plugin flags as spam, and unselected forms don't count.
  - A **selected form** counts wherever a visitor in the test submits it, on any page of the site (for example, a contact form on another page they browse to).
  - With **no forms selected**, any Freeform or Formie form counts, but only when it's submitted on the tested page.
- **Advanced Mode**: Configure CSS selectors for custom forms. Detected in the browser on the tested page, as before.

Spam checks that Freeform runs later in the queue (such as AI-based spam checks) can't be taken into account, because the conversion is recorded when the form is submitted.

### File Download
Tracks downloads by file extension (PDF, DOC, XLS, ZIP, etc.).

### Page Visit
Tracks visits to a specific URL. Match types: exact, starts with, contains.

Counted when a visitor who has seen the test reaches the page, on any page of the site, including after a redirect (such as a form's thank-you page). A small beacon script on every page reports the visit for visitors in a test (see [How page-visit goals are counted](#how-page-visit-goals-are-counted)). A click on a tested page through to a matching URL on **another** site also counts.

### Custom Event
Track custom conversions via JavaScript:

```javascript
// In your template or JavaScript
window.ABTestCraft.trackConversion('purchase_complete');
```

## Configuration

Configure global settings in **ABTestCraft > Settings**:

| Setting | Default | Description |
|---------|---------|-------------|
| Cookie Duration | 30 days | How long visitor assignments persist |
| Track Phone Clicks | On | Automatically track tel: links |
| Track Email Clicks | On | Automatically track mailto: links |
| Track Form Submissions | On | Automatically track form submits |
| Track File Downloads | On | Automatically track file downloads |
| Conversion Rate Limit | 10/min | Max conversions per IP per test per minute |
| Conversion Counting | Per Goal Type | How to count multiple conversions |
| Significance Threshold | 95% | Confidence level to declare significance |
| Send Significance Email | On | Email when a test reaches significance |
| Notification Emails | (empty) | Who receives the significance email |
| Minimum Detectable Effect | 10% | Smallest relative improvement to detect |
| Enable dataLayer | Off | Push events to GA4/GTM |

## Twig Variables

Access split test information in your templates via `craft.abtestcraft`:

```twig
{# Check if there's an active test on this page #}
{% if craft.abtestcraft.hasActiveTest() %}
    {# Get current variant ('control' or 'variant') #}
    {% set variant = craft.abtestcraft.getActiveVariant() %}

    {# Conditional content based on variant #}
    {% if craft.abtestcraft.isShowingVariant() %}
        {# Variant-specific content #}
    {% endif %}
{% endif %}

{# For cascade support - get parent accounting for variant #}
{% set parent = craft.abtestcraft.getParent(entry) %}

{# Get children accounting for variant borrowing #}
{% set children = craft.abtestcraft.getChildren(entry) %}

{# Check if current page is a cascaded child #}
{% if craft.abtestcraft.isCascaded() %}
    ...
{% endif %}
```

## Permissions

The plugin registers two permissions:

- **Manage split tests** (`abtestcraft:manageTests`): Create, edit, start, pause, complete, and delete tests
- **View test results** (`abtestcraft:viewResults`): View test statistics and results

## GA4/GTM Integration

When enabled, the plugin pushes events to `window.dataLayer`:

### Events

| Event | When Fired | Parameters |
|-------|------------|------------|
| `abtestcraft_impression` | Visitor sees test | `abtestcraft_name`, `abtestcraft_variant` |
| `abtestcraft_conversion` | Conversion recorded | `abtestcraft_name`, `abtestcraft_variant`, `conversion_type` |

### GTM Setup

1. Create a Custom Event trigger for `abtestcraft_conversion`
2. Create Data Layer Variables for each parameter
3. Create a GA4 Event tag with your event parameters
4. Create Custom Dimensions in GA4 for reporting

## API Endpoints

### Record Conversion

```
POST /actions/abtestcraft/track/convert
```

Parameters:
- `testHandle` (string, required): The test handle
- `conversionType` (string, required): phone, email, form, download, page, or custom
- `goalId` (int, optional): Specific goal ID

No CSRF token is required, so conversions keep working on cached pages. A conversion is only recorded for a visitor who was assigned a variant (identified by their first-party cookie), and the endpoint is rate limited. Easy-mode form goals are counted on the server instead, so `form` reports for them are ignored.

### Record Page Visit

```
POST /actions/abtestcraft/track/page
```

Parameters:
- `url` (string, required): The page URL

Sent by the page-visit beacon. Records page-visit goals for the requesting visitor's running tests and returns `{"success": true, "events": [...]}`, where `events` holds the dataLayer events to push when the dataLayer is enabled. Same CSRF, cookie and rate-limit rules as above.

## Console Commands

```bash
# List all tests with their ID, status, handle and name
./craft abtestcraft/tests/list

# Show results for a test
./craft abtestcraft/tests/stats <testId>

# Clear the cached stats for a test and recalculate them (--force skips the confirmation)
./craft abtestcraft/tests/recalculate-stats <testId>

# Delete visitor assignment records older than 90 days from completed tests
# (--days=<n> changes the age, --force skips the confirmation)
./craft abtestcraft/tests/cleanup

# Print a test's results as CSV
./craft abtestcraft/tests/export <testId>
```

Test IDs are shown by `abtestcraft/tests/list`. Before 1.0.2 the commands were `./craft abtestcraft/a-b-test-craft/<command>`; that form still works. Child-page (cascade) mappings don't need a command: they're rebuilt automatically when a test is saved and when entries under a tested page are moved or added.

## How It Works

1. **Visitor arrives**: Plugin checks if there's an active test for the requested URL
2. **Assignment**: Visitor is assigned to control or variant (persisted in cookie)
3. **Template swap**: If assigned to variant, the variant entry's template is rendered instead
4. **Tracking**: JavaScript on the tested page tracks click goals (phone, email, downloads, custom events); Easy-mode form goals are counted on the server when the form plugin accepts a submission, and page-visit goals by a small beacon on every page, so conversions count wherever on the site the visitor completes them

### How page-visit goals are counted

When a visitor is assigned to a test, they also get a `_abtestcraft_on=1` cookie. It holds no data and is readable by JavaScript. Every front-end page includes a small beacon script that's identical for every visitor, so it's safe on cached pages. For visitors with that cookie, the beacon sends the page URL to `/actions/abtestcraft/track/page`, and the server records any page-visit goal it matches for the tests that visitor is in. That's one extra small request per page view, only for visitors who've been in a test. Pages are matched on their path; the query string is ignored.
5. **Statistics**: Chi-squared test calculates statistical significance
6. **Notification**: Email sent when test reaches significance threshold

## Trial

You can install ABTestCraft from the Plugin Store as a free trial and use every feature, including creating and running tests, so you can evaluate it on staging before buying. Craft shows a reminder in the control panel until you purchase a license.

## Static & Full-Page Caching (Blitz, CDNs)

Variant assignment happens on the server when a page renders, so a page must not be served from a shared full-page cache while it's being tested. ABTestCraft handles this automatically:

- **All setups**: pages with a running test (the control entry and its descendants) are sent with `Cache-Control: private, no-cache`, so CDNs and reverse proxies won't share one visitor's variant with everyone.
- **Blitz**: detected automatically, no configuration needed. ABTestCraft tells Blitz not to cache tested pages (the control entry, its child pages, and the variant entry's own URL while it carries the test's `noindex`/canonical tags). Whenever a running test is started or saved, it clears their existing Blitz cache, including query-string and paginated versions, and purges them through any configured purger such as Cloudflare. Blitz's cache generator is never assigned a variant or counted as an impression.

Only the pages under test skip the cache, and only while the test is running. Every other page stays cached as usual. When the test is paused or completed, its pages are cached again on the next visit.

If your CDN ignores origin `Cache-Control` headers (for example, a Cloudflare "Cache Everything" rule), add a bypass rule for the tested URLs while the test runs.

Pages that were cached before you updated to 1.1.0 don't include the page-visit beacon yet. Refresh your Blitz cache (or purge your CDN) once after updating, so page-visit goals are counted on cached pages too.

## Data Retention & Backup

### Database Tables

The plugin creates the following tables that should be included in your backup strategy:

| Table | Purpose | Growth Rate |
|-------|---------|-------------|
| `abtestcraft_tests` | Test configurations | Low (manual creation) |
| `abtestcraft_goals` | Goal configurations | Low (tied to tests) |
| `abtestcraft_visitors` | Visitor assignments | Medium (one per unique visitor per test) |
| `abtestcraft_visitor_goals` | Which goal types each visitor has converted on (prevents double counting) | Medium (at most one per visitor per goal type per test) |
| `abtestcraft_daily_stats` | Aggregated statistics | Low (one row per day/variant/goal) |
| `abtestcraft_test_descendants` | Child pages of each tested page (cascade support) | Low (rebuilt automatically) |
| `abtestcraft_rate_limits` | Rate limiting cache | Self-cleaning (auto-purges old entries) |

### Data Retention

- **Visitor data**: Kept until you remove it. `./craft abtestcraft/tests/cleanup` deletes visitor assignment records from completed tests (older than 90 days by default); impressions, conversions, conversion rates and significance come from the aggregated daily statistics and don't change, but the test's unique visitor counts and confidence intervals will then only reflect the visitors that remain. Permanently deleting a test removes all of its visitor data and results.
- **Statistics**: Aggregated daily, raw impressions not stored
- **Completed tests**: Remain in database for historical reference
- **Deleted tests**: **Delete test** (on a test's settings page) moves it to the **Trash** tab, where it stops running and its data is kept. From there you can **Restore** it (a test that was running comes back paused) or **Delete permanently**, which removes the test and all its results and can't be undone.

### Archiving Old Data

For high-traffic sites, consider periodically:
1. Exporting completed test results (`./craft abtestcraft/tests/export <testId>`) to your analytics platform or records
2. Running `./craft abtestcraft/tests/cleanup` to remove old visitor records from completed tests (after exporting, since it reduces their visitor counts), or deleting tests you no longer need and then deleting them permanently from the Trash tab
3. Monitoring the `abtestcraft_rate_limits` table size (auto-cleans but verify)

### Backup Recommendations

Include these tables in your database backups:
```sql
abtestcraft_tests
abtestcraft_goals
abtestcraft_visitors
abtestcraft_visitor_goals
abtestcraft_daily_stats
abtestcraft_test_descendants
```

The `abtestcraft_rate_limits` table is transient and doesn't need to be backed up.

## Best Practices

### Test Duration
- Run tests for at least 7 days to account for weekly traffic patterns
- Wait for statistical significance before declaring a winner
- Don't peek and stop early - this inflates false positive rate

### Traffic Requirements
Sample size needed depends on your baseline conversion rate and minimum detectable effect:
- 5% baseline, 10% MDE: ~3,000 visitors per variant
- 2% baseline, 10% MDE: ~8,000 visitors per variant

### Goals
- Track the metric that matters most to your business
- Enable one goal type per test for clearest results
- Use "Per Goal Type" counting for multi-goal tests

## Support

For bug reports and feature requests, please [open an issue](https://github.com/livehanddotcom/abtestcraft/issues).

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/).
