# WordPress.org listing assets

Files in this directory are deployed to the plugin's SVN `/assets` directory by
the GitHub Action (they are **not** part of the shipped plugin). Drop the
following PNGs/JPGs here and tag a release:

| File | Size | Purpose |
| --- | --- | --- |
| `icon-256x256.png` | 256×256 | Plugin icon (also provide `icon-128x128.png`) |
| `banner-772x250.png` | 772×250 | Header banner (standard) |
| `banner-1544x500.png` | 1544×500 | Header banner (retina) |
| `screenshot-1.png` | — | Matches "1." under `== Screenshots ==` in readme.txt |
| `screenshot-2.png` | — | Matches "2." |
| `screenshot-3.png` | — | Matches "3." |

Notes:
- Screenshot numbers must line up with the `== Screenshots ==` list in `readme.txt`.
- An SVG icon (`icon.svg`) is also accepted by WordPress.org in place of the PNGs.
