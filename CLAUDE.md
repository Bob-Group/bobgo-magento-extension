# CLAUDE.md

Bob Go Shipping Extension for Magento 2 (`BobGroup\BobGo`). PHP ^7.4/^8.0/^8.2.

For architecture, API details, config paths, and component docs, read `docs/spec.md` as needed — not every conversation.

## Commands

```bash
# Tests
vendor/bin/phpunit Test/Unit/

# Bump version (patch|minor|major|x.y.z) — updates composer.json + etc/module.xml
./bump-version.sh patch

# Distribution zip
git archive --format=zip HEAD -o bobgo-magento-extension.zip
```

## Deployment (remote test server: bitnami@ip-10-107-3-85)

```bash
rsync -avz --exclude='.git' --exclude='vendor' --exclude='Test' \
  ./ bitnami@ip-10-107-3-85:/opt/bitnami/magento/app/code/BobGroup/BobGo/

# After di.xml/events.xml/constructor changes:
sudo rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile && sudo php bin/magento cache:flush

# PHP logic changes only:
sudo php bin/magento cache:flush
```

After deploying, clear PHP opcache from web context (Apache restart alone is not enough). See `docs/spec.md` Troubleshooting for procedure.

## Branches

- `main` — stable/production
- `dev` — development (default working branch)

## Version

Source of truth: `composer.json` (synced to `etc/module.xml`).

## DI Gotchas

Follow these when writing or modifying PHP classes:

1. **Constructor param naming:** Never use common names like `$request` for custom params in Magento core subclasses. Magento inherits DI argument mappings by name. Use unique names (e.g., `$httpRequest`).
2. **Encrypted config:** `scopeConfig->getValue()` returns raw encrypted text for `Backend\Encrypted` fields. Use `EncryptorInterface::decrypt()`.
3. **Stale config in observers:** During config save, in-memory ScopeConfig is stale. Inject `ReinitableConfigInterface` and call `reinit()` first.
