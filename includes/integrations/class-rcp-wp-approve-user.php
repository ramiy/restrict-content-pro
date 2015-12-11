<?php

class RCP_WP_Approve_User {
	
	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function __construct() {

		if( ! class_exists( 'Obenland_Wp_Approve_User' ) ) {
			return;
		}

		$this->init();
	}

	/**
	 * Add actions and filters
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function init() {

		add_filter( 'option_users_can_register', array( $this, 'users_can_register' ) );
		add_filter( 'rcp_member_can_access', array( $this, 'can_access' ), 10, 4 );
		add_filter( 'rcp_restricted_message', array( $this, 'pending_message' ), 9999 );

		add_action( 'signup_header', array( $this, 'redirect_wp_signup' ) );
		add_action( 'rcp_member_row_actions', array( $this, 'member_row_actions' ) );
		add_action( 'admin_init', array( $this, 'process_approve' ) );
		add_action( 'admin_init', array( $this, 'process_unapprove' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

	}

	/**
	 * Determine if member is pending
	 *
	 * @access  public
	 * @since   2.4
	 */
	private function is_pending( $user_id = 0 ) {
		return (bool) is_user_logged_in() && ! get_user_meta( $user_id, 'wp-approve-user', true ) && ! user_can( $user_id, 'edit_pages' );
	}

	/**
	 * Force can register option on
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function users_can_register() {

		return true;

	}

	/**
	 * Prevent users from accessing the default registration screen
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function redirect_wp_signup() {
		global $rcp_options;

		$redirect = isset( $rcp_options['registration_page'] ) ? get_permalink( $rcp_options['registration_page'] ) : home_url();

		wp_redirect( $redirect ); exit;
	}

	/**
	 * Block pending members from seeing content
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function can_access( $can_access, $member_id, $post_id, $member ) {

		if( $can_access && $this->is_pending( $member_id ) ) {
			$can_access = false;
		}

		return $can_access;

	}

	/**
	 * Display pending verification message when trying to access restricted content
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function pending_message( $message ) {
		
		global $rcp_load_css;

		$rcp_load_css = true;

		if( $this->is_pending( get_current_user_id() ) ) {
			$message = '<div class="rcp_message error"><p class="rcp_error rcp_pending_member"><span>' . __( 'Your account is pending verification by a site administrator.', 'rcp' ) . '</span></p></div>';
		}

		return $message;
	}

	/**
	 * Display Approve | Unapprove links on member rows
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function member_row_actions( $member_id ) {

		$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url     = 'admin.php?page=rcp-members';

		if ( ! $this->is_pending( $member_id ) ) {

			$url = wp_nonce_url( add_query_arg( array(
				'action' => 'rcp_wpau_unapprove',
				'user'   => $member_id
			), $url ), 'wpau-unapprove-user' );

			printf( ' | <a class="submitunapprove" href="%1$s">%2$s</a>', esc_url( $url ), __( 'Unapprove', 'rcp' ) );

		} else {

			$url = wp_nonce_url( add_query_arg( array(
				'action' => 'rcp_wpau_approve',
				'user'   => $member_id
			), $url ), 'wpau-approve-user' );

			printf( ' | <a class="submitapprove" href="%1$s">%2$s</a>', esc_url( $url ), __( 'Approve', 'rcp' ) );
		}

	}

	/**
	 * Approve a user
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function process_approve() {

		if( empty( $_REQUEST['action'] ) || 'rcp_wpau_approve' !== $_REQUEST['action'] ) {
			return;
		}

		check_admin_referer( 'wpau-approve-user' );

		if( ! current_user_can( 'edit_user', $_REQUEST['user'] ) ) {
			wp_die( __( 'You do not have permission to edit this user', 'rcp' ), __( 'Error', 'rcp' ), array( 'response' => 403 ) );
		}

		update_user_meta( $_REQUEST['user'], 'wp-approve-user', true );
		do_action( 'wpau_approve', $_REQUEST['user'] );

		wp_redirect( add_query_arg( array(
			'action' => 'rcp_wpau_update',
			'update' => 'wpau-approved',
			'count'  => 1
		), admin_url( 'admin.php?page=rcp-members' ) ) );
		exit;

	}

	/**
	 * Unapprove a user
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function process_unapprove() {

		if( empty( $_REQUEST['action'] ) || 'rcp_wpau_unapprove' !== $_REQUEST['action'] ) {
			return;
		}

		check_admin_referer( 'wpau-unapprove-user' );

		if( ! current_user_can( 'edit_user', $_REQUEST['user'] ) ) {
			wp_die( __( 'You do not have permission to edit this user', 'rcp' ), __( 'Error', 'rcp' ), array( 'response' => 403 ) );
		}

		update_user_meta( $_REQUEST['user'], 'wp-approve-user', false );
		do_action( 'wpau_unapprove', $_REQUEST['user'] );

		wp_redirect( add_query_arg( array(
			'action' => 'rcp_wpau_update',
			'update' => 'wpau-unapproved',
			'count'  => 1
		), admin_url( 'admin.php?page=rcp-members' ) ) );
		exit;

	}


	/**
	 * Show admin notices
	 *
	 * @access  public
	 * @since   2.4
	 */
	public function admin_notices() {

		if( empty( $_REQUEST['action'] ) || 'rcp_wpau_update' !== $_REQUEST['action'] ) {
			return;
		}

		if( empty( $_REQUEST['update'] ) ) {
			return;
		}

		if( 'wpau-unapproved' == $_REQUEST['update'] ) {
			$text = __( 'Member unapproved', 'rcp' );
		} else {
			$text = __( 'Member approved', 'rcp' );
		}

		echo '<div class="updated"><p>' . $text . '</p></div>';

	}

}
new RCP_WP_Approve_User;