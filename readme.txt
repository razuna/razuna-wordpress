=== Razuna DAM ===
Contributors: razuna
Tags: dam, digital asset management, media, images, oauth
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse, search and embed your Razuna digital assets in WordPress. Connect over OAuth; images are served from Razuna via durable direct links.

== Description ==

Razuna DAM connects WordPress to your [Razuna](https://razuna.com) digital asset
management library so editors can browse, semantic-search and embed assets
without leaving the editor.

* **OAuth connection** — sign in to Razuna once; the plugin stores an encrypted
  token and refreshes it automatically. The token stays server-side.
* **Reference, don't duplicate** — inserted images point at durable Razuna direct
  links, so Razuna stays the single source of truth (no copies in your media
  library).
* **Block + classic editor** — a "Razuna Asset" block and an "Add from Razuna"
  button.

== Installation ==

1. Upload the plugin to `wp-content/plugins/razuna-wordpress` and activate it.
2. Go to **Settings → Razuna**, choose your region (US/EU) or a custom server.
3. Click **Connect Razuna**, sign in, and approve access.
4. Add a **Razuna Asset** block (or use **Add from Razuna** in the classic
   editor) to insert assets.

== Frequently Asked Questions ==

= Where are my images stored? =

In Razuna. WordPress embeds a durable, signed direct link to each asset; nothing
is copied into the WordPress media library.

= Does it work on a custom / self-hosted Razuna server? =

Yes — choose "Custom / dedicated server" and enter your server URL.

== Changelog ==

= 0.1.0 =
* First release: OAuth connection, asset picker, Razuna Asset block, classic-editor button.
