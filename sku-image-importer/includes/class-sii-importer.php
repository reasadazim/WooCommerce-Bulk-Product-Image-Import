<?php
defined( 'ABSPATH' ) || exit;

class SII_Importer {
	private $batch;

	public function __construct( SII_Batch $batch ) {
		$this->batch = $batch;
	}

	public function batch() {
		return $this->batch;
	}

	/** Read the saved thumbnail, without WooCommerce image filters or inheritance. */
	private function assigned_image_id( $variation_id ) {
		wp_cache_delete( $variation_id, 'post_meta' );
		return (int) get_post_meta( $variation_id, '_thumbnail_id', true );
	}

	private function image_ids( $value ) {
		$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
			return $id > 0;
		} ) ) );
	}

	/** The classic variation gallery can contain images even when its thumbnail is empty. */
	private function variation_images( $variation_id ) {
		$featured = $this->assigned_image_id( $variation_id );
		$gallery  = $this->image_ids( get_post_meta( $variation_id, '_product_image_gallery', true ) );
		$legacy   = array();
		if ( ! metadata_exists( 'post', $variation_id, '_wc_variation_gallery_legacy_fallback_disabled' ) ) {
			$legacy = $this->image_ids( get_post_meta( $variation_id, '_wc_additional_variation_images', true ) );
		}
		$display = $gallery ? $gallery : $legacy;
		if ( $featured > 0 && ! in_array( $featured, $display, true ) ) {
			array_unshift( $display, $featured );
		}
		return array( 'featured' => $featured, 'gallery' => $gallery, 'legacy' => $legacy, 'display' => $display );
	}

	private function clear_variation_cache( $variation_id ) {
		clean_post_cache( $variation_id );
		wc_delete_product_transients( $variation_id );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $variation_id );
		}
		$product_cache_class = '\\Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
		if ( class_exists( $product_cache_class ) && function_exists( 'wc_get_container' ) ) {
			wc_get_container()->get( $product_cache_class )->remove( $variation_id );
		}
	}

	/** A successful object save alone does not prove that the thumbnail was saved. */
	private function replace_variation_image( $variation, $front ) {
		$id      = $variation->get_id();
		$before  = $this->variation_images( $id );
		if ( ! $before['display'] ) {
			return array( 'id' => $id, 'previous_image_id' => 0, 'image_id' => 0, 'status' => 'skipped_empty' );
		}
		$current = $before['display'][0];
		$changed = $before['featured'] !== $front || $before['gallery'] || $before['legacy'];
		if ( $changed ) {
			$variation->set_image_id( $front );
			// Match WooCommerce's own unified gallery save: first image is featured, the rest are extras.
			// The requested gallery contains only the front, so there are no extra variation images.
			$variation->set_gallery_image_ids( array() );
			$compatibility = '\\Automattic\\WooCommerce\\Internal\\VariationGallery\\LegacyVariationGalleryCompatibility';
			if ( is_callable( array( $compatibility, 'mark_core_managed' ) ) ) {
				$compatibility::mark_core_managed( $variation );
			}
			$variation->save();
			if ( $this->assigned_image_id( $id ) !== $front ) {
				// Persist the standard WooCommerce variation image field if its object save missed it.
				update_post_meta( $id, '_thumbnail_id', $front );
			}
			if ( $this->image_ids( get_post_meta( $id, '_product_image_gallery', true ) ) ) {
				update_post_meta( $id, '_product_image_gallery', '' );
			}
			if ( $before['legacy'] ) {
				// Prevent WooCommerce's compatibility reader from restoring the old gallery.
				update_post_meta( $id, '_wc_variation_gallery_legacy_fallback_disabled', 'yes' );
			}
		}
		$this->clear_variation_cache( $id );
		$saved = $this->variation_images( $id );
		if ( $saved['featured'] !== $front || $saved['gallery'] || $saved['legacy'] || $saved['display'] !== array( $front ) ) {
			return new WP_Error( 'variation_image_not_saved', 'Variation #' . $id . ': image/gallery replacement could not be verified. Expected only image #' . $front . ', found primary #' . $saved['featured'] . ' and gallery [' . implode( ',', $saved['display'] ) . ']. Scan and retry; completed replacements can be reused.' );
		}
		return array( 'id' => $id, 'previous_image_id' => $current, 'previous_image_ids' => $before['display'], 'image_id' => $saved['featured'], 'image_ids' => $saved['display'], 'status' => $changed ? 'updated' : 'already_set' );
	}

	/** Resolve the parent SKU and existing variations without writing anything. */
	public function inspect( $group ) {
		if ( ! empty( $group['issues'] ) ) {
			return new WP_Error( 'duplicate_files', implode( ' ', $group['issues'] ) );
		}
		$fingerprint = $this->batch->fingerprint( $group );
		if ( is_wp_error( $fingerprint ) ) {
			return $fingerprint;
		}
		$product_id = wc_get_product_id_by_sku( $group['sku'] );
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return new WP_Error( 'sku_not_found', 'No product found with this parent SKU.' );
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'not_variable', 'This SKU must belong to a variable parent product.' );
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return new WP_Error( 'cannot_edit', 'You do not have permission to edit this product.' );
		}
		$existing_gallery = array_filter( $product->get_gallery_image_ids( 'edit' ), static function ( $image_id ) {
			return (int) $image_id > 0;
		} );
		if ( (int) $product->get_image_id( 'edit' ) < 1 && ! $existing_gallery ) {
			return new WP_Error( 'no_existing_images', 'Skipped: this parent SKU has no existing featured or gallery images. Products with no images are left unchanged.' );
		}
		$targets       = array();
		$skipped_empty = 0;
		// Query real child posts, bypassing WooCommerce's cached/filtered child list.
		$variation_ids = get_posts( array(
			'post_type' => 'product_variation', 'post_parent' => $product_id,
			'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
			'suppress_filters' => true, 'cache_results' => false,
			'update_post_meta_cache' => false, 'update_post_term_cache' => false,
		) );
		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id( 'edit' ) !== (int) $product_id ) {
				return new WP_Error( 'invalid_variation', 'Could not load variation #' . $variation_id . ' for this parent. Scan again.' );
			}
			if ( ! $this->variation_images( $variation_id )['display'] ) {
				++$skipped_empty;
				continue;
			}
			if ( ! current_user_can( 'edit_post', $variation_id ) ) {
				return new WP_Error( 'cannot_edit_variation', 'You do not have permission to edit a matching variation.' );
			}
			$targets[] = array( 'id' => $variation_id );
		}
		return array(
			'product_id'  => $product_id,
			'targets'     => $targets,
			'skipped_empty' => $skipped_empty,
			'fingerprint' => $fingerprint,
		);
	}

	private function media( $name, $product_id ) {
		$path = $this->batch->path( $name );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$type = wp_check_filetype_and_ext( $path, $name );
		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) || ! wp_getimagesize( $path ) ) {
			return new WP_Error( 'invalid_image', 'Not a valid supported image: ' . $name );
		}
		$key = hash( 'sha256', strtolower( $name ) . '|' . hash_file( 'sha256', $path ) );
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1,
			'fields' => 'ids', 'meta_key' => '_sii_source_key', 'meta_value' => $key,
		) );
		if ( $ids && wp_attachment_is_image( $ids[0] ) && wp_get_attachment_url( $ids[0] ) ) {
			return (int) $ids[0];
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$temp = wp_tempnam( $name );
		if ( ! $temp ) {
			return new WP_Error( 'temp_file', 'Could not create a temporary image file.' );
		}
		if ( ! @copy( $path, $temp ) ) {
			wp_delete_file( $temp );
			return new WP_Error( 'copy_failed', 'Could not copy the Batch image for import.' );
		}
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $temp ), $product_id );
		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_sii_source_key', $key );
		update_post_meta( $id, '_wp_attachment_image_alt', pathinfo( $name, PATHINFO_FILENAME ) );
		return (int) $id;
	}

	/** One SKU per request; the preview binds both files and variation IDs. */
	public function import( $group, $expected ) {
		$plan = $this->inspect( $group );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( $plan !== $expected ) {
			return new WP_Error( 'preview_changed', 'Files or matching variations changed after preview. Scan again.' );
		}
		$lock = 'sii_import_lock_' . $plan['product_id'];
		$existing = get_option( $lock );
		if ( is_numeric( $existing ) && (int) $existing < time() - 600 ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return new WP_Error( 'import_busy', 'Another import is processing this product. Try again when it finishes.' );
		}
		try {
			$front = $this->media( $group['files']['f'], $plan['product_id'] );
			if ( is_wp_error( $front ) ) {
				return $front;
			}
			$back = $this->media( $group['files']['b'], $plan['product_id'] );
			if ( is_wp_error( $back ) ) {
				return $back;
			}
			$product = wc_get_product( $plan['product_id'] );
			$product->set_image_id( $front );
			$product->set_gallery_image_ids( array( $back ) );
			$product->update_meta_data( '_sii_back_image_id', $back );
			$product->save();
			$updated = 0;
			$skipped = $plan['skipped_empty'];
			$verified = array();
			foreach ( $plan['targets'] as $target ) {
				$variation = wc_get_product( $target['id'] );
				if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id( 'edit' ) !== (int) $plan['product_id'] ) {
					return new WP_Error( 'invalid_variation', 'Variation #' . $target['id'] . ' is no longer available under this parent. Scan again.' );
				}
				$replacement = $this->replace_variation_image( $variation, $front );
				if ( is_wp_error( $replacement ) ) {
					return $replacement;
				}
				if ( 'skipped_empty' === $replacement['status'] ) {
					++$skipped;
					continue;
				}
				if ( 'updated' === $replacement['status'] ) {
					++$updated;
				}
				$verified[] = $replacement;
			}
			return array( 'updated' => $updated, 'skipped' => $skipped, 'front_id' => $front, 'back_id' => $back, 'variations' => $verified );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'import_failed', 'Import did not finish: ' . $exception->getMessage() . ' Scan and retry; completed image assignments can be reused.' );
		} finally {
			wc_delete_product_transients( $plan['product_id'] );
			delete_option( $lock );
		}
	}
}
