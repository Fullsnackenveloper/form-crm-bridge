# Platform Notes

Behaviors, failure modes, and traps for each supported platform. These are the things that cost hours when nobody writes them down, collected here from building and operating these integrations in production.

---

## Neos | creates an intake

**Auth:** bearer token obtained from the login endpoint, cached until it nears expiry rather than requested fresh on every submission. Without caching, every lead pays for an extra round trip and generates avoidable auth traffic against a partner API.

**Token refresh:** a 401 triggers exactly one refresh and one retry. A second 401 is a hard failure, it means a revoked or misconfigured credential, which needs an operator, not a retry loop.

### The trap: per-field permissions fail quietly

Write permissions are scoped per tenant and **do not fail loudly at onboarding**. The connection can authenticate perfectly while an individual field write returns 403. In practice this showed up as a 403 on email fields specifically, caused by missing tenant permissions — nothing about the token or the connection looked wrong.

**Control:** when onboarding a new tenant, explicitly verify write permission on *each mapped field*, not just that the token works. The adapter logs a specific hint when it sees a 403, because the raw error doesn't explain itself.

### Lead time

Neos requires a real partner API onboarding path: staging credentials, then permission grants, then production. This is the longest lead time of the supported platforms and should be started early on any new client using it.

---

## Lead Docket | creates an opportunity

**Auth:** a static security key appended to the endpoint rather than a token exchange. Simpler, no refresh lifecycle, no caching layer, but it means a long-lived secret sits in site configuration and key rotation is a manual operation.

**Format:** the endpoint accepts form-urlencoded, not JSON.

### The trap: HTTP 200 does not mean accepted

Lead Docket reports application-level success in the response body, separately from the HTTP status. A **200 response with `success: false`** is a rejection, not a delivery.

**Control:** the adapter interprets the body after the transport reports HTTP success, and treats a false success flag as a hard failure with the platform's own reason logged. It is not retried, retrying will not change a rejected payload.

### Mapping is more client-specific here

Opportunity records carry case type and source fields that each firm configures itself. Expect a mapping review per install rather than copying the previous one.

---

## HubSpot | creates a contact

**Auth:** private app token, scoped to the client's portal.

### The trap: silent field drops

HubSpot will accept a submission and **discard properties it does not recognize without raising an error**. A 200 response does not mean every field landed. This is the single most misleading behavior across all supported platforms, because everything about the response says success.

**Control:** the adapter optionally reads the created contact back and logs any mapped property that did not persist, by name. Validation has to confirm the *contents* of the created record, not just the status code.

```php
define('FCB_HUBSPOT_VERIFY_WRITE', true);   // on by default
```

### The trap: enum and picklist formatting

Picklist properties must match HubSpot's **internal values** exactly, not the display labels shown in the UI. This is the most common source of mapping bugs on this platform, and it fails silently in exactly the way described above.

### Custom properties must already exist

A property that hasn't been created in the portal cannot be written. Sending an undefined property is the usual root cause behind a silent drop.

### Duplicates

HubSpot rejects a contact whose email already exists with a 409. This is expected behavior rather than an error to retry, and the adapter logs it as such.

---

## Platforms in a different shape

Not every CRM fits the adapter model cheaply. Adapters are inexpensive when a platform's auth resembles something already built. A platform requiring **OAuth 2.0 authorization-code flow plus a background queue architecture** is a fundamentally different shape, the auth is interactive, tokens require refresh storage, and submissions can't be delivered synchronously inside a form hook.

Platforms in that category should be scoped as their own piece of work rather than folded into an adapter estimate. Naming that distinction up front is what keeps the effort model honest.

---

## Effort model

This is the part that matters most for planning.

| Work | Cost | Frequency |
|---|---|---|
| Shared core | Built once | Once, total |
| New platform adapter | Auth + mapping + error semantics | Once per CRM |
| Additional client on a supported platform | Credentials, field map review, form allowlist, validation | Once per client |

Each new platform costs roughly the same bounded amount, and each new client on a supported platform costs very little. The suite gets cheaper to operate as it grows rather than more expensive, which is the entire argument for the shared-core design.

---

## Operational open questions

Honest gaps worth naming rather than pretending are solved:

**Credential storage.** Credentials currently live in per-site configuration. That's simple and keeps secrets out of the codebase, but it means rotation is a per-site operation and there's no central inventory of which site holds which key.

**Centralized monitoring.** Per-site logs answer "did this lead make it?" Nothing currently answers "which of our sites had failed submissions this week?" in one view. That's the natural next build.