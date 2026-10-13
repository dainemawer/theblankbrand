<?php
/**
 * Outgoing mail — one sender for everything the shop sends.
 *
 * Every email comes from a no-reply address, while replies and the "contact us"
 * line in customer emails still point at a mailbox a person reads.
 *
 * WooCommerce makes that harder than it looks. It treats the From address as
 * the shop's contact address: the `{store_email}` placeholder that fills "If you
 * need any help with your order, please contact us at …" is the From address,
 * and Reply-To defaults to it as well. Changing the From address alone would
 * therefore tell customers to write to a mailbox nobody reads, and send their
 * replies there. So the sender is changed and both of those are pointed back at
 * the support address.
 *
 * Lives in the must-use plugin with the rest of the integration so that a theme
 * switch cannot quietly put the sender back.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

/**
 * Sender address handling.
 *
 * @package TBB\Zoho
 */
class Mail {

	/**
	 * Address every email is sent from. Replies to it are not read.
	 */
	const SENDER = 'noreply@theblankbrand.co.za';

	/**
	 * Address customers are told to write to, and that replies go to.
	 */
	const SUPPORT = 'admin@theblankbrand.co.za';

	/**
	 * Always registers: WordPress's own mail goes through the same sender.
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return true;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Anything sent with wp_mail() and no From header of its own, such as
		// WordPress's password reset.
		add_filter( 'wp_mail_from', [ $this, 'sender' ] );
		add_filter( 'wp_mail_from_name', [ $this, 'sender_name' ] );

		// WooCommerce's emails. The From name is already set in its own
		// settings, so only the address is forced.
		add_filter( 'woocommerce_email_from_address', [ $this, 'sender' ] );
		add_filter( 'woocommerce_email_format_string', [ $this, 'point_contact_at_support' ] );
		add_filter( 'woocommerce_email_headers', [ $this, 'point_replies_at_support' ] );
	}

	/**
	 * The From address.
	 *
	 * @return string
	 */
	public function sender(): string {
		return self::SENDER;
	}

	/**
	 * The From name for mail that does not set one.
	 *
	 * @return string
	 */
	public function sender_name(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Swap the sender for the support address in text WooCommerce has formatted.
	 *
	 * `{store_email}` resolves to the From address, which is now the no-reply
	 * one. Subjects, headings and the "contact us" line are all passed through
	 * here after their placeholders are filled, so replacing it at this point
	 * corrects every one of them without touching the From header itself.
	 *
	 * @param string $text Formatted text.
	 * @return string
	 */
	public function point_contact_at_support( $text ) {
		return is_string( $text ) ? str_ireplace( self::SENDER, self::SUPPORT, $text ) : $text;
	}

	/**
	 * Send replies to the support address.
	 *
	 * WooCommerce sets Reply-To to the From address for customer emails. The
	 * emails it sends to the shop already reply to the customer and are left
	 * alone: only a Reply-To that names the no-reply address is rewritten.
	 *
	 * @param string $headers Email headers.
	 * @return string
	 */
	public function point_replies_at_support( $headers ) {
		if ( ! is_string( $headers ) ) {
			return $headers;
		}

		return preg_replace(
			'/^(Reply-to:[^\r\n<]*<)' . preg_quote( self::SENDER, '/' ) . '(>)/im',
			'${1}' . self::SUPPORT . '${2}',
			$headers
		);
	}
}
