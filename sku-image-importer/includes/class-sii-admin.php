<?php
defined( 'ABSPATH' ) || exit;

class SII_Admin {
	private $importer;

	public function __construct( SII_Importer $importer ) {
		$this->importer = $importer;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_sii_scan', array( $this, 'scan' ) );
		add_action( 'wp_ajax_sii_import', array( $this, 'import' ) );
	}

	public function menu() {
		add_submenu_page( 'woocommerce', 'WooCommerce Bulk Product Image Import', 'WooCommerce Bulk Product Image Import', 'manage_woocommerce', 'sku-image-importer', array( $this, 'page' ) );
	}

	public function assets( $hook ) {
		if ( 'woocommerce_page_sku-image-importer' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'sii-admin', SII_URL . 'assets/admin.css', array(), SII_VERSION );
		wp_enqueue_script( 'sii-admin', SII_URL . 'assets/admin.js', array(), SII_VERSION, true );
		wp_localize_script( 'sii-admin', 'siiAdmin', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sii_import' ) ) );
	}

	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap sii-wrap" id="sii-app">
			<h1>WooCommerce Bulk Product Image Import</h1>
			<p>Replace the product image and variation images with the front photo; replace the entire product gallery with the back photo.</p>
			<div class="sii-card">
				<h2>1. Check your Batch folder</h2>
				<p>Put image pairs such as <code>B0001-f.jpg</code> and <code>B0001-b.jpg</code> in this folder:</p>
				<p><code><?php echo esc_html( $this->importer->batch()->directory() ); ?></code></p>
				<p><code>B0001</code> must be the variable parent product's SKU. The <code>-f</code> photo replaces its product image and becomes the only image in each variation that already has a thumbnail or variation gallery, regardless of size or other attributes. This includes the newer WooCommerce “Variation gallery” editor. Variations with no thumbnail or gallery are left unchanged. The <code>-b</code> photo becomes the only image in the product gallery, replacing all previous gallery images.</p>
				<p><strong>Only parent SKUs that already have a featured image or gallery image are eligible.</strong> Products with no images are skipped and left unchanged. This rule is checked again before importing.</p>
				<p>No products or variations are created. Prices, stock and other product details are preserved. Previous gallery images remain in the Media Library.</p>
				<button class="button button-secondary" id="sii-scan" type="button">Scan Batch folder</button>
			</div>
			<div class="sii-card" id="sii-preview" hidden>
				<h2>2. Review and import</h2>
				<p id="sii-summary"></p>
				<p>Only checked SKUs are imported. The listed variations already have a thumbnail or images in their own gallery. Their old gallery images are replaced by the front photo only. A parent with no eligible variations still gets its product image and gallery updated. An image inherited from the parent does not count as a variation's own image.</p>
				<div class="sii-actions">
					<button class="button button-primary" id="sii-import" type="button" disabled>Import selected SKUs</button>
					<button class="button" id="sii-stop" type="button" hidden>Stop after current SKU</button>
				</div>
				<progress id="sii-progress" value="0" max="1" hidden aria-label="Import progress"></progress>
				<div class="sii-table-scroll">
					<table class="widefat striped sii-table">
						<thead><tr><td class="check-column"><input id="sii-select-all" type="checkbox" checked aria-label="Select all ready SKUs"></td><th scope="col">Parent SKU</th><th scope="col">Front / back files</th><th scope="col">Variations with assigned images</th><th scope="col">Status</th></tr></thead>
						<tbody id="sii-rows"></tbody>
					</table>
				</div>
				<details id="sii-ignored" hidden><summary>Ignored filenames</summary><p id="sii-ignored-names"></p><p>Use the exact suffix <code>-f</code> or <code>-b</code> before the extension. Supported formats: JPG, JPEG, PNG, WebP, GIF and AVIF (subject to your WordPress server's support).</p></details>
			</div>
			<p id="sii-status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	private function authorize() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => 'You need permission to manage WooCommerce, edit products and upload files.' ), 403 );
		}
		if ( ! check_ajax_referer( 'sii_import', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Your session expired. Reload this page and scan again.' ), 403 );
		}
	}

	private function preview_key( $token ) {
		return 'sii_preview_' . get_current_user_id() . '_' . $token;
	}

	public function scan() {
		$this->authorize();
		$batch     = $this->importer->batch()->scan();
		if ( is_wp_error( $batch ) ) {
			wp_send_json_error( array( 'message' => $batch->get_error_message() ), 400 );
		}
		$rows    = array();
		$records = array();
		foreach ( $batch['groups'] as $key => $group ) {
			$plan = $this->importer->inspect( $group );
			$row  = array( 'key' => (string) $key, 'sku' => $group['sku'], 'files' => $group['files'], 'ready' => ! is_wp_error( $plan ) );
			if ( is_wp_error( $plan ) ) {
				$row['message'] = $plan->get_error_message();
				$row['targets'] = array();
			} else {
				$row['targets'] = $plan['targets'];
				$row['message'] = 'Ready. ' . count( $plan['targets'] ) . ' variations with assigned images; ' . $plan['skipped_empty'] . ' without images left unchanged.';
				$records[ $key ] = array( 'group' => $group, 'plan' => $plan );
			}
			$rows[] = $row;
		}
		$token = wp_generate_uuid4();
		if ( ! set_transient( $this->preview_key( $token ), array( 'records' => $records ), HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => 'Could not save the preview. Check your WordPress database and scan again.' ), 500 );
		}
		wp_send_json_success( array( 'token' => $token, 'rows' => $rows, 'ignored' => $batch['ignored'], 'ready' => count( $records ) ) );
	}

	public function import() {
		$this->authorize();
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		$key   = isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $token ) ) {
			wp_send_json_error( array( 'message' => 'Invalid preview. Scan again.' ), 400 );
		}
		$preview = get_transient( $this->preview_key( $token ) );
		if ( ! $preview || ! isset( $preview['records'][ $key ] ) ) {
			wp_send_json_error( array( 'message' => 'Preview expired or this SKU was not ready. Scan again.' ), 400 );
		}
		$record = $preview['records'][ $key ];
		$result = $this->importer->import( $record['group'], $record['plan'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}
}
