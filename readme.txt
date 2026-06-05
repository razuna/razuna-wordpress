=== Razuna DAM ===
Contributors: razuna
Tags: dam, digital asset management, media, images, razuna
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse, search and embed assets from your Razuna digital asset management account — without leaving the WordPress editor.

== Description ==

**Razuna DAM** connects WordPress to your [Razuna](https://razuna.com) digital
asset management (DAM) library so your team can browse, semantic-search and
embed approved assets directly from the editor — keeping Razuna as the single
source of truth.

* **Connect with OAuth** — sign in to your Razuna account once. The plugin stores
  an encrypted token, refreshes it automatically, and keeps it server-side (it is
  never exposed to the browser).
* **Browse & search** — pick a workspace and folder, or run a semantic search,
  from a familiar picker in both the block editor and the classic editor.
* **Insert images or links** — choose a size (full, large, thumbnail) or one of
  your saved Razuna formats, or insert a download link.
* **No duplication** — inserted images point at durable Razuna links, so the file
  stays in Razuna. Editing or replacing it there updates everywhere it is used.
* **Multi-region & self-hosted** — works with Razuna US, Razuna EU, or your own
  custom / dedicated Razuna server.

A Razuna account is required. You can [start a free Razuna trial](https://razuna.com).

= External service: Razuna =

This plugin connects your site to **Razuna**, a third-party digital asset
management service operated by Razuna (https://razuna.com). The plugin is only
useful when connected to a Razuna account, and it communicates with the Razuna
server you choose.

What data is sent, and when:

* **When you connect (admin, one time):** your site registers itself as an OAuth
  client with your Razuna server. This sends your site name and the admin
  callback URL. You then sign in on Razuna and authorize access; Razuna returns
  an access/refresh token that is stored (encrypted) on your site.
* **While browsing or searching (admin only):** the plugin sends your workspace,
  folder and search requests to your Razuna server, authenticated with the stored
  token, and receives lists of your assets. No site-visitor data is sent.
* **On published pages (front end):** inserted images are loaded by each
  visitor's browser directly from your Razuna server using signed, public
  "direct link" URLs — an ordinary image request, with no extra data attached.

Which server is contacted depends on your settings: **app.razuna.com** (US),
**app.razuna.eu** (EU), or the custom / dedicated Razuna server URL you enter.

Service provider and legal terms:

* Razuna: https://razuna.com
* Terms of Service: https://razuna.com/terms-of-service/
* Privacy Policy: https://razuna.com/privacy-policy/
* GDPR: https://razuna.com/gdpr/

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Razuna**.
3. Choose your region (US or EU), or select **Custom / dedicated server** and
   enter your Razuna server URL. Save.
4. Click **Connect Razuna**, sign in, and approve access. You will be returned to
   the settings page showing the connected account.
5. In a post or page, add the **Razuna Asset** block (block editor) or click
   **Razuna** next to **Add Media** (classic editor) to browse and insert assets.

== Frequently Asked Questions ==

= Do I need a Razuna account? =

Yes. The plugin browses and embeds assets from your Razuna account and is not
useful without one. You can start a free trial at https://razuna.com.

= Where are my images stored? =

In Razuna. WordPress embeds a durable, signed link to each asset; nothing is
copied into the WordPress media library, so Razuna remains the single source of
truth.

= Does it work with a self-hosted / dedicated Razuna server? =

Yes. Choose **Custom / dedicated server** in the settings and enter your server
URL.

= Is my Razuna token exposed to visitors? =

No. The token is stored encrypted and used only server-side. The editor's asset
picker talks to your own site (a same-origin REST proxy); the token never reaches
the browser. Embedded images use separate signed public links.

== Screenshots ==

1. Settings → choose your Razuna server and connect with OAuth.
2. Browse workspaces and folders, or semantic-search your library.
3. Choose how to insert: a size, a saved format, or a download link.

== Changelog ==

= 1.1.0 =
* Workspace dropdown now keeps "My workspace" first and sorts the remaining workspaces alphabetically.
* Asset picker now supports paged browsing and search results with infinite scroll / Load more fallback.

= 1.0.0 =
* Initial release: OAuth connection, asset browser/search, size & saved-format
  selection, download links, "Razuna Asset" block and classic-editor inserter.

== Upgrade Notice ==

= 1.1.0 =
Adds workspace sorting and paged picker results for larger Razuna libraries.

= 1.0.0 =
Initial release.
