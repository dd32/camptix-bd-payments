<?php
namespace CamptixBD\Gateway;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSLCommerz gateway
 */
class SSLCommerz extends \CampTix_Payment_Method {

	public $id                   = 'sslcommerz';
	public $name                 = 'SSLCommerz';
	public $description          = 'SSLCommerz payment gateway for Bangladesh.';
	public $supported_currencies = [ 'BDT' ];

	function camptix_init() {
		$this->options = array_merge( [
			'merchant_id'    => '',
			'store_password' => '',
			'sandbox'        => true,
		], $this->get_payment_options() );

		if ( $this->gateway_enabled() ) {
			add_filter( 'camptix_form_register_complete_attendee_object', [ $this, 'add_attendee_info' ], 10, 3 );
			add_action( 'template_redirect', [ $this, 'template_redirect' ] );
			add_action( 'template_redirect', [ $this, 'early_template_redirect' ], 5 ); // Before CampTix_Require_Login::block_unauthenticated_actions
		}
	}

	/**
	 * Check if the gateway is enabled
	 *
	 * @return boolean
	 */
	public function gateway_enabled() {
		return isset( $this->camptix_options['payment_methods'][ $this->id ] );
	}

	/**
	 * If the phone number is passed, add this to the attendee object
	 *
	 * @param [type] $attendee
	 * @param [type] $attendee_info
	 * @param [type] $current_count
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		if ( ! empty( $_POST['tix_attendee_info'][ $current_count ]['phone'] ) ) {
			$attendee->phone = trim( $_POST['tix_attendee_info'][ $current_count ]['phone'] );
		}

		return $attendee;
	}

	/**
	 * Process the payment
	 *
	 * @param  string $payment_token
	 *
	 * @return void
	 */
	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) ) {
			return false;
		}

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( __( 'The selected currency is not supported by this payment method.', 'bd-payments-camptix' ) );
		}

		$url   = $this->options['sandbox'] ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
		$order = $this->get_order( $payment_token );

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action'         => 'payment_cancel',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$notify_url = add_query_arg( array(
			'tix_action'         => 'payment_notify',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$fail_url = add_query_arg( array(
			'tix_action'         => 'payment_failed',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$attendees = get_posts(
			[
				'post_type'   => 'tix_attendee',
				'post_status' => 'any',
				'meta_query'  => [
					[
						'key'     => 'tix_payment_token',
						'compare' => '=',
						'value'   => $payment_token,
					],
				],
			]
		);

		// take the first attendee as the customer because
		// we need the name and phone number for the gateway
		$attendee = reset( $attendees );
		$email = $attendee->tix_email;
		$name  = $attendee->tix_first_name . ' ' . $attendee->tix_last_name;
		$phone = $attendee->tix_phone;

		// build the payment description wth the event name and
		// ticket names with quantity
		$description = $camptix->email_template_shortcode_event_name([]);

		foreach ( $order['items'] as $ticket ) {
			$description .= ' | ' . $ticket['name'] . ' x' . $ticket['quantity'];
		}

		$args = [
			'store_id'     => $this->options['merchant_id'],
			'tran_id'      => $payment_token,
			'success_url'  => $return_url,
			'fail_url'     => $fail_url,
			'emi_option'   => 0,
			'cancel_url'   => $cancel_url,
			'ipn_url'      => $notify_url,
			'total_amount' => $order['total'],
			'currency'     => $this->camptix_options['currency'],
			'store_passwd' => $this->options['store_password'],
			'desc'         => $description,
			'cus_name'     => $name,
			'cus_email'    => $email,
			'cus_phone'    => $phone,
		];

		$response = wp_remote_post( $url . '/gwprocess/v3/api.php', [
			'body' => $args
		] );

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['GatewayPageURL'] ) && $body['GatewayPageURL'] != '' ) {
				wp_redirect( $body['GatewayPageURL'] );
				exit;
			}
		}

		_e( 'Something went wrong with creating the payment session.', 'bd-payments-camptix' );

		return;
	}

	/**
	 * Add payment settings field
	 *
	 * @return void
	 */
	function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_id', __( 'Store ID', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
		$this->add_settings_field_helper( 'store_password', __( 'Store Password', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode',  'bd-payments-camptix' ), [ $this, 'field_yesno' ] );
	}

	/**
	 * Validate payment settings fields
	 *
	 * @param  array $input
	 *
	 * @return array
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_id'] ) ) {
			$output['merchant_id'] = $input['merchant_id'];
		}

		if ( isset( $input['store_password'] ) ) {
			$output['store_password'] = $input['store_password'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}

	/**
	 * Monitor for return-from-gateway earlier in the request.
	 *
	 * SSLCommerz redirects back with a cross-domain POST request, which will result in the request
	 * not being authenticated on the WordCamp.org side, and thus blocked by the Require Login add-on.
	 *
	 * The lack of cookies is a browser security feature, and while we can work around this, we really shouldn't.
	 * Instead, this validates the returned POST data and if valid, submits a local GET redirect in place of it.
	 * The POST data (transaction/error details) are saved in a temporary cookie for use on the GET request.
	 *
	 * Without this, upon completing a payment, users will simply land on the ticket page without any indication
	 * they've got a ticket.
	 *
	 * NOTE: payment_notify IPN is not covered here, as it's an unauthenticated server-to-server request, and
	 * thus not blocked by Require Login.
	 */
	function early_template_redirect() {
		if (
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! isset( $_REQUEST['tix_action'], $_REQUEST['tix_payment_method'] ) ||
			$this->id != $_REQUEST['tix_payment_method'] ||
			! in_array( $_REQUEST['tix_action'], [ 'payment_return', 'payment_failed', 'payment_cancel' ] )
		) {
			return;
		}

		// Set a temporary cookie with the POST'd transaction data, which we'll use on the GET request.
		if ( $this->_ipn_hash_varify( $this->options['store_password'], $_POST ) ) {
			$cookie_data = json_encode( $_POST );
			setcookie( $this->id . '_transaction', $cookie_data, time() + 300, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		wp_safe_redirect( add_query_arg( [
			'tix_action'         => $_REQUEST['tix_action'] ?? '',
			'tix_payment_token'  => $_REQUEST['tix_payment_token'] ?? '',
			'tix_payment_method' => $this->id,
		], $GLOBALS['camptix']->get_tickets_url() ) );

		die();
	}

	/**
	 * Monitor for IPN and payment return
	 *
	 * @return void
	 */
	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || $this->id != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		/*
		 * If the request has the returned POST data in the temporary cookie, extract it and merge it into the request.
		 *
		 * See early_template_redirect() for more details.
		 */
		if ( isset( $_COOKIE[ $this->id . '_transaction' ] ) ) {
			// Retrieve the temporary cookie with the POST'd transaction data.
			$transaction_data = json_decode( wp_unslash( $_COOKIE[ $this->id . '_transaction' ] ), true );

			if (
				is_array( $transaction_data ) &&
				'GET' === $_SERVER['REQUEST_METHOD'] &&
				$this->_ipn_hash_varify( $this->options['store_password'], $transaction_data )
			) {
				// Merge the POST data into the request so that payment_notify() can use it.
				$_REQUEST = array_merge( $_REQUEST, $transaction_data );
				$_POST    = array_merge( $_POST, $transaction_data );
			}

			// Clear the temporary cookie.
			setcookie( $this->id . '_transaction', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		switch ( $_GET['tix_action'] ?? '' ) {
			case 'payment_return':
				// Payment return is handled as a notification, so fall through to that case.
			case 'payment_notify':
				$this->payment_notify();
				break;
			case 'payment_cancel':
				$this->payment_cancel();
				break;
			case 'payment_failed':
				$this->payment_failed();
				break;
		}
	}

	/**
	 * Process payment return step (IPN or interactively).
	 *
	 * @return mixed
	 */
	function payment_notify() {
		global $camptix;

		$payment_token  = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$transaction_id = isset( $_REQUEST['tran_id'] ) ? $_REQUEST['tran_id'] : '';
		$val_id         = isset( $_REQUEST['val_id'] ) ? $_REQUEST['val_id'] : '';

		// The payment transaction data is always in the POST data.
		$transaction_data = $_POST;

		$camptix->log( 'Payment validation from SSLCommerz', null, compact( 'payment_token', 'transaction_id', 'val_id', 'transaction_data' ) );

		if ( $this->_ipn_hash_varify( $this->options['store_password'], $transaction_data ) ) {

			$payment_data = [
				'transaction_id'      => $transaction_id,
				'val_id'              => $val_id,
				'transaction_details' => $transaction_data,
			];

			if ( $this->verify_transaction( $val_id, $payment_token ) ) {
				return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
			} else {
				$camptix->log( 'IPN Verification failed', null, $payment_data );
				return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
			}
		}

		return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED );
	}

	/**
	 * Cancel the payment
	 *
	 * @return void
	 */
	public function payment_cancel() {
		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$camptix->log('Cancel token: ' . $payment_token );

		if ( ! $payment_token ) {
			return $camptix->error( 'empty token' );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			return $camptix->error( 'could not find order' );
		}

		return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}

	/**
	 * Fail the payment
	 *
	 * @return void
	 */
	public function payment_failed() {
		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$camptix->log('Fail token: ' . $payment_token );

		if ( ! $payment_token ) {
			return $camptix->error( 'empty token' );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			return $camptix->error( 'could not find order' );
		}

		return $camptix->payment_result( $payment_token, \CampTix_Plugin::PAYMENT_STATUS_FAILED );
	}

	/**
	 * Verify the transaction
	 *
	 * @param  string $payment_token
	 *
	 * @return boolean
	 */
	public function verify_transaction( $val_id, $payment_token ) {
		global $camptix;

		$url  = $this->options['sandbox'] ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
		$url  = $url . '/validator/api/validationserverAPI.php';
		$args = [
			'body' => [
				'val_id'       => $val_id,
				'store_id'     => $this->options['merchant_id'],
				'store_passwd' => $this->options['store_password'],
				'format'       => 'json'
			],
			'timeout' => 30,
		];

		$response = wp_remote_get( $url, $args );

		if ( ! is_wp_error( $response ) ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ) );
			$order = $this->get_order( $payment_token );

			if ( in_array( $body->status, ['VALID', 'VALIDATED'] ) && $order['total'] == $body->amount ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verify IPN hash
	 *
	 * @param  string $store_passwd The store password.
	 * @param  array  $data         The data to validate.
	 *
	 * @return boolean
	 */
	function _ipn_hash_varify( $store_passwd, $data ) {

		if ( isset( $data['verify_sign'] ) && isset( $data['verify_key'] ) ) {
			$pre_define_key = explode(',', $data['verify_key']);
			$new_data       = array();

			if ( !empty( $pre_define_key ) ) {
				foreach ( $pre_define_key as $value ) {
					if ( isset( $data[ $value ] ) ) {
						$new_data[ $value ] = $data[$value];
					}
				}
			}

			# ADD MD5 OF STORE PASSWORD
			$new_data['store_passwd'] = md5( $store_passwd );

			# SORT THE KEY AS BEFORE
			ksort( $new_data );

			$hash_string = '';
			foreach ( $new_data as $key => $value ) {
				$hash_string .= $key . '=' . $value .'&';
			}

			$hash_string = rtrim( $hash_string, '&' );

			if ( md5( $hash_string ) == $data['verify_sign'] ) {
				return true;
			} else {
				return false;
			}
		}

		return false;
	}

}
