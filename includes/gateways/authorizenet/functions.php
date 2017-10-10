<?php
/**
 * Authorize.net Functions
 *
 * @package     Restrict Content Pro
 * @subpackage  Gateways/Authorize.net/Functions
 * @copyright   Copyright (c) 2017, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.7
 */

/**
 * Cancel an Authorize.net subscriber
 *
 * @param int $member_id ID of the member to cancel.
 *
 * @access      private
 * @since       2.7
 * @return      bool|WP_Error
 */
function rcp_authnet_cancel_member( $member_id = 0 ) {

	global $rcp_options;

	$ret             = true;
	if ( rcp_is_sandbox() ) {
		$api_login_id    = isset( $rcp_options['authorize_test_api_login'] ) ? sanitize_text_field( $rcp_options['authorize_test_api_login'] ) : '';
		$transaction_key = isset( $rcp_options['authorize_test_txn_key'] ) ? sanitize_text_field( $rcp_options['authorize_test_txn_key'] ) : '';
	} else {
		$api_login_id    = isset( $rcp_options['authorize_api_login'] ) ? sanitize_text_field( $rcp_options['authorize_api_login'] ) : '';
		$transaction_key = isset( $rcp_options['authorize_txn_key'] ) ? sanitize_text_field( $rcp_options['authorize_txn_key'] ) : '';
	}
	$md5_hash_value  = isset( $rcp_options['authorize_hash_value'] ) ? sanitize_text_field( $rcp_options['authorize_hash_value'] ) : '';

	require_once RCP_PLUGIN_DIR . 'includes/libraries/anet_php_sdk/autoload.php';

	$member     = new RCP_Member( $member_id );
	$profile_id = str_replace( 'anet_', '', $member->get_payment_profile_id() );

	/**
	 * Create a merchantAuthenticationType object with authentication details.
	 */
	$merchant_authentication = new net\authorize\api\contract\v1\MerchantAuthenticationType();
	$merchant_authentication->setName( $api_login_id );
	$merchant_authentication->setTransactionKey( $transaction_key );

	/**
	 * Set the transaction's refId
	 */
	$refId = 'ref' . time();

	$request = new net\authorize\api\contract\v1\ARBCancelSubscriptionRequest();
	$request->setMerchantAuthentication( $merchant_authentication );
	$request->setRefId( $refId );
	$request->setSubscriptionId( $profile_id );

	/**
	 * Submit the request
	 */
	$controller  = new net\authorize\api\controller\ARBCancelSubscriptionController( $request );
	$environment = rcp_is_sandbox() ? \net\authorize\api\constants\ANetEnvironment::SANDBOX : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;
	$response    = $controller->executeWithApiResponse( $environment );

	/**
	 * An error occurred - get the error message.
	 */
	if( $response == null || $response->getMessages()->getResultCode() != "Ok" ) {

		$error_messages = $response->getMessages()->getMessage();
		$error          = $error_messages[0]->getCode() . "  " .$error_messages[0]->getText();
		$ret            = new WP_Error( 'rcp_authnet_error', $error );

	}

	return $ret;
}


