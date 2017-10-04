<?php
/**
 * PayPal Express Confirmation
 *
 * This template is loaded while processing a PayPal Express payment. The customer is
 * asked to confirm the subscription details.
 *
 * For modifying this template, please see: http://docs.restrictcontentpro.com/article/1738-template-files
 *
 * @package     Restrict Content Pro
 * @subpackage  Templates/PayPal Express Confirmation
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

global $rcp_checkout_details; ?>
<div class="rcp-confirm-details" id="billing_info">
	<h3><?php _e( 'Please confirm your payment', 'rcp' ); ?></h3>
	<p><strong><?php echo $rcp_checkout_details['FIRSTNAME'] ?> <?php echo $rcp_checkout_details['LASTNAME'] ?></strong><br />
	<?php _e( 'PayPal Status:', 'rcp' ); ?> <?php echo $rcp_checkout_details['PAYERSTATUS'] ?><br />
	<?php _e( 'Email:', 'rcp' ); ?> <?php echo $rcp_checkout_details['EMAIL'] ?></p>
</div>
<table id="order_summary" class="rcp-table">
	<thead>
		<tr>
			<th><?php _e( 'Subscription', 'rcp' ); ?></th>
			<?php if( ! empty( $_GET['rcp-recurring'] ) ) : ?>
				<th><?php _e( 'Recurs', 'rcp' ); ?></th>
			<?php endif; ?>
			<?php if( ! empty( $_GET['rcp-recurring'] ) && ! empty( $rcp_checkout_details['subscription']['fee'] ) ) : ?>
				<th><?php _e( 'Signup Fee', 'rcp' ); ?></th>
			<?php endif; ?>
			<th><?php _e( 'Subscription Cost', 'rcp' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td data-th="<?php esc_attr_e( 'Subscription', 'rcp' ); ?>"><?php echo $rcp_checkout_details['DESC']; ?></td>
			<?php if( ! empty( $_GET['rcp-recurring'] ) ) : ?>
				<td data-th="<?php esc_attr_e( 'Recurs', 'rcp' ); ?>">
					<?php
					printf( __( 'Every %d %s', 'rcp' ),
					    esc_html( $rcp_checkout_details['subscription']['duration'] ),
					    rcp_filter_duration_unit( esc_html( $rcp_checkout_details['subscription']['duration_unit'] ), esc_html( $rcp_checkout_details['subscription']['duration'] ) )
					);
					?>
				</td>
			<?php endif; ?>
			<?php if( ! empty( $_GET['rcp-recurring'] ) && ! empty( $rcp_checkout_details['subscription']['fee'] ) ) : ?>
				<td data-th="<?php esc_attr_e( 'Signup Fee', 'rcp' ); ?>">
					<?php echo rcp_format_amount( $rcp_checkout_details['subscription']['fee'] ); ?>
				</td>
			<?php endif; ?>
			<td data-th="<?php esc_attr_e( 'Subscription Cost', 'rcp' ); ?>"><?php echo rcp_format_amount( $rcp_checkout_details['PAYMENTREQUEST_0_AMT' ] ); ?></td>
		</tr>
	</tbody>
</table>

<form action="<?php echo esc_url( add_query_arg( 'rcp-confirm', 'paypal_express' ) ); ?>" method="post">
	<input type="hidden" name="confirmation" value="yes" />
	<input type="hidden" name="token" value="<?php echo esc_attr( $_GET['token'] ); ?>" />
	<input type="hidden" name="payer_id" value="<?php echo esc_attr( $_GET['PayerID'] ); ?>" />
	<input type="hidden" name="rcp_ppe_confirm_nonce" value="<?php echo wp_create_nonce( 'rcp-ppe-confirm-nonce' ); ?>"/>
	<input type="submit" value="<?php esc_attr_e( 'Confirm', 'rcp' ); ?>" />
</form>