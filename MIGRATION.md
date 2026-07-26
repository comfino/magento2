# Migration from ZIP installation to Composer 4.0.0+

If you previously installed the Comfino Magento 2 module via ZIP package, this guide will help you migrate to the new Composer-based 4.0.0 release.

## Automated migration (recommended for beta testers)

> **Clean install instead?** This guide covers shops that already run a legacy
> ZIP/app-code Comfino module. If the shop has **never** had a Comfino module,
> use `bin/comfino-beta-install.sh install /path/to/magento` instead — it does a
> clean Composer install (no app/code migration), and `revert` removes it,
> optionally with `--purge-data` to also drop the Comfino config + order statuses
> it created. Run `bin/comfino-beta-install.sh help` for full usage.

`bin/comfino-beta-migrate.sh` performs the whole upgrade unattended and can fully
revert it. It backs up the old module (tar.gz) **and** snapshots your Comfino
settings + custom order statuses from the database before changing anything, then
installs 4.0.0 via Composer and re-runs Magento setup. Your configuration is
preserved automatically (settings are stored in the database by config path, which
is identical across 2.x/3.x/4.x).

```bash
# Upgrade (preview first, then run):
bin/comfino-beta-migrate.sh upgrade /path/to/magento --dry-run
bin/comfino-beta-migrate.sh upgrade /path/to/magento --yes

# Inspect state / list backups:
bin/comfino-beta-migrate.sh status  /path/to/magento

# Roll back to the previous module from the backup it created:
bin/comfino-beta-migrate.sh revert  /path/to/magento --yes
```

The private beta repo (`comfino/magento2-staging`) and version (`^4.0.0-beta1`) are
the built-in defaults. Deploy-key testers need nothing extra; token testers pass
`--auth-token <PAT>` (which switches the repo URL to HTTPS automatically). All of
it can be overridden via flags or environment variables (`--repo-url`,
`--constraint`, `--auth-token` / `COMFINO_COMPOSER_REPO_URL`,
`COMFINO_PACKAGE_CONSTRAINT`, `COMFINO_COMPOSER_TOKEN`). The script **never** runs
`bin/magento module:uninstall`, so existing Comfino order statuses are never
deleted. Run `bin/comfino-beta-migrate.sh help` for full usage.

### Promoting a beta shop to the public production release

Once the public stable 4.0.0+ release is published, `bin/comfino-beta-promote.sh`
switches a shop off the **private staging beta** setup onto the **public production**
package. It detaches the private staging Composer repo, drops the `-beta` constraint,
and reinstalls `comfino/magento2:^4.0.0` from Packagist / the public GitHub repo —
all settings carry over (same package + module name). It backs up
`composer.json`/`composer.lock`/`auth.json` + the Comfino config first, and `revert`
restores that backup.

```bash
# Promote (preview, then run):
bin/comfino-beta-promote.sh promote /path/to/magento --dry-run
bin/comfino-beta-promote.sh promote /path/to/magento --yes

# Install from the public GitHub VCS repo instead of Packagist:
bin/comfino-beta-promote.sh promote /path/to/magento --via-vcs --yes

# Inspect state / roll back to the staging-beta setup:
bin/comfino-beta-promote.sh status /path/to/magento
bin/comfino-beta-promote.sh revert /path/to/magento --yes
```

Defaults: Packagist source, `^4.0.0` constraint. Override with `--constraint`,
`--repo-url`, `COMFINO_PROD_CONSTRAINT`, `COMFINO_PROD_REPO_URL`. Pass `--clear-auth`
to also remove the stored private-repo token once you no longer need beta access.
Like the migrate tool, it **never** runs `bin/magento module:uninstall`.

The manual steps below remain available if you prefer to drive the migration yourself.

## Before you start

- **Backup your Magento installation** — This is a major version upgrade with architectural changes.
- **Review the CHANGELOG** — See what's new in v4.0.0+.
- **Test in a development environment first** — Do not upgrade production directly.

## Migration steps

### 1. Configure your test shop path (one-time setup)

The migration scripts need to know where your Magento installation is located. You can configure this in multiple ways:

**Option A: Environment variable (recommended for CI/CD)**
```bash
export COMFINO_TESTSHOP_PATH="/path/to/your/magento/root"
```

**Option B: Project config file (recommended for development)**
Create `.env.local` in the module project root:
```bash
cd ~/Devel/comfino/plugins/magento2-dev
echo "TESTSHOP_PATH=/path/to/your/magento/root" > .env.local
```

**Option C: Command-Line Argument (One-Off)**
```bash
bin/remove-old-zip-modules.sh /path/to/your/magento/root
```

### 2. Remove old ZIP module

Use the provided script to cleanly remove the old ZIP-based installation:

```bash
cd ~/Devel/comfino/plugins/magento2-dev
bin/remove-old-zip-modules.sh
```

This script will:
- Remove `app/code/Comfino/ComfinoGateway` directory.
- Clear cached static files for the module.
- Clear Magento's module registry cache.
- Print next steps for composer installation.

### 3. Install the new Composer 4.0.0+ module

From your Magento root directory:

```bash
cd /path/to/your/magento/root
composer require comfino/magento2:^4.0.0
```

### 4. Setup Magento

Run the standard Magento setup commands:

```bash
./bin/magento setup:upgrade
./bin/magento setup:di:compile
./bin/magento setup:static-content:deploy -f
./bin/magento cache:flush
```

### 5. Verify configuration

The module configuration is stored in Magento's database. All your previous settings should be preserved:
- API keys and credentials.
- Sandbox/production mode.
- Module-specific settings.

**Check the admin panel** at:
- **Stores → Configuration → Sales → Payment Methods → Comfino**

All your existing configurations should be intact.

## What changed in v4.0.0?

- **Composer-based distribution** — Eliminates the need for ZIP extraction.
- **Docker development environment** — Provides consistent dev setup across the team.
- **PHP 8.1+ support** — Requires PHP 8.1 or higher.
- **Updated SDK dependencies** — Uses latest Comfino PHP SDK.
- **Code standards compliance** — Passes Magento EQP (Extension Quality Program).

## Troubleshooting

### Module still shows as an old version after composer install

**Clear Magento cache:**
```bash
rm -rf var/cache/* var/page_cache/*
./bin/magento cache:flush
```

### Static files are not updating

**Redeploy static content:**
```bash
rm -rf pub/static/{adminhtml,frontend}
./bin/magento setup:static-content:deploy -f
```

### Configuration is not visible in admin

**Clear everything and reconfigure:**
```bash
./bin/magento setup:upgrade
./bin/magento setup:di:compile
./bin/magento cache:flush
```

Then go to **Stores → Configuration → Sales → Payment Methods → Comfino** and verify settings.

### The old module directory still exists

**Ensure removal was complete:**
```bash
rm -rf app/code/Comfino/ComfinoGateway
./bin/magento cache:flush
```

## Development environment setup

For local development with the new module, use the provided sync script:

```bash
# Configure path once.
echo "TESTSHOP_PATH=/path/to/magento" > .env.local

# Sync module changes to test shop (ZIP-style development).
bin/sync-to-testshop.sh

# Then deploy and flush cache.
cd /path/to/your/magento/root
./bin/magento setup:static-content:deploy -f && ./bin/magento cache:flush
```

Or use Composer for cleaner dependency management:

```bash
cd /path/to/magento
composer require comfino/magento2:dev-master --dev
```

## Support

If you encounter issues during migration:
- Check the CHANGELOG for breaking changes.
- Review module logs: `var/log/comfino.log`.
- Contact support: pomoc@comfino.pl.
- File issues: https://github.com/comfino/magento2/issues.
