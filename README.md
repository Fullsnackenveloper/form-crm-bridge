# Form to CRM Bridge

A solution that forwards website form submissions straight into a CRM. Gravity Forms and Elementor Pro submissions are normalized, mapped to the target platform's API format, and posted to create the record: an intake in Neos, an opportunity in Lead Docket, a contact in HubSpot.

Live demo: **https://bridge-demo.msschermer.us** (submit a sample form and watch the normalization and per platform payloads happen in real time)

---

## Why this exists

Websites collect leads through forms. Those leads have to reach the business CRM / case management system, and every firm uses a different one. The naive approach is to build an integration per firm, which means the expensive part, figuring out a platform's auth, field mapping, and failure semantics, gets re-solved on every project.

I built this integration, then tailored it several times for different platforms, and the rebuilds made the real shape of the problem obvious: roughly 80% of each one was identical, and the identical part was being copy pasted. This repo is that pattern refactored properly. One shared core, with each platform reduced to a thin adapter.

The core value is not any one integration. It is that the skeleton is shared, so the expensive work gets solved **once per CRM** rather than once per client. Every later client on a supported platform becomes an install and configure job rather than a build.

---

## Architecture

The design boundary is the whole point: separate what is identical everywhere from what genuinely differs per platform.

### Shared, written once: `core/`

| File | Responsibility |
|---|---|
| `capture.php` | Normalizes Gravity Forms and Elementor Pro hooks into one internal payload shape. Nothing downstream knows which form plugin fired. Supporting another form plugin later touches only this file. |
| `mapper.php` | Applies alias resolution to produce a canonical field set from whatever keys the form builder produced. |
| `transport.php` | Performs the POST, applies retry policy, interprets HTTP level outcomes. |
| `logger.php` | Records the outcome of every submission, including the recoverable payload on failure. |

### Per adapter, the actual work: `adapters/`

Each adapter supplies only three things:

* **Authentication** acquisition and lifecycle.
* **Field mapping** into that platform's request body, including required fields and its own validation quirks.
* **Response interpretation**, meaning what counts as success, what is worth retrying, and what is a hard failure.

Everything else is inherited.

---

## The adapter contract

An adapter registers itself and implements two functions:

```php
add_filter('fcb_register_adapters', function (array $adapters): array {
    $adapters[] = [
        'id'            => 'myplatform',
        'is_configured' => 'fcb_myplatform_is_configured', // bool
        'send'          => 'fcb_myplatform_send',          // bool
    ];
    return $adapters;
});
```

`send()` receives the canonical `$fields` array and a `$context` array (form name, page URL, UTM parameters), builds the platform's payload, hands it to `fcb_post_with_retry()`, and reports the outcome.

`adapters/webhook.php` is the smallest complete example. Start there when adding a platform.

### Adding a platform

To support a new CRM, an engineer writes an auth handler for that platform's scheme, a field map to its request body, response interpretation rules, and a short note on its quirks in [PLATFORM-NOTES.md](PLATFORM-NOTES.md). Form capture, transport, retries, logging, and configuration are inherited.

The practical result is that "can you support platform X?" becomes a scoping question with a predictable estimate instead of an open ended one.

One caveat on estimating. Adapters are only cheap when the platform's auth model resembles something already built. A platform requiring OAuth 2.0 authorization code flow plus a background queue is a different shape entirely and should be planned as its own piece of work rather than folded into an adapter estimate.

---

## Reliability

Behavior is consistent across every adapter, because it lives in the core.

**Retry on transient failures.** Network timeouts, 429 rate limiting, and 5xx responses, with exponential backoff (2s, 4s, 8s, 16s) and a bounded attempt count.

**Do not retry hard failures.** Validation and auth errors in the 4xx range. Retrying a malformed payload just multiplies the failure, so these are logged for human attention instead.

**Auth handling.** A 401 triggers one credential refresh and one retry, then fails hard. A repeated 401 means a rotated or revoked credential, which needs an operator rather than a retry loop.

