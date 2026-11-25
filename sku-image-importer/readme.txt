=== WooCommerce Bulk Product Image Import ===
Requires at least: 6.4
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.5
License: GPL-2.0-or-later

Bulk import front and back images by parent SKU for existing WooCommerce variable products.

== Description ==

Developed by Reasad Azim. Pair front and back images in the plugin's Batch folder and preview the products and variations that will be updated. The front replaces the featured image and becomes the sole image on variations with assigned images. The back replaces the entire parent product gallery.

== Installation ==

1. Upload sku-image-importer.zip via Plugins > Add New Plugin > Upload Plugin, then activate it. WooCommerce must be active.
2. Create wp-content/plugins/sku-image-importer/Batch/ if needed and copy your image pairs into it on the same server as WordPress.
3. Open WooCommerce > WooCommerce Bulk Product Image Import.
4. Click Scan Batch folder. Review filenames, parent SKUs and variation IDs. Uncheck any SKUs you do not want to import.
5. Click Import selected SKUs and keep the page open. Each SKU runs in a separate request. Stop after current SKU pauses between products; a scan can be used to start again.
6. Reload the product editor after import to load the saved variation gallery before editing or saving that product. A product editor tab that was already open does not refresh its image fields automatically.

== Filename and product matching ==

B0001-f.jpg = front image for the variable parent product with SKU B0001.
B0001-b.jpg = back image for that same product.

Both images are required. Names and suffixes are matched without case sensitivity; duplicate front/back files for the same SKU are flagged instead of guessed. JPG, JPEG, PNG, WebP, GIF and AVIF are recognized; WordPress must support the actual image type. Files must be directly inside Batch; subfolders and symbolic links are not followed.

The SKU must be assigned to an existing variable PARENT product. No product or variation is created, and variation SKUs are not used to guess a parent. Every existing variation with its own assigned image is eligible, regardless of size, color, other attributes or variation SKU. No size attribute configuration is needed.

Only parent SKUs that already have at least one featured image or gallery image are eligible. If both fields are empty, the entire SKU and all its variations are skipped without importing media or updating anything, even if Batch contains a complete pair. An image assigned only to a variation does not qualify an otherwise empty parent SKU. The preview explains skipped rows. Eligibility is checked again at import time, so clearing a parent's images after preview also prevents the import.

Variations with either their own thumbnail OR their own variation gallery qualify. This includes the unified "Variation gallery" editor in WooCommerce 11.1+, where a variation can have gallery images without a saved thumbnail. Existing pre-migration Additional Variation Images galleries are also recognized. Variations with no thumbnail and no gallery are left unchanged; an image inherited from the parent does not qualify. Variations are queried directly under their parent with query caching and display filters disabled, instead of relying on WooCommerce's cached child list. An eligible parent still gets its product image and gallery updated when none of its variations have assigned images.

Review the listed variation IDs before importing. If variation image assignments are added or removed after preview, scan again. The old preview is rejected before any writes. The importer also checks each variation immediately before assigning the front image so it cannot fill an image field that has become empty during the import.

Each eligible variation's old thumbnail and gallery are replaced by one front image. The importer follows WooCommerce's unified editor storage: the front is the featured image and extra gallery images are empty, so the editor displays one front photo as Primary. Legacy gallery fallback is marked as disabled so old images cannot reappear. After saving, the importer clears caches and reads back both the thumbnail and gallery state. WordPress metadata fallbacks handle missed object saves. A replacement is counted only after the visible image list resolves to exactly the front photo. Otherwise the row shows an error naming the variation and image IDs. Successful rows include an expandable report of the old and verified new image IDs.

== Image behavior ==

The -f image replaces the parent's product image and becomes the sole image of every variation that already has its own thumbnail or variation gallery. Existing variation gallery images are removed from that variation's gallery but remain in the Media Library. Variations with no assigned images stay empty. The -f image is not added to the parent product gallery.

The -b image REPLACES the entire parent product gallery. After importing, that gallery contains exactly one image: the back photo. All previous gallery images are removed from this product's gallery. The back photo is never assigned as a product image, variation image or variation gallery image. This uses WooCommerce's standard product gallery; gallery visibility depends on your theme's support. This assignment rule is fixed; the previous size filter and image replacement checkbox have been removed.

Sizes, attributes, descriptions, prices and stock settings are preserved. Previous gallery images remain in the Media Library; media files are never deleted. Importing again resets the gallery to just the current back photo, including when its contents have changed.

Batch source images are copied, never moved or deleted. WordPress creates normal Media Library entries and thumbnails. Unchanged filenames and contents reuse the plugin's previously imported attachments, so repeated imports do not create duplicate media or gallery entries. Changed contents create a new attachment; old media remains in the library. If an import fails part way through, correct the problem, scan and retry; completed assignments and attachments can be reused.

Preview records expire after one hour and belong to the current user. Files and matched variation IDs are checked again before import. If either changes, scan again. Only users who can manage WooCommerce, edit the affected products and upload files can import. AJAX actions require a valid WordPress nonce. The Batch path is fixed inside this plugin and cannot be supplied through a request.

Deactivating or deleting the plugin does not undo image assignments or delete imported Media Library items. Save a copy of Batch before deleting or replacing the plugin folder, because WordPress removes files inside a deleted plugin.

== Building an installation ZIP ==

From the repository root in PowerShell, run `Compress-Archive -Path .\sku-image-importer -DestinationPath .\sku-image-importer.zip`. Use a source folder containing only the files you want to distribute. The ZIP should contain sku-image-importer/ as its top-level folder. The repository's README-GIT.md also explains how to package the committed plugin files with Git.
