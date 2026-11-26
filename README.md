# ABTestCraft Plugin for Craft CMS

A/B testing for Craft CMS 5 - test page variations and track conversions with statistical significance.

**Website:** [abtestcraft.com](https://abtestcraft.com)

## Features

- **Server-Side Testing**: No page flicker. Visitors are assigned variants before the page renders
- **Entry-Based Variants**: Use Craft's native entry system - test entire pages, not just CSS tweaks
- **Multi-Goal Tracking**: Track multiple conversion types per test (forms, phone clicks, emails, downloads, page visits)
- **Trustworthy Results**: Per-visitor conversion rates on a primary goal, a chi-squared test with a configurable confidence threshold (80-99%), and no winner called until the test has reached its planned sample size and minimum duration
- **Clean Data**: Bots, crawlers, logged-in editors and previews are left out automatically, and a warning shows when visitors aren't split as configured
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
- Conversion rates per variant: the share of visitors who completed the test's primary goal
- Statistical significance (Chi-squared test) and confidence level
- Relative improvement percentage
- The planned sample size, and how many more days until a result can be called
- A breakdown for every goal

See [How Results Are Calculated](#how-results-are-calculated).

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
| Minimum Test Duration | 7 days | Days a test must run before a winner is called |
| Send Significance Email | On | Email when a test reaches significance |
| Notification Emails | (empty) | Who receives the significance email |
| Minimum Detectable Effect | 10% | Smallest relative improvement to detect |
| Enable dataLayer | Off | Push events to GA4/GTM |
| Exclude Bots | On | Known bots and crawlers see the control and aren't counted |
| Exclude Control Panel Users | On | People logged in with control panel access see the control and aren't counted |

Each test also has a **Primary Goal** (on its settings page): the goal its result is judged on. "Any goal" (the default) counts a visitor who completed any of the test's enabled goals.

## How Results Are Calculated

- **Per visitor.** A version's conversion rate is the share of its visitors who completed the primary goal at least once. Each visitor counts once, however many pages they view or times they convert, which is what the significance test assumes. Page views and raw conversion counts are shown alongside, but don't decide the result.
- **Primary goal.** With several goals, choose the one that matters most as the test's primary goal; the others are still reported in the goal breakdown. "Any goal" counts a visitor who completed any enabled goal.
- **No early calls.** A winner is only called once each version has reached the planned sample size (worked out from the control's conversion rate, the Minimum Detectable Effect and the Significance Threshold, with 80% power) **and** the test has run the Minimum Test Duration. Until then the confidence is shown as provisional, and no significance email is sent. Checking results repeatedly and stopping the first time they look significant is the most common way A/B tests produce false winners.
- **No significant difference** once the sample is reached means the change didn't make a measurable difference: either version is a reasonable choice.
- **Split check.** If visitors split noticeably differently from the traffic split (for example 60/40 on a 50/50 test), the results page warns you: a page cache, redirect or bot traffic is usually affecting one version, which makes the comparison unreliable.
- **Conversion Counting** (in the settings) only changes the raw conversion totals, not the result.

## Who's Counted

Only real visitors take part in tests. These see the control, get no cookies and aren't counted:

- **Bots**: search engine crawlers, link previews (Slack, Facebook and the like), uptime monitors, headless browsers and HTTP libraries such as curl. Bots don't keep cookies, so otherwise each request would count as a new visitor. Turn off with **Exclude Bots**.
- **Control panel users**: anyone logged in with control panel access, so editors checking their own pages don't skew results. Turn off with **Exclude Control Panel Users**.
- **Previews**: Live Preview and shared preview links.

To see a variant on the real page while logged in, add `?abtestcraft=variant` (or `?abtestcraft=control`) to the tested page's URL. Those views are never counted, and the parameter is ignored for visitors who aren't logged in.

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

No CSRF token is required, so conversions keep working on cached pages. A conversion is only recorded for a visitor who was assigned a variant (identified by their first-party cookie), requests from bots and logged-in control panel users are ignored, and the endpoint is rate limited. Easy-mode form goals are counted on the server instead, so `form` reports for them are ignored.

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
./craft abtestcraft/tests/stats <testId or handle>

# Clear the cached stats for a test and recalculate them (--force skips the confirmation)
./craft abtestcraft/tests/recalculate-stats <testId or handle>

# Delete visitor assignment records older than 90 days from completed tests
# (--days=<n> changes the age, --force skips the confirmation)
./craft abtestcraft/tests/cleanup

# Print a test's results as CSV (only the CSV goes to standard output)
./craft abtestcraft/tests/export <testId or handle> > results.csv
```

Test IDs are shown by `abtestcraft/tests/list`. Before 1.0.2 the commands were `./craft abtestcraft/a-b-test-craft/<command>`; that form still works. Child-page (cascade) mappings don't need a command: they're rebuilt automatically when a test is saved and when entries under a tested page are moved or added.

## How It Works

1. **Visitor arrives**: Plugin checks if there's an active test for the requested URL
2. **Assignment**: Visitor is assigned to control or variant (persisted in cookie)
3. **Template swap**: If assigned to variant, the variant entry's template is rendered instead
4. **Tracking**: JavaScript on the tested page tracks click goals (phone, email, downloads, custom events); Easy-mode form goals are counted on the server when the form plugin accepts a submission, and page-visit goals by a small beacon on every page, so conversions count wherever on the site the visitor completes them

### How page-visit goals are counted

When a visitor is assigned to a test, they also get a `_abtestcraft_on=1` cookie. It holds no data and is readable by JavaScript. Every front-end page includes a small beacon script that's identical for every visitor, so it's safe on cached pages. For visitors with that cookie, the beacon sends the page URL to `/actions/abtestcraft/track/page`, and the server records any page-visit goal it matches for the tests that visitor is in. That's one extra small request per page view, only for visitors who've been in a test. Pages are matched on their path; the query string is ignored.
5. **Statistics**: per-visitor conversion rates on the primary goal, with a chi-squared test once the planned sample and minimum duration are reached (see [How Results Are Calculated](#how-results-are-calculated))
6. **Notification**: Email sent when a test has a winner

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

- **Visitor data**: Kept until you remove it. `./craft abtestcraft/tests/cleanup` deletes visitor assignment records from completed tests (older than 90 days by default). Results come from the aggregated daily statistics, so they don't change. Permanently deleting a test removes all of its visitor data and results.
- **Statistics**: Aggregated daily, raw impressions not stored
- **Completed tests**: Remain in database for historical reference
- **Deleted tests**: **Delete test** (on a test's settings page) moves it to the **Trash** tab, where it stops running and its data is kept. From there you can **Restore** it (a test that was running comes back paused) or **Delete permanently**, which removes the test and all its results and can't be undone.

### Archiving Old Data

For high-traffic sites, consider periodically:
1. Exporting completed test results (`./craft abtestcraft/tests/export <testId>`) to your analytics platform or records
2. Running `./craft abtestcraft/tests/cleanup` to remove old visitor records from completed tests (their results don't change), or deleting tests you no longer need and then deleting them permanently from the Trash tab
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
- Run tests for at least 7 days to account for weekly traffic patterns (the Minimum Test Duration setting enforces this)
- Wait until the results page calls a result before declaring a winner; until then the confidence is provisional
- Don't stop a test the first time it looks significant: early differences often disappear

### Traffic Requirements
Sample size needed depends on your baseline conversion rate and minimum detectable effect (at 95% confidence and 80% power):
- 10% baseline, 20% MDE: ~3,800 visitors per variant
- 5% baseline, 10% MDE: ~31,000 visitors per variant
- 2% baseline, 10% MDE: ~81,000 visitors per variant

Low-traffic sites should use a larger Minimum Detectable Effect (20-25%) and test bigger changes.

### Goals
- Track the metric that matters most to your business
- With several goals, set the test's Primary Goal to the one that matters most
- Enable one goal type per test for clearest results

## Support

For bug reports and feature requests, please [open an issue](https://github.com/livehanddotcom/abtestcraft/issues).

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/).
