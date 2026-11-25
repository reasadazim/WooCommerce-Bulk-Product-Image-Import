# WooCommerce Bulk Product Image Import Git Guide

WooCommerce Bulk Product Image Import by Reasad Azim updates images for existing variable products. It matches `SKU-f` and `SKU-b` image pairs to the parent SKU in WooCommerce. The front photo replaces the parent’s featured image and becomes the sole image on variations with assigned images. The back photo replaces the parent’s entire gallery. A parent must already have a featured or gallery image to qualify. See the [main README](README.md) for WordPress requirements, supported image formats, and the import workflow.

## Clone the repository

Replace `REPOSITORY_URL` with the clone URL from your Git host. For an existing checkout, run the commands in this guide from the repository root.

```sh
git clone REPOSITORY_URL
cd "PATH_TO_CLONE"
git status --short --branch
```

Replace `PATH_TO_CLONE` with the directory created by `git clone`. To work on a separate branch from the current checkout, use `git switch -c codex/update-image-importer` with a branch name that describes your change.

## Repository layout

| Path | Purpose |
| --- | --- |
| `sku-image-importer/sku-image-importer.php` | WordPress plugin header and initialization |
| `sku-image-importer/includes/class-sii-admin.php` | WooCommerce admin page and import requests |
| `sku-image-importer/includes/class-sii-batch.php` | Image filename matching and source file checks |
| `sku-image-importer/includes/class-sii-importer.php` | Product matching, Media Library imports, and image assignments |
| `sku-image-importer/assets/` | Admin JavaScript and CSS |
| `sku-image-importer/readme.txt` | WordPress-format plugin documentation |
| `sku-image-importer/Batch/` | Local source images for imports. Create this folder on the WordPress server if needed. |

## Validate changes

Run these syntax checks from the repository root with PHP and Node.js installed.

```sh
php -l sku-image-importer/sku-image-importer.php
php -l sku-image-importer/includes/class-sii-admin.php
php -l sku-image-importer/includes/class-sii-batch.php
php -l sku-image-importer/includes/class-sii-importer.php
node --check sku-image-importer/assets/admin.js
```

For a functional check, activate the plugin on a test WordPress site with WooCommerce. Create an existing variable product with a parent SKU and a featured or gallery image, then give one variation its own image and leave another empty. Add a matching front and back pair to `Batch/`, preview it, and import. Confirm the front is the parent’s featured image and the only image on the eligible variation, the back is the sole parent gallery image, and the empty variation stays empty. Repeat the import with the same files to confirm that the existing attachments are reused.

## Commit and push changes

Stage the source files and documentation you intend to publish. The following commands stage the plugin and both readmes by path while keeping local `Batch/` images and generated ZIP files out of the commit.

```sh
git status --short
git add -- README.md README-GIT.md sku-image-importer/sku-image-importer.php sku-image-importer/readme.txt sku-image-importer/includes sku-image-importer/assets
git diff --cached --check
git diff --cached --stat
git diff --cached
git commit -m "Update WooCommerce bulk image importer"
git push -u origin HEAD
```

Review the staged diff before committing, especially if other work was already staged. Adjust the file list and commit message to match your changes. The push command assumes `origin` is your intended remote and you have write access. If you use a work branch, open a pull request from that branch after pushing.

## Build an installation ZIP

From the repository root, run this command after committing the plugin files you want to package.

```sh
git archive --format=zip --output=sku-image-importer.zip HEAD sku-image-importer
```

The ZIP includes the committed `sku-image-importer/` folder with `sku-image-importer/sku-image-importer.php` inside. It excludes the repository’s Git metadata and files outside the plugin folder. To package your working copy before committing, run the following in PowerShell from a copy of the source that contains only the files you intend to distribute.

```powershell
Compress-Archive -Path .\sku-image-importer -DestinationPath .\sku-image-importer.zip
```

Upload the ZIP in WordPress and follow the [installation and import steps](README.md#installation). For routine imports, copy image pairs into the installed plugin’s `Batch/` folder after installation.

## License

GPL-2.0-or-later.
