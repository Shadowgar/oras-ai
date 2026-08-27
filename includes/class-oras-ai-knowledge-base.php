<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Knowledge_Base {

	const POST_TYPE = 'oras_ai_knowledge';
	const TAXONOMY  = 'oras_ai_category';

	public function __construct() {
		add_action( 'init', array( $this, 'register_content_types' ) );
		add_action( 'init', array( __CLASS__, 'seed_default_categories' ), 20 );

		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'replace_native_submit_box' ), 99 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_entry' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );

		add_action( 'restrict_manage_posts', array( $this, 'add_filters' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_filters' ) );
	}

	public static function default_categories() {
		return array(
			'Membership',
			'Observatory Access',
			'Observer Passes',
			'Telescopes & Equipment',
			'Events',
			'AstroBlast',
			'Public Nights',
			'Facilities',
			'Volunteering',
			'Policies & Rules',
			'Website / Technical Help',
			'Payments / Treasurer',
			'Board & Organization',
			'Directions / Parking / Accessibility',
			'Contacts & Question Routing',
			'General FAQ',
		);
	}

	public static function seed_default_categories() {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		foreach ( self::default_categories() as $category_name ) {
			if ( ! term_exists( $category_name, self::TAXONOMY ) ) {
				wp_insert_term( $category_name, self::TAXONOMY );
			}
		}
	}

	public function register_content_types() {
		$labels = array(
			'name'               => __( 'Knowledge Base', 'oras-ai-assistant' ),
			'singular_name'      => __( 'Knowledge Entry', 'oras-ai-assistant' ),
			'menu_name'          => __( 'Knowledge Base', 'oras-ai-assistant' ),
			'add_new'            => __( 'Add Entry', 'oras-ai-assistant' ),
			'add_new_item'       => __( 'Add Knowledge Entry', 'oras-ai-assistant' ),
			'edit_item'          => __( 'Edit Knowledge Entry', 'oras-ai-assistant' ),
			'new_item'           => __( 'New Knowledge Entry', 'oras-ai-assistant' ),
			'view_item'          => __( 'View Knowledge Entry', 'oras-ai-assistant' ),
			'search_items'       => __( 'Search Knowledge Base', 'oras-ai-assistant' ),
			'not_found'          => __( 'No knowledge entries found.', 'oras-ai-assistant' ),
			'not_found_in_trash' => __( 'No knowledge entries found in Trash.', 'oras-ai-assistant' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'oras-ai-assistant',
				'show_in_rest'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'revisions', 'author' ),
				'menu_icon'           => 'dashicons-welcome-learn-more',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Knowledge Categories', 'oras-ai-assistant' ),
					'singular_name' => __( 'Knowledge Category', 'oras-ai-assistant' ),
					'menu_name'     => __( 'Categories', 'oras-ai-assistant' ),
					'add_new_item'  => __( 'Add Knowledge Category', 'oras-ai-assistant' ),
					'edit_item'     => __( 'Edit Knowledge Category', 'oras-ai-assistant' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'meta_box_cb'       => false,
			)
		);
	}

	public function disable_block_editor( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	public function replace_native_submit_box() {
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );

		add_meta_box(
			'oras_ai_save_entry',
			__( 'Save Entry', 'oras-ai-assistant' ),
			array( $this, 'render_save_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_save_meta_box( $post ) {
		?>
		<div class="oras-ai-save-box">
			<p><?php esc_html_e( 'This saves an internal ORAS AI knowledge record. It does not create a public webpage.', 'oras-ai-assistant' ); ?></p>
			<?php
			submit_button(
				$post->post_status === 'auto-draft' ? __( 'Save Entry', 'oras-ai-assistant' ) : __( 'Update Entry', 'oras-ai-assistant' ),
				'primary large',
				'publish',
				false
			);
			?>
		</div>
		<?php
	}

	public function register_meta_boxes() {
		add_meta_box(
			'oras_ai_entry_form',
			__( 'Knowledge Entry', 'oras-ai-assistant' ),
			array( $this, 'render_entry_form' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'oras_ai_entry_help',
			__( 'Entry Guidance', 'oras-ai-assistant' ),
			array( $this, 'render_help_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public function render_entry_form( $post ) {
		wp_nonce_field( 'oras_ai_save_entry', 'oras_ai_entry_nonce' );

		$answer              = get_post_meta( $post->ID, '_oras_ai_official_answer', true );
		$visibility          = get_post_meta( $post->ID, '_oras_ai_visibility', true ) ?: 'members';
		$source              = get_post_meta( $post->ID, '_oras_ai_source', true );
		$source_url          = get_post_meta( $post->ID, '_oras_ai_source_url', true );
		$responsible_group   = get_post_meta( $post->ID, '_oras_ai_responsible_group', true );
		$escalation_contact  = get_post_meta( $post->ID, '_oras_ai_escalation_contact', true );
		$status              = get_post_meta( $post->ID, '_oras_ai_status', true ) ?: 'draft';
		$last_reviewed       = get_post_meta( $post->ID, '_oras_ai_last_reviewed', true );
		$internal_notes      = get_post_meta( $post->ID, '_oras_ai_internal_notes', true );

		$current_terms = wp_get_post_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$current_term  = ! empty( $current_terms ) ? (int) $current_terms[0] : 0;
		?>
		<div class="oras-ai-form-grid">
			<div class="oras-ai-field oras-ai-field-full">
				<label for="oras_ai_official_answer"><strong><?php esc_html_e( 'Official Answer', 'oras-ai-assistant' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Enter the authoritative ORAS information the assistant should rely on. This is not a public web page.', 'oras-ai-assistant' ); ?></p>
				<?php
				wp_editor(
					$answer,
					'oras_ai_official_answer',
					array(
						'textarea_name' => 'oras_ai_official_answer',
						'textarea_rows' => 10,
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => true,
					)
				);
				?>
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_category"><strong><?php esc_html_e( 'Category', 'oras-ai-assistant' ); ?></strong></label>
				<?php
				wp_dropdown_categories(
					array(
						'taxonomy'         => self::TAXONOMY,
						'name'             => 'oras_ai_category',
						'id'               => 'oras_ai_category',
						'show_option_none' => __( '— Select category —', 'oras-ai-assistant' ),
						'option_none_value'=> '0',
						'hide_empty'       => false,
						'hierarchical'     => true,
						'selected'         => $current_term,
					)
				);
				?>
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_visibility"><strong><?php esc_html_e( 'Visibility', 'oras-ai-assistant' ); ?></strong></label>
				<select name="oras_ai_visibility" id="oras_ai_visibility">
					<option value="public" <?php selected( $visibility, 'public' ); ?>><?php esc_html_e( 'Public', 'oras-ai-assistant' ); ?></option>
					<option value="members" <?php selected( $visibility, 'members' ); ?>><?php esc_html_e( 'Members Only', 'oras-ai-assistant' ); ?></option>
					<option value="admin" <?php selected( $visibility, 'admin' ); ?>><?php esc_html_e( 'Admin Only', 'oras-ai-assistant' ); ?></option>
				</select>
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_status"><strong><?php esc_html_e( 'Knowledge Status', 'oras-ai-assistant' ); ?></strong></label>
				<select name="oras_ai_status" id="oras_ai_status">
					<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'oras-ai-assistant' ); ?></option>
					<option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Approved', 'oras-ai-assistant' ); ?></option>
					<option value="review" <?php selected( $status, 'review' ); ?>><?php esc_html_e( 'Needs Review', 'oras-ai-assistant' ); ?></option>
					<option value="retired" <?php selected( $status, 'retired' ); ?>><?php esc_html_e( 'Retired', 'oras-ai-assistant' ); ?></option>
				</select>
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_last_reviewed"><strong><?php esc_html_e( 'Last Reviewed', 'oras-ai-assistant' ); ?></strong></label>
				<input type="date" name="oras_ai_last_reviewed" id="oras_ai_last_reviewed" value="<?php echo esc_attr( $last_reviewed ); ?>">
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_source"><strong><?php esc_html_e( 'Source', 'oras-ai-assistant' ); ?></strong></label>
				<input type="text" name="oras_ai_source" id="oras_ai_source" value="<?php echo esc_attr( $source ); ?>" placeholder="<?php esc_attr_e( 'Example: Observatory Use Policy', 'oras-ai-assistant' ); ?>">
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_source_url"><strong><?php esc_html_e( 'Source URL', 'oras-ai-assistant' ); ?></strong></label>
				<input type="url" name="oras_ai_source_url" id="oras_ai_source_url" value="<?php echo esc_attr( $source_url ); ?>" placeholder="https://oras.org/...">
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_responsible_group"><strong><?php esc_html_e( 'Responsible Group', 'oras-ai-assistant' ); ?></strong></label>
				<input type="text" name="oras_ai_responsible_group" id="oras_ai_responsible_group" value="<?php echo esc_attr( $responsible_group ); ?>" placeholder="<?php esc_attr_e( 'Example: Observatory Committee', 'oras-ai-assistant' ); ?>">
			</div>

			<div class="oras-ai-field">
				<label for="oras_ai_escalation_contact"><strong><?php esc_html_e( 'Escalation Contact', 'oras-ai-assistant' ); ?></strong></label>
				<input type="text" name="oras_ai_escalation_contact" id="oras_ai_escalation_contact" value="<?php echo esc_attr( $escalation_contact ); ?>" placeholder="<?php esc_attr_e( 'Name, role, or email address', 'oras-ai-assistant' ); ?>">
				<p class="description"><?php esc_html_e( 'Used later when the AI cannot answer confidently.', 'oras-ai-assistant' ); ?></p>
			</div>

			<div class="oras-ai-field oras-ai-field-full">
				<label for="oras_ai_internal_notes"><strong><?php esc_html_e( 'Internal Notes', 'oras-ai-assistant' ); ?></strong></label>
				<textarea rows="4" name="oras_ai_internal_notes" id="oras_ai_internal_notes"><?php echo esc_textarea( $internal_notes ); ?></textarea>
			</div>
		</div>
		<?php
	}

	public function render_help_meta_box() {
		?>
		<p><strong><?php esc_html_e( 'Question / Topic', 'oras-ai-assistant' ); ?></strong><br><?php esc_html_e( 'Use the title field at the top for the question or subject.', 'oras-ai-assistant' ); ?></p>
		<p><strong><?php esc_html_e( 'Official Answer', 'oras-ai-assistant' ); ?></strong><br><?php esc_html_e( 'Use factual, approved ORAS information. One entry can answer many differently worded questions.', 'oras-ai-assistant' ); ?></p>
		<p><strong><?php esc_html_e( 'Approved', 'oras-ai-assistant' ); ?></strong><br><?php esc_html_e( 'Only entries marked Approved should later be exposed to the AI as authoritative knowledge.', 'oras-ai-assistant' ); ?></p>
		<?php
	}

	public function save_entry( $post_id ) {
		if (
			! isset( $_POST['oras_ai_entry_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oras_ai_entry_nonce'] ) ), 'oras_ai_save_entry' )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$visibility_allowed = array( 'public', 'members', 'admin' );
		$status_allowed     = array( 'draft', 'approved', 'review', 'retired' );

		$visibility = isset( $_POST['oras_ai_visibility'] ) ? sanitize_key( wp_unslash( $_POST['oras_ai_visibility'] ) ) : 'members';
		$status     = isset( $_POST['oras_ai_status'] ) ? sanitize_key( wp_unslash( $_POST['oras_ai_status'] ) ) : 'draft';

		if ( ! in_array( $visibility, $visibility_allowed, true ) ) {
			$visibility = 'members';
		}

		if ( ! in_array( $status, $status_allowed, true ) ) {
			$status = 'draft';
		}

		update_post_meta( $post_id, '_oras_ai_visibility', $visibility );
		update_post_meta( $post_id, '_oras_ai_status', $status );

		$answer = isset( $_POST['oras_ai_official_answer'] )
			? wp_kses_post( wp_unslash( $_POST['oras_ai_official_answer'] ) )
			: '';
		update_post_meta( $post_id, '_oras_ai_official_answer', $answer );

		$text_fields = array(
			'oras_ai_source'             => '_oras_ai_source',
			'oras_ai_responsible_group'  => '_oras_ai_responsible_group',
			'oras_ai_escalation_contact' => '_oras_ai_escalation_contact',
			'oras_ai_last_reviewed'      => '_oras_ai_last_reviewed',
		);

		foreach ( $text_fields as $request_key => $meta_key ) {
			$value = isset( $_POST[ $request_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $request_key ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		$source_url = isset( $_POST['oras_ai_source_url'] ) ? esc_url_raw( wp_unslash( $_POST['oras_ai_source_url'] ) ) : '';
		update_post_meta( $post_id, '_oras_ai_source_url', $source_url );

		$internal_notes = isset( $_POST['oras_ai_internal_notes'] )
			? sanitize_textarea_field( wp_unslash( $_POST['oras_ai_internal_notes'] ) )
			: '';
		update_post_meta( $post_id, '_oras_ai_internal_notes', $internal_notes );

		$category_id = isset( $_POST['oras_ai_category'] ) ? absint( $_POST['oras_ai_category'] ) : 0;
		if ( $category_id > 0 ) {
			wp_set_post_terms( $post_id, array( $category_id ), self::TAXONOMY, false );
		} else {
			wp_set_post_terms( $post_id, array(), self::TAXONOMY, false );
		}
	}

	public function title_placeholder( $title, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			return __( 'Question / knowledge topic', 'oras-ai-assistant' );
		}

		return $title;
	}

	public function columns( $columns ) {
		return array(
			'cb'                  => $columns['cb'],
			'title'               => __( 'Question / Topic', 'oras-ai-assistant' ),
			'oras_ai_id'          => __( 'KB ID', 'oras-ai-assistant' ),
			'oras_ai_category'    => __( 'Category', 'oras-ai-assistant' ),
			'oras_ai_visibility'  => __( 'Visibility', 'oras-ai-assistant' ),
			'oras_ai_status'      => __( 'Status', 'oras-ai-assistant' ),
			'oras_ai_owner'       => __( 'Responsible Group', 'oras-ai-assistant' ),
			'oras_ai_reviewed'    => __( 'Last Reviewed', 'oras-ai-assistant' ),
			'date'                => $columns['date'],
		);
	}

	public function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'oras_ai_id':
				echo esc_html( 'KB-' . str_pad( (string) $post_id, 5, '0', STR_PAD_LEFT ) );
				break;

			case 'oras_ai_category':
				$terms = get_the_terms( $post_id, self::TAXONOMY );
				if ( $terms && ! is_wp_error( $terms ) ) {
					echo esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
				} else {
					echo '&mdash;';
				}
				break;

			case 'oras_ai_visibility':
				$visibility = get_post_meta( $post_id, '_oras_ai_visibility', true );
				$labels = array(
					'public'  => __( 'Public', 'oras-ai-assistant' ),
					'members' => __( 'Members Only', 'oras-ai-assistant' ),
					'admin'   => __( 'Admin Only', 'oras-ai-assistant' ),
				);
				echo esc_html( isset( $labels[ $visibility ] ) ? $labels[ $visibility ] : '—' );
				break;

			case 'oras_ai_status':
				$status = get_post_meta( $post_id, '_oras_ai_status', true );
				$labels = array(
					'draft'    => __( 'Draft', 'oras-ai-assistant' ),
					'approved' => __( 'Approved', 'oras-ai-assistant' ),
					'review'   => __( 'Needs Review', 'oras-ai-assistant' ),
					'retired'  => __( 'Retired', 'oras-ai-assistant' ),
				);
				echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : '—' );
				break;

			case 'oras_ai_owner':
				echo esc_html( get_post_meta( $post_id, '_oras_ai_responsible_group', true ) ?: '—' );
				break;

			case 'oras_ai_reviewed':
				echo esc_html( get_post_meta( $post_id, '_oras_ai_last_reviewed', true ) ?: '—' );
				break;
		}
	}

	public function add_filters( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		wp_dropdown_categories(
			array(
				'show_option_all' => __( 'All Categories', 'oras-ai-assistant' ),
				'taxonomy'        => self::TAXONOMY,
				'name'            => self::TAXONOMY,
				'orderby'         => 'name',
				'selected'        => isset( $_GET[ self::TAXONOMY ] ) ? (int) $_GET[ self::TAXONOMY ] : 0,
				'hierarchical'    => true,
				'depth'           => 3,
				'show_count'      => false,
				'hide_empty'      => false,
				'value_field'     => 'term_id',
			)
		);

		$current_status = isset( $_GET['oras_ai_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['oras_ai_status_filter'] ) ) : '';
		?>
		<select name="oras_ai_status_filter">
			<option value=""><?php esc_html_e( 'All Knowledge Statuses', 'oras-ai-assistant' ); ?></option>
			<option value="draft" <?php selected( $current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'oras-ai-assistant' ); ?></option>
			<option value="approved" <?php selected( $current_status, 'approved' ); ?>><?php esc_html_e( 'Approved', 'oras-ai-assistant' ); ?></option>
			<option value="review" <?php selected( $current_status, 'review' ); ?>><?php esc_html_e( 'Needs Review', 'oras-ai-assistant' ); ?></option>
			<option value="retired" <?php selected( $current_status, 'retired' ); ?>><?php esc_html_e( 'Retired', 'oras-ai-assistant' ); ?></option>
		</select>
		<?php
	}

	public function apply_filters( $query ) {
		if (
			! is_admin() ||
			! $query->is_main_query() ||
			self::POST_TYPE !== $query->get( 'post_type' )
		) {
			return;
		}

		if ( isset( $_GET[ self::TAXONOMY ] ) && (int) $_GET[ self::TAXONOMY ] > 0 ) {
			$query->set(
				'tax_query',
				array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => (int) $_GET[ self::TAXONOMY ],
					),
				)
			);
		}

		if ( ! empty( $_GET['oras_ai_status_filter'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['oras_ai_status_filter'] ) );
			$allowed = array( 'draft', 'approved', 'review', 'retired' );

			if ( in_array( $status, $allowed, true ) ) {
				$query->set(
					'meta_query',
					array(
						array(
							'key'   => '_oras_ai_status',
							'value' => $status,
						),
					)
				);
			}
		}
	}


	public static function upsert_scanned_entry( $args ) {
		$defaults = array(
			'entry_id'       => 0,
			'source_id'      => 0,
			'title'          => '',
			'content'        => '',
			'category'       => 'General FAQ',
			'visibility'     => 'public',
			'status'         => 'review',
			'source_label'   => '',
			'source_url'     => '',
			'internal_notes' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$entry_id = absint( $args['entry_id'] );

		if ( ! $entry_id && $args['source_id'] ) {
			$existing = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_oras_ai_source_record_id',
					'meta_value'     => absint( $args['source_id'] ),
					'no_found_rows'  => true,
				)
			);

			if ( ! empty( $existing ) ) {
				$entry_id = (int) $existing[0];
			}
		}

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( $args['title'] ),
		);

		if ( $entry_id ) {
			$postarr['ID'] = $entry_id;
			$result = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$entry_id = (int) $result;

		$allowed_visibility = array( 'public', 'members', 'admin' );
		$allowed_status = array( 'draft', 'approved', 'review', 'retired' );

		$visibility = in_array( $args['visibility'], $allowed_visibility, true ) ? $args['visibility'] : 'public';
		$status = in_array( $args['status'], $allowed_status, true ) ? $args['status'] : 'review';

		update_post_meta( $entry_id, '_oras_ai_official_answer', wp_kses_post( $args['content'] ) );
		update_post_meta( $entry_id, '_oras_ai_visibility', $visibility );
		update_post_meta( $entry_id, '_oras_ai_status', $status );
		update_post_meta( $entry_id, '_oras_ai_source', sanitize_text_field( $args['source_label'] ) );
		update_post_meta( $entry_id, '_oras_ai_source_url', esc_url_raw( $args['source_url'] ) );
		update_post_meta( $entry_id, '_oras_ai_internal_notes', sanitize_textarea_field( $args['internal_notes'] ) );
		update_post_meta( $entry_id, '_oras_ai_managed_by_scan', '1' );
		update_post_meta( $entry_id, '_oras_ai_source_record_id', absint( $args['source_id'] ) );

		if ( 'approved' === $status ) {
			update_post_meta( $entry_id, '_oras_ai_last_reviewed', current_time( 'Y-m-d' ) );
		}

		$term = term_exists( $args['category'], self::TAXONOMY );

		if ( ! $term ) {
			$term = wp_insert_term( sanitize_text_field( $args['category'] ), self::TAXONOMY );
		}

		if ( ! is_wp_error( $term ) ) {
			$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
			wp_set_post_terms( $entry_id, array( $term_id ), self::TAXONOMY, false );
		}

		return $entry_id;
	}

}
