# WordPress.org assets (optional)

When submitting to the plugin directory, place listing assets here before upload (or use the plugin SVN `assets/` folder):

- `icon-128x128.png` / `icon-256x256.png`
- `banner-772x250.png` / `banner-1544x500.png`
- `screenshot-1.png` … (Overview, Settings, Recipes, OAuth consent)

Build a review zip with:

```bash
# from repo root
rsync -a --exclude-from=.distignore ./ /tmp/mt-mcp-build/mt-mcp/
cd /tmp/mt-mcp-build && zip -r mt-mcp.zip mt-mcp
```

Then run [Plugin Check](https://wordpress.org/plugins/plugin-check/) on a staging site with that zip.
