# Razuna DAM for WordPress

Browse, search, and embed your [Razuna](https://razuna.com) digital assets
directly from WordPress. Connects to Razuna over **OAuth 2.0**; published images
are served from Razuna via **durable direct links** — assets stay in Razuna as
the single source of truth, with no duplication into the WordPress media library.

## How it works

Two planes, kept separate by design:

- **Auth / management plane — OAuth.** The plugin registers itself as an OAuth
  client (dynamic client registration), runs the authorization-code + PKCE flow,
  and stores an encrypted access/refresh token. All Razuna API calls happen
  **server-side**; the token never reaches the browser. The editor's asset
  picker talks only to a same-origin WordPress REST proxy (`wp-json/razuna/v1/*`).

- **Content / delivery plane — direct links.** When you insert an asset, the
  plugin embeds a Razuna **direct link** — a 365-day signed `/file/remote` URL
  that is public and durable. Published content renders for anonymous visitors
  independently of the OAuth session (which expires every ~10 minutes).

## Editor integrations

- **Razuna Asset block** (block editor) — browse/search and embed an image.
- **"Add from Razuna"** button (classic editor) — same picker, inserts an `<img>`.

## Plugin structure

```
razuna.php                    Plugin header + bootstrap
includes/
  class-plugin.php            Orchestrator (hooks, asset registration)
  class-settings.php          Region/server config + encrypted token storage + admin page
  class-oauth.php             OAuth client: DCR, PKCE S256, callback, refresh
  class-api.php               Server-side Razuna REST client (Bearer JWT, auto-refresh)
  class-rest.php              Same-origin REST proxy (wp-json/razuna/v1/*)
  class-block.php             "Razuna Asset" dynamic block (register + server render)
  class-crypto.php            At-rest encryption for stored tokens
views/settings-page.php       Settings UI
assets/js/picker.js           Shared, build-free asset picker (vanilla JS)
assets/js/block.js            Block editor UI (wp.element, no JSX/build)
assets/js/media-modal.js      Classic-editor "Add from Razuna" + modal
assets/css/admin.css          Picker / modal / block styles
uninstall.php                 Removes options + stored tokens
docker/                       Local WordPress test harness (see docker/README.md)
```

No build step: the JS uses plain ES5 / `wp.element.createElement`, so the plugin
runs straight from a checkout (handy for the bind-mounted Docker harness).

## Requirements

- WordPress 6.0+, PHP 7.4+
- A Razuna account (US, EU, or a custom/dedicated server)
- The Razuna server must accept OAuth-issued access tokens on its v1 API
  (the `api/v1/auth_api.js` change in the `helpmonks` repo).

## Local development & testing

See [`docker/README.md`](docker/README.md) for a one-command WordPress harness
and the full connect → browse → insert → public-render walkthrough.

## Status

v0.1.0 — first cut. WordPress is the first of three planned Razuna DAM plugins
(WordPress, Shopify, Ghost).
