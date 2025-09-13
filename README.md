# Party Plan Builder — Handover

Plugin: party-plan-builder v1.6.2
Purpose: Auto-calculating party/venue quote builder for WordPress.
Install: Plugins → Add New → Upload plugin `party-plan-builder-1.6.2.zip` → Activate.
Shortcodes:
- [party_plan_builder template="simple"]
- [party_plan_builder template="advanced" show_estimate="gated"]
Test: create page with shortcode, submit a quote, confirm CPT `ppb_quote` is created.

Handover pack attached in repo.

## Release

The plugin version is defined in `package.json`.

1. Run `npm version <newversion>` to bump the version. This runs `bin/bump-version.sh` which updates `party-plan-builder.php` and README references.
2. Build the ZIP archive:
   `zip -r party-plan-builder-$(node -p "require('./package.json').version").zip party-plan-builder`
3. Upload the ZIP to WordPress or distribute as needed.
