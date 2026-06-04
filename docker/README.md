# Local test harness (Docker)

Runs WordPress at **http://localhost:8080** with the plugin bind-mounted, and
reaches a **local Razuna dev server at http://r.lan** from inside the container.

`localhost` is a loopback host, so it satisfies Razuna's OAuth redirect-URI
HTTPS requirement without needing TLS on the WordPress side.

## Prerequisites

- A local Razuna dev server reachable from the host at `http://r.lan` (the
  `helpmonks` repo dev stack, with the OAuth-token change applied — see below).
- Docker (Orbstack is fine).

## Backend prerequisite (in the `helpmonks` repo)

The plugin authenticates with OAuth. For OAuth tokens to authorize the REST API,
the helpmonks repo needs the change in `api/v1/auth_api.js` that accepts an
OAuth access token (`verifyMcpAccessToken` fallback in `_authAccessToken`).
Restart the Razuna app after pulling it.

## 1. Start WordPress

```bash
docker compose -f docker/docker-compose.yml up -d
```

Open http://localhost:8080 and complete the famous five-minute WordPress install
(any title/admin user). Then **Plugins → activate "Razuna DAM."**

## 2. Configure the server

**Settings → Razuna:**

| Field      | Value                |
| ---------- | -------------------- |
| Region     | `Custom / dedicated` |
| Server URL | `http://r.lan`       |

Save. (That's all — the OAuth token's audience is the server URL itself.)

## 3. Connect

Click **Connect Razuna** → you're sent to `r.lan` to sign in and approve → you
land back on the settings page showing **Connected as &lt;your email&gt;**.

Under the hood: the site dynamically registers an OAuth client
(`POST /oauth/register`), runs the PKCE authorization-code flow with
`resource=http://r.lan` (the server itself), and stores the JWT + refresh token
(encrypted).

## 4. Insert an asset

- **Block editor:** add a **"Razuna Asset"** block → *Browse Razuna* → pick a
  workspace/folder, search, click an image → it embeds.
- **Classic editor:** click **Razuna** next to *Add Media* → same picker →
  *Insert into post*.

## 5. Verify durable public delivery (the key check)

Publish the post, then open it in a **private/incognito window** (no WordPress or
Razuna login). The image must load — it's served from a **365-day signed Razuna
direct link** (`/file/remote?...`), independent of the OAuth session. It should
keep loading well past the ~10-minute OAuth access-token lifetime.

## Troubleshooting

- **`invalid_target` / "Unknown resource"** on connect → your Razuna server is
  missing the OAuth change that accepts its own base URL as a resource
  (`routes/app/oauth2_routes.js`). Make sure the dev server is running the
  updated code.
- **Connect returns "Could not register this site"** → the container can't reach
  `r.lan`. Confirm `curl -I http://r.lan` works from the host, and that the
  `extra_hosts: r.lan:host-gateway` mapping resolves
  (`docker compose exec wordpress getent hosts r.lan`).
- **SSL errors** → the dev mu-plugin disables `sslverify` for `r.lan`/`mcp.r.lan`
  only. If your Caddy forces HTTPS with a private cert, use `http://r.lan` for
  the Server URL (the mu-plugin covers the http→https hop).
- **401 on browse after a while** → the access token expired and refresh failed;
  click *Disconnect* then *Connect* again. Check `wp-content/debug.log`.
- **Reset everything:** `docker compose -f docker/docker-compose.yml down -v`.

## Production note

The `mu-plugins/razuna-local-dev.php` helper is **dev-only** (it relaxes TLS
verification). It lives under `docker/` and is never part of the shipped plugin.
On a real site (HTTPS), the plugin works with no helper: it registers as an
OAuth client and connects over TLS.