**Nothing is silently lost.** A submission that exhausts its attempts is logged with its full payload and error, so the lead is recoverable by hand. The site's existing form notification still fires regardless.

**Nothing can break a form submission.** Every hook is wrapped in `try`/`catch`, and one adapter failing does not prevent the others from delivering.

**Safe by default.** The plugin can be activated with nothing configured. It logs a notice and does nothing.

---

## Configuration

All credentials live in `wp-config.php`, never in the plugin. An adapter activates itself when its own settings are present.

```php
// Neos
define('FCB_NEOS_INTEGRATION_ID',   '...');
define('FCB_NEOS_API_KEY',          '...');
define('FCB_NEOS_COMPANY_ID',       '...');
define('FCB_NEOS_SUBSCRIPTION_KEY', '...');
define('FCB_NEOS_ENV',       'staging');   // or 'production'
define('FCB_NEOS_CASE_TYPE', 'EXAMPLE CASE TYPE');

// Lead Docket
define('FCB_LD_ENDPOINT',     'https://YOURFIRM.leaddocket.com/opportunities/form/1');
define('FCB_LD_SECURITY_KEY', '...');

// HubSpot
define('FCB_HUBSPOT_TOKEN', '...');

// Generic webhook, also the easiest way to test
define('FCB_WEBHOOK_URL', 'https://webhook.site/your-id');
```

Submissions are opt in per form rather than global. A site with a newsletter signup and a case evaluation form should only be sending the latter:

```php
define('FCB_GF_INCLUDE_IDS', '1,3');                 // only these form IDs
define('FCB_ELEMENTOR_INCLUDE_FORMS', 'Case Evaluation');
// or the inverse:
define('FCB_GF_EXCLUDE_IDS', '2');
define('FCB_ELEMENTOR_EXCLUDE_FORMS', 'Newsletter');
```

Optional debug output (full payloads, off by default):

```php
define('FCB_DEBUG', true);
```

### Form field keys

Set the field's **Admin Field Label** (Gravity Forms) or **field ID** (Elementor Pro) to one of the canonical keys: `fullname` (or `firstname` plus `lastname`), `email`, `phone`, `message`, `dateofincident`, `city`, `state`, `zip`.

Common variations are resolved automatically. Keys like `your-email`, `tel`, `comments`, and `zipcode` all map correctly without configuration.

---

## Installation

1. Copy the plugin folder to `wp-content/plugins/`, or upload a zip of it through **Plugins → Add New → Upload**.
2. Activate it.
3. Add the relevant defines to `wp-config.php`.
4. Set the form field keys as above.
5. Validate before going live (below).

---

## Validation before a site goes live

Verifying the HTTP status code is not sufficient on any of these platforms.

* **Use staging credentials first** where the platform offers them.
* **Verify the record, not the response.** Open the created intake, opportunity, or contact in the CRM and confirm every mapped field actually populated. This is the specific control for HubSpot's silent field drops and for Neos field level permission failures.
* **Test each allowed form individually.** Different forms carry different field sets, and it is common for one to map cleanly while another has a gap.
* **Force a failure.** Submit with a deliberately invalid payload or a revoked credential, then confirm the error is logged and the site's fallback notification still fires.
* **Confirm no duplicate records** are created when a retry occurs.

---

## Platform notes

Each platform has behaviors that cost hours if nobody wrote them down. They are documented in **[PLATFORM-NOTES.md](PLATFORM-NOTES.md)**, covering auth models, known failure modes, and the specific traps for each.

---

## Repository layout

```text
form-crm-bridge.php     # bootstrap: loads core, registers adapters
core/
  capture.php           # form hooks to normalized payload
  mapper.php            # alias resolution to canonical fields
  transport.php         # POST plus retry policy
  logger.php            # outcome logging
adapters/
  neos.php              # bearer token, creates an intake
  leaddocket.php        # security key, creates an opportunity
  hubspot.php           # private app token, creates a contact
  webhook.php           # generic endpoint, reference adapter and test target
```