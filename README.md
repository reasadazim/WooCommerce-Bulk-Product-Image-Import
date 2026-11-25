# WooCommerce Bulk Product Image Import

By Reasad Azim

Bulk import front and back product photos into WooCommerce using the SKUs of existing variable products. Preview matched products and variations, select the SKUs to update, and follow the import results from the WooCommerce admin screen.

## Requirements

- WordPress 6.4 or later
- PHP 7.4 or later
- WooCommerce installed and active
- A user who can manage WooCommerce, upload media, and edit the affected products and variations

## Installation

1. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin** and upload `sku-image-importer.zip`. For a manual installation, copy the `sku-image-importer` folder into `wp-content/plugins/`.
2. Activate **WooCommerce Bulk Product Image Import** in **Plugins**.
3. Create `wp-content/plugins/sku-image-importer/Batch/` on the WordPress server if the folder is missing. Copy your image pairs directly into it.

See the [Git and development guide](README-GIT.md#build-an-installation-zip) to build the installation ZIP from source.

## Prepare the image pairs

Name each pair after the SKU assigned to the **variable parent product**. For a parent SKU of `B0001`, use these filenames.

| Filename | How it is used |
| --- | --- |
| `B0001-f.jpg` | Replaces the parent’s featured image and becomes the sole image of each variation that already has its own thumbnail or gallery. |
| `B0001-b.jpg` | Replaces the entire parent product gallery with the back photo. |

Each SKU needs exactly one front and one back image. Supported extensions are JPG, JPEG, PNG, WebP, GIF, and AVIF, subject to your WordPress server’s image support. Use the SKU as it appears in WooCommerce. The scan groups filenames case-insensitively and flags duplicate front or back images for review.

The parent product must already have a featured image or at least one gallery image. The importer skips parents with empty image fields and leaves variations with no image of their own in their current state. An image inherited from the parent does not qualify a variation. An eligible parent’s images can still be updated when none of its variations qualify.

## Run an import

1. Go to **WooCommerce → WooCommerce Bulk Product Image Import**.
2. Click **Scan Batch folder**. Review each row’s parent SKU, filenames, eligible variation IDs, and any skipped or ignored entries.
3. Select the ready SKUs you want to update and click **Import selected SKUs**. Keep the page open while the importer processes one SKU at a time. **Stop after current SKU** pauses before the next product.
4. Review the results and reload any open product editor tabs to see the saved images before editing or saving the product.

If a preview expires or images or eligible variations change after scanning, scan again. After correcting a failed row, rescan and retry the affected SKU. Repeated imports reuse attachments when the filename and image contents are unchanged.

The importer updates image assignments on existing products and variations. Previous uploads remain in the Media Library, and the source images remain in `Batch/`. Save a copy of `Batch/` before deleting or replacing the plugin folder. Deactivating or deleting the plugin keeps the imported media and image assignments in place.

## License

GPL-2.0-or-later.
