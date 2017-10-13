<?php
/**
 * Payment Gateway Authorize.net Class
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Roles
 * @copyright   Copyright (c) 2017, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.7
*/

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class RCP_Payment_Gateway_Authorizenet extends RCP_Payment_Gateway {

	private $md5_hash_value;
	private $api_login_id;
	private $transaction_key;

	/**
	* get things going
	*
	* @since      2.7
	*/
	public function init() {
		global $rcp_options;

		$this->supports[]  = 'one-time';
		$this->supports[]  = 'recurring';
		$this->supports[]  = 'fees';
		$this->supports[]  = 'trial';

		if ( $this->test_mode ) {
			$this->api_login_id    = isset( $rcp_options['authorize_test_api_login'] ) ? sanitize_text_field( $rcp_options['authorize_test_api_login'] ) : '';
			$this->transaction_key = isset( $rcp_options['authorize_test_txn_key'] ) ? sanitize_text_field( $rcp_options['authorize_test_txn_key'] ) : '';
		} else {
			$this->api_login_id    = isset( $rcp_options['authorize_api_login'] ) ? sanitize_text_field( $rcp_options['authorize_api_login'] ) : '';
			$this->transaction_key = isset( $rcp_options['authorize_txn_key'] ) ? sanitize_text_field( $rcp_options['authorize_txn_key'] ) : '';
		}

		$this->md5_hash_value  = isset( $rcp_options['authorize_hash_value'] ) ? sanitize_text_field( $rcp_options['authorize_hash_value'] ) : '';

		require_once RCP_PLUGIN_DIR . 'includes/libraries/anet_php_sdk/autoload.php';

	} // end init

	/**
	 * Validate additional fields during registration submission
	 *
	 * @since 2.7
	 */
	public function validate_fields() {

		if( empty( $_POST['rcp_card_cvc'] ) ) {
			rcp_errors()->add( 'missing_card_code', __( 'The security code you have entered is invalid', 'rcp' ), 'register' );
		}

		if( empty( $_POST['rcp_card_zip'] ) ) {
			rcp_errors()->add( 'missing_card_zip', __( 'Please enter a Zip / Postal Code code', 'rcp' ), 'register' );
		}

		if ( empty( $this->api_login_id ) || empty( $this->transaction_key ) ) {
			rcp_errors()->add( 'missing_authorize_settings', __( 'Authorize.net API Login ID or Transaction key is missing.', 'rcp' ), 'register' );
		}

		$sub_id = ! empty( $_POST['rcp_level'] ) ? absint( $_POST['rcp_level'] ) : false;

		if( $sub_id ) {

			$sub = rcp_get_subscription_length( $sub_id );

			if( rcp_registration_is_recurring() && 'day' == $sub->duration_unit && $sub->duration < 7 ) {
				rcp_errors()->add( 'invalid_authorize_length', __( 'Authorize.net does not permit subscriptions with renewal periods less than 7 days.', 'rcp' ), 'register' );
			}

			if( rcp_registration_is_recurring() && 'year' == $sub->duration_unit && $sub->duration > 1 ) {
				rcp_errors()->add( 'invalid_authorize_length_years', __( 'Authorize.net does not permit subscriptions with renewal periods greater than 1 year.', 'rcp' ), 'register' );
			}

		}

	}

	/**
	 * Process registration
	 *
	 * @since 2.7
	 */
	public function process_signup() {

		/**
		 * @var RCP_Payments $rcp_payments_db
		 */
		global $rcp_payments_db;

		if ( empty( $this->api_login_id ) || empty( $this->transaction_key ) ) {
			rcp_errors()->add( 'missing_authorize_settings', __( 'Authorize.net API Login ID or Transaction key is missing.', 'rcp' ) );
		}

		$member = new RCP_Member( $this->user_id );

		$length = $this->length;
		$unit   = $this->length_unit . 's';

		if( 'years' == $unit && 1 == $length ) {
			$unit   = 'months';
			$length = 12;
		}

		$names = explode( ' ', sanitize_text_field( $_POST['rcp_card_name'] ) );
		$fname = isset( $names[0] ) ? $names[0] : $member->user_first;

		if( ! empty( $names[1] ) ) {
			unset( $names[0] );
			$lname = implode( ' ', $names );
		} else {
			$lname = $member->user_last;
		}

		try {

			/**
			 * Create a merchantAuthenticationType object with authentication details.
			 */
			$merchant_authentication = new AnetAPI\MerchantAuthenticationType();
			$merchant_authentication->setName( $this->api_login_id );
			$merchant_authentication->setTransactionKey( $this->transaction_key );

			/**
			 * Set the transaction's refId
			 */
			$refId = 'ref' . time();

			/**
			 * Add credit card details and create payment object.
			 */
			$credit_card = new AnetAPI\CreditCardType();
			$credit_card->setCardNumber( sanitize_text_field( $_POST['rcp_card_number'] ) );
			$credit_card->setExpirationDate( sanitize_text_field( $_POST['rcp_card_exp_year'] ) . '-' . sanitize_text_field( $_POST['rcp_card_exp_month'] ) );
			$credit_card->setCardCode( sanitize_text_field( $_POST['rcp_card_cvc'] ) );

			$payment = new AnetAPI\PaymentType();
			$payment->setCreditCard( $credit_card );

			$environment = rcp_is_sandbox() ? \net\authorize\api\constants\ANetEnvironment::SANDBOX : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;

			/**
			 * Create a recurring subscription.
			 */
			if ( $this->auto_renew ) {

				/**
				 * First authorize the initial amount because Authorize.net doesn't actually
				 * take payment until several hours later. If this fails then we won't be
				 * creating the subscription.
				 */
				rcp_log( sprintf( 'Authorizing initial payment amount with Authorize.net for user #%d.', $this->user_id ) );

				$auth_transaction = new AnetAPI\TransactionRequestType();
				$auth_transaction->setTransactionType( 'authOnlyTransaction' );
				$auth_transaction->setAmount( $this->initial_amount );
				$auth_transaction->setPayment( $payment );

				$auth_request = new AnetAPI\CreateTransactionRequest();
				$auth_request->setMerchantAuthentication( $merchant_authentication );
				$auth_request->setRefId( $refId );
				$auth_request->setTransactionRequest( $auth_transaction );

				$auth_controller = new AnetController\CreateTransactionController( $auth_request );
				$auth_response   = $auth_controller->executeWithApiResponse( $environment );

				// Invalid or no response from Authorize.net.
				if ( empty( $auth_response ) ) {
					$error_messages = $auth_response->getMessages()->getMessage();
					$error          = '<p>' . __( 'There was a problem processing your payment.', 'rcp' ) . '</p>';
					$error         .= '<p>' . sprintf( __( 'Error code: %s', 'rcp' ), $error_messages[0]->getCode() ) . '</p>';
					$error         .= '<p>' . sprintf( __( 'Error message: %s', 'rcp' ), $error_messages[0]->getText() ) . '</p>';

					rcp_log( sprintf( 'Authorize.net card authorization failed for user #%d. Invalid response from Authorize.net. Error code: %s. Error message: %s.', $this->user_id, $error_messages[0]->getCode(), $error_messages[0]->getText() ) );

					$this->handle_processing_error( new Exception( $error ) );
				}

				$auth_transaction_response = $auth_response->getTransactionResponse();

				// Successful API request, but authorization was not successful.
				if ( empty( $auth_transaction_response ) || $auth_transaction_response->getResponseCode() != '1' ) {
					$errors  = $auth_transaction_response->getErrors();
					$error   = '<p>' . __( 'There was a problem processing your payment.', 'rcp' ) . '</p>';
					$error  .= '<p>' . sprintf( __( 'Error code: %s', 'rcp' ), $errors[0]->getErrorCode() ) . '</p>';
					$error  .= '<p>' . sprintf( __( 'Error message: %s', 'rcp' ), $errors[0]->getErrorText() ) . '</p>';

					rcp_log( sprintf( 'Authorize.net card authorization failed for user #%d. Card was declined. Error code: %s. Error message: %s.', $this->user_id, $errors[0]->getErrorCode(), $errors[0]->getErrorText() ) );

					$this->handle_processing_error( new Exception( $error ) );
				}

				/**
				 * Authorization was successful! Now we can create the actual subscription.
				 */

				/**
				 * Configure the subscription information.
				 */
				$subscription = new AnetAPI\ARBSubscriptionType();
				$subscription->setName( substr( $this->subscription_name . ' - ' . $this->subscription_key, 0, 50 ) ); // Max of 50 characters

				/**
				 * Configure billing interval.
				 */
				$interval = new AnetAPI\PaymentScheduleType\IntervalAType();
				$interval->setLength( $length );
				$interval->setUnit( $unit );

				/**
				 * Configure billing schedule.
				 */
				$payment_schedule = new AnetAPI\PaymentScheduleType();
				$payment_schedule->setInterval( $interval );
				$payment_schedule->setStartDate( new DateTime( date( 'Y-m-d' ) ) );
				$payment_schedule->setTotalOccurrences( 9999 );
				$payment_schedule->setTrialOccurrences( 1 );

				// Delay start date for free trials.
				if ( $this->is_trial() ) {
					$payment_schedule->setStartDate( new DateTime( date( 'Y-m-d' ), strtotime( $this->subscription_data['trial_duration'] . ' ' . $this->subscription_data['trial_duration_unit'], current_time( 'timestamp' ) ) ) );
				}

				$subscription->setPaymentSchedule( $payment_schedule );
				$subscription->setAmount( $this->amount );
				$subscription->setTrialAmount( $this->initial_amount );

				/**
				 * Add credit card details to subscription.
				 */
				$subscription->setPayment( $payment );

				/**
				 * Configure order details.
				 */
				$order = new AnetAPI\OrderType();
				$order->setDescription( $this->subscription_key );
				$subscription->setOrder( $order );

				/**
				 * Add customer information.
				 */
				$bill_to = new AnetAPI\NameAndAddressType();
				$bill_to->setFirstName( $fname );
				$bill_to->setLastName( $lname );
				$bill_to->setZip( sanitize_text_field( $_POST['rcp_card_zip'] ) );
				$subscription->setBillTo( $bill_to );

				/**
				 * Make API request.
				 */
				$request = new AnetAPI\ARBCreateSubscriptionRequest();
				$request->setMerchantAuthentication( $merchant_authentication );
				$request->setRefId( $refId );
				$request->setSubscription( $subscription );
				$controller = new AnetController\ARBCreateSubscriptionController( $request );

				$response = $controller->executeWithApiResponse( $environment );

				if ( $response != null && $response->getMessages()->getResultCode() == "Ok" ) {

					// If the customer has an existing subscription, we need to cancel it
					if ( $member->just_upgraded() && $member->can_cancel() ) {
						$cancelled = $member->cancel_payment_profile( false );
					}

					$member->set_recurring( $this->auto_renew );
					$member->set_payment_profile_id( 'anet_' . $response->getSubscriptionId() );

					if ( $this->is_trial() ) {

						// Complete $0 payment and activate account.
						$rcp_payments_db->update( $this->payment->id, array(
							'payment_type' => 'Credit Card',
							'status'       => 'complete'
						) );

					} else {

						// Manually set these values because webhook has a big delay and we want to activate the account ASAP.
						$force_now  = $this->auto_renew || ( $member->get_subscription_id() != $this->subscription_id );
						$expiration = $member->calculate_expiration( $force_now );
						$member->set_subscription_id( $this->subscription_id );
						$member->set_expiration_date( $expiration );
						$member->set_status( 'active' );

						/*
						 * Set pending expiration date so this will be used in rcp_add_user_to_subscription() when the webhook
						 * gets the transaction ID and completes the payment, which may take several hours.
						 */
						update_user_meta( $this->user_id, 'rcp_pending_expiration_date', $expiration );

					}

					$member->add_note( __( 'Subscription started in Authorize.net', 'rcp' ) );

					if ( ! is_user_logged_in() ) {

						// log the new user in
						rcp_login_user_in( $this->user_id, $this->user_name, $_POST['rcp_user_pass'] );

					}

					do_action( 'rcp_authorizenet_signup', $this->user_id, $this, $response );

				} else {

					$error_messages = $response->getMessages()->getMessage();
					$error          = '<p>' . __( 'There was a problem processing your payment.', 'rcp' ) . '</p>';
					$error         .= '<p>' . sprintf( __( 'Error code: %s', 'rcp' ), $error_messages[0]->getCode() ) . '</p>';
					$error         .= '<p>' . sprintf( __( 'Error message: %s', 'rcp' ), $error_messages[0]->getText() ) . '</p>';

					$this->handle_processing_error( new Exception( $error ) );

				}

			} else {

				/**
				 * Process one-time transaction.
				 */

				/**
				 * Create new order.
				 */
				$order = new AnetAPI\OrderType();
				$order->setInvoiceNumber( $this->payment->id );
				$order->setDescription( $this->payment->subscription );

				/**
				 * Set up billing information.
				 */
				$bill_to = new AnetAPI\CustomerAddressType();
				$bill_to->setFirstName( $fname );
				$bill_to->setLastName( $lname );
				$bill_to->setZip( sanitize_text_field( $_POST['rcp_card_zip'] ) );
				$bill_to->setEmail( $this->email );

				/**
				 * Create a transaction and add all the information.
				 */
				$transaction = new AnetAPI\TransactionRequestType();
				$transaction->setTransactionType( 'authCaptureTransaction' );
				$transaction->setAmount( $this->initial_amount );
				$transaction->setPayment( $payment );
				$transaction->setOrder( $order );
				$transaction->setBillTo( $bill_to );

				/**
				 * Make API request.
				 */
				$request = new AnetAPI\CreateTransactionRequest();
				$request->setMerchantAuthentication( $merchant_authentication );
				$request->setRefId( $refId );
				$request->setTransactionRequest( $transaction );
				$controller = new AnetController\CreateTransactionController( $request );

				$response = $controller->executeWithApiResponse( $environment );

				if ( $response != null && $response->getMessages()->getResultCode() == "Ok" ) {

					$transaction_response = $response->getTransactionResponse();

					if ( ! empty( $transaction_response ) && '1' == $transaction_response->getResponseCode() ) {

						/**
						 * Payment was successful. Complete the pending payment and activate the subscription.
						 */
						$rcp_payments_db->update( $this->payment->id, array(
							'date'           => date( 'Y-m-d g:i:s', time() ),
							'payment_type'   => __( 'Authorize.net Credit Card One Time', 'rcp' ),
							'transaction_id' => 'anet_' . $transaction_response->getTransID(),
							'status'         => 'complete'
						) );

						do_action( 'rcp_gateway_payment_processed', $member, $this->payment->id, $this );

					} else {

						/**
						 * API request was successful but card was declined.
						 */
						$errors  = $transaction_response->getErrors();
						$error   = '<p>' . __( 'There was a problem processing your payment.', 'rcp' ) . '</p>';
						$error  .= '<p>' . sprintf( __( 'Error code: %s', 'rcp' ), $errors[0]->getErrorCode() ) . '</p>';
						$error  .= '<p>' . sprintf( __( 'Error message: %s', 'rcp' ), $errors[0]->getErrorText() ) . '</p>';

						$this->handle_processing_error( new Exception( $error ) );

					}

				} else {

					/**
					 * Something in the API request failed or no response from Authorize.net.
					 */
					$error_messages = $response->getMessages()->getMessage();
					$error          = '<p>' . __( 'There was a problem processing your payment.', 'rcp' ) . '</p>';
					$error         .= '<p>' . sprintf( __( 'Error code: %s', 'rcp' ), $error_messages[0]->getCode() ) . '</p>';
					$error         .= '<p>' . sprintf( __( 'Error message: %s', 'rcp' ), $error_messages[0]->getText() ) . '</p>';

					$this->handle_processing_error( new Exception( $error ) );

				}

			}

		} catch ( AuthorizeNetException $e ) {
			$this->handle_processing_error( $e );
		}

		// redirect to the success page, or error page if something went wrong
		wp_redirect( $this->return_url ); exit;
	}

	/**
	 * Handles the error processing.
	 *
	 * @param Exception $exception
	 *
	 * @since 2.9.5
	 * @return void
	 */
	protected function handle_processing_error( $exception ) {

		$this->error_message = $exception->getMessage();

		do_action( 'rcp_registration_failed', $this );

		wp_die( $exception->getMessage(), __( 'Error', 'rcp' ), array( 'response' => 401 ) );

	}

	/**
	 * Proccess webhooks
	 *
	 * @since 2.7
	 */
	public function process_webhooks() {

		global $rcp_payments_db;

		if ( empty( $_GET['listener'] ) || 'authnet' != $_GET['listener'] ) {
			return;
		}

		rcp_log( 'Starting to process Authorize.net webhook.' );

		if( ! $this->is_silent_post_valid( $_POST ) ) {
			rcp_log( 'Exiting Authorize.net webhook - invalid MD5 hash.' );

			die( 'invalid silent post' );
		}

		$anet_subscription_id = intval( $_POST['x_subscription_id'] );

		if ( $anet_subscription_id ) {

			$response_code = intval( $_POST['x_response_code'] );
			$reason_code   = intval( $_POST['x_response_reason_code'] );

			$member_id = rcp_get_member_id_from_profile_id( 'anet_' . $anet_subscription_id );

			if( empty( $member_id ) ) {
				rcp_log( 'Exiting Authorize.net webhook - member ID not found.' );

				die( 'no member found' );
			}

			$member   = new RCP_Member( $member_id );
			$payments = new RCP_Payments();

			rcp_log( sprintf( 'Processing webhook for member #%d.', $member->ID ) );

			if ( 1 == $response_code ) {

				// Approved
				$renewal_amount = sanitize_text_field( $_POST['x_amount'] );
				$transaction_id = sanitize_text_field( $_POST['x_trans_id'] );
				$is_trialing    = $member->is_trialing();

				$payment_data = array(
					'date'             => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
					'subscription'     => $member->get_subscription_name(),
					'payment_type'     => 'Credit Card',
					'subscription_key' => $member->get_subscription_key(),
					'amount'           => $renewal_amount,
					'user_id'          => $member->ID,
					'transaction_id'   => 'anet_' . $transaction_id,
					'status'           => 'complete'
				);

				$pending_payment_id = $member->get_pending_payment_id();
				if ( ! empty( $pending_payment_id ) ) {

					rcp_log( 'Processing approved Authorize.net payment via webhook - updating pending payment.' );

					// Completing a pending payment (this will be the first payment made via registration).
					$rcp_payments_db->update( absint( $pending_payment_id ), $payment_data );
					$payment_id = $pending_payment_id;

				} else {

					rcp_log( 'Processing approved Authorize.net payment via webhook - inserting new payment.' );

					$payment_id = $payments->insert( $payment_data );

				}

				if ( intval( $_POST['x_subscription_paynum'] ) > 1 || $is_trialing ) {

					// Renewal payment.
					$member->renew( $member->is_recurring() );
					do_action( 'rcp_webhook_recurring_payment_processed', $member, $payment_id, $this );

				} elseif ( $member->is_recurring() ) {

					// Recurring profile first created.
					do_action( 'rcp_webhook_recurring_payment_profile_created', $member, $this );

				}

				$member->add_note( __( 'Subscription processed in Authorize.net', 'rcp' ) );

				do_action( 'rcp_authorizenet_silent_post_payment', $member, $this );
				do_action( 'rcp_gateway_payment_processed', $member, $payment_id, $this );

			} elseif ( 2 == $response_code ) {

				// Declined
				rcp_log( 'Processing Authorize.net webhook - declined payment.' );

				if ( ! empty( $_POST['x_trans_id'] ) ) {
					$this->webhook_event_id = sanitize_text_field( $_POST['x_trans_id'] );
				}

				do_action( 'rcp_recurring_payment_failed', $member, $this );
				do_action( 'rcp_authorizenet_silent_post_error', $member, $this );

			} elseif ( 3 == $response_code || 8 == $reason_code ) {

				// An expired card
				rcp_log( 'Processing Authorize.net webhook - expired card.' );

				if ( ! empty( $_POST['x_trans_id'] ) ) {
					$this->webhook_event_id = sanitize_text_field( $_POST['x_trans_id'] );
				}

				do_action( 'rcp_recurring_payment_failed', $member, $this );
				do_action( 'rcp_authorizenet_silent_post_error', $member, $this );

			} else {

				// Other Error
				do_action( 'rcp_authorizenet_silent_post_error', $member, $this );

			}

			/*
			 * Cancel the membership immediately if payment was not successful and this was
			 * the first charge not part of a trial. This should probably never happen since
			 * we authorize cards beforehand, but just in case authorization was successful
			 * but the actual charge fails a few hours later.
			 */
			if ( 1 != $response_code && 1 == intval( $_POST['x_subscription_paynum'] ) && ! $member->is_trialing() ) {
				rcp_log( sprintf( 'Cancelling membership for user #%d, as initial charge from Authorize.net failed. Response code: %d', $member->ID, $response_code ) );
				$member->set_status( 'expired' );
			}
		}

		die( 'success');
	}

	/**
	 * Load credit card fields
	 *
	 * @since 2.7
	 */
	public function fields() {
		ob_start();
		rcp_get_template_part( 'card-form' );
		return ob_get_clean();
	}

	/**
	 * Determines if the silent post is valid by verifying the MD5 Hash
	 *
	 * @access  public
	 * @since   2.7
	 * @param   array $request The Request array containing data for the silent post
	 * @return  bool
	 */
	public function is_silent_post_valid( $request ) {

		$auth_md5 = isset( $request['x_MD5_Hash'] ) ? $request['x_MD5_Hash'] : '';

		//Sanity check to ensure we have an MD5 Hash from the silent POST
		if( empty( $auth_md5 ) ) {
			return false;
		}

		$str           = $this->md5_hash_value . $request['x_trans_id'] . $request['x_amount'];
		$generated_md5 = strtoupper( md5( $str ) );

		return hash_equals( $generated_md5, $auth_md5 );
	}

}