/**
 * Determine if a member is an Authorize.net Customer
 *
 * @param int $user_id The ID of the user to check
 *
 * @since       2.7
 * @access      public
 * @return      bool
*/
function rcp_is_authnet_subscriber( $user_id = 0 ) {

	if( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$ret = false;

	$member = new RCP_Member( $user_id );

	$profile_id = $member->get_payment_profile_id();

	// Check if the member is an Authorize.net customer
	if( false !== strpos( $profile_id, 'anet_' ) ) {

		$ret = true;

	}

	return (bool) apply_filters( 'rcp_is_authorizenet_subscriber', $ret, $user_id );
}

/**
 * Determine if all necessary API credentials are filled in
 *
 * @since  2.7
 * @return bool
 */
function rcp_has_authnet_api_access() {

	global $rcp_options;

	$ret = false;

	if ( rcp_is_sandbox() ) {
		$api_login_id    = $rcp_options['authorize_test_api_login'];
		$transaction_key = $rcp_options['authorize_test_txn_key'];
	} else {
		$api_login_id    = $rcp_options['authorize_api_login'];
		$transaction_key = $rcp_options['authorize_txn_key'];
	}

	if ( ! empty( $api_login_id ) && ! empty( $transaction_key ) ) {
		$ret = true;
	}

	return $ret;

}

/**
 * Process an update card form request for Authorize.net
 *
 * @param int        $member_id  ID of the member.
 * @param RCP_Member $member_obj Member object.
 *
 * @access      private
 * @since       2.7
 * @return      void
 */
function rcp_authorizenet_update_billing_card( $member_id = 0, $member_obj ) {

	global $rcp_options;

	if( empty( $member_id ) ) {
		return;
	}

	if( ! is_a( $member_obj, 'RCP_Member' ) ) {
		return;
	}

	if( ! rcp_is_authnet_subscriber( $member_id ) ) {
		return;
	}

	require_once RCP_PLUGIN_DIR . 'includes/libraries/anet_php_sdk/autoload.php';

	if ( rcp_is_sandbox() ) {
		$api_login_id    = isset( $rcp_options['authorize_test_api_login'] ) ? sanitize_text_field( $rcp_options['authorize_test_api_login'] ) : '';
		$transaction_key = isset( $rcp_options['authorize_test_txn_key'] ) ? sanitize_text_field( $rcp_options['authorize_test_txn_key'] ) : '';
	} else {
		$api_login_id    = isset( $rcp_options['authorize_api_login'] ) ? sanitize_text_field( $rcp_options['authorize_api_login'] ) : '';
		$transaction_key = isset( $rcp_options['authorize_txn_key'] ) ? sanitize_text_field( $rcp_options['authorize_txn_key'] ) : '';
	}
	$md5_hash_value  = isset( $rcp_options['authorize_hash_value'] ) ? sanitize_text_field( $rcp_options['authorize_hash_value'] ) : '';

	$error          = '';
	$card_number    = isset( $_POST['rcp_card_number'] )    && is_numeric( $_POST['rcp_card_number'] )    ? sanitize_text_field( $_POST['rcp_card_number'] )    : '';
	$card_exp_month = isset( $_POST['rcp_card_exp_month'] ) && is_numeric( $_POST['rcp_card_exp_month'] ) ? sanitize_text_field( $_POST['rcp_card_exp_month'] ) : '';
	$card_exp_year  = isset( $_POST['rcp_card_exp_year'] )  && is_numeric( $_POST['rcp_card_exp_year'] )  ? sanitize_text_field( $_POST['rcp_card_exp_year'] )  : '';
	$card_cvc       = isset( $_POST['rcp_card_cvc'] )       && is_numeric( $_POST['rcp_card_cvc'] )       ? sanitize_text_field( $_POST['rcp_card_cvc'] )       : '';
	$card_zip       = isset( $_POST['rcp_card_zip'] ) ? sanitize_text_field( $_POST['rcp_card_zip'] ) : '' ;

	if ( empty( $card_number ) || empty( $card_exp_month ) || empty( $card_exp_year ) || empty( $card_cvc ) || empty( $card_zip ) ) {
		$error = __( 'Please enter all required fields.', 'rcp' );
	}

	if ( empty( $error ) ) {

		$member     = new RCP_Member( $member_id );
		$profile_id = str_replace( 'anet_', '', $member->get_payment_profile_id() );

		/**
		 * Create a merchantAuthenticationType object with authentication details.
		 */
		$merchant_authentication = new net\authorize\api\contract\v1\MerchantAuthenticationType();
		$merchant_authentication->setName( $api_login_id );
		$merchant_authentication->setTransactionKey( $transaction_key );

		/**
		 * Set the transaction's refId
		 */
		$refId = 'ref' . time();

		$subscription = new net\authorize\api\contract\v1\ARBSubscriptionType();

		/**
		 * Update card details.
		 */
		$credit_card = new net\authorize\api\contract\v1\CreditCardType();
		$credit_card->setCardNumber( $card_number );
		$credit_card->setExpirationDate( $card_exp_year . '-' . $card_exp_month );
		$credit_card->setCardCode( $card_cvc );

		$payment = new net\authorize\api\contract\v1\PaymentType();
		$payment->setCreditCard( $credit_card );

		$subscription->setPayment( $payment );

		/**
		 * Update the billing zip.
		 */
		$bill_to = new net\authorize\api\contract\v1\NameAndAddressType();
		$bill_to->setZip( $card_zip );
		$subscription->setBillTo( $bill_to );

		/**
		 * Make request to update details.
		 */
		$request = new net\authorize\api\contract\v1\ARBUpdateSubscriptionRequest();
		$request->setMerchantAuthentication( $merchant_authentication );
		$request->setRefId( $refId );
		$request->setSubscriptionId( $profile_id );
		$request->setSubscription( $subscription );

		$controller  = new net\authorize\api\controller\ARBCancelSubscriptionController( $request );
		$environment = rcp_is_sandbox() ? \net\authorize\api\constants\ANetEnvironment::SANDBOX : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;
		$response    = $controller->executeWithApiResponse( $environment );

		/**
		 * An error occurred - get the error message.
		 */
		if( $response == null || $response->getMessages()->getResultCode() != "Ok" ) {
			$error_messages = $response->getMessages()->getMessage();
			$error          = $error_messages[0]->getCode() . "  " .$error_messages[0]->getText();
		}

	}

	if( ! empty( $error ) ) {

		wp_redirect( add_query_arg( array( 'card' => 'not-updated', 'msg' => urlencode( $error ) ) ) ); exit;

	}

	wp_redirect( add_query_arg( array( 'card' => 'updated', 'msg' => '' ) ) ); exit;

}
add_action( 'rcp_update_billing_card', 'rcp_authorizenet_update_billing_card', 10, 2 );