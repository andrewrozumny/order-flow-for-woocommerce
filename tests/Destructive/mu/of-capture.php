<?php
/**
 * Test helper for the throwaway clone only. Copied into wp-content/mu-plugins by `bin/destructive.sh sync`.
 *
 * - While the file /cache/tmp/capture-on exists, every outgoing email is written to /cache/tmp/mail.jsonl
 *   (recipients, subject, bodies, attachment names) and its attachments are copied to /cache/tmp/att/.
 *   Nothing is sent: the mailer is pointed at /bin/true.
 * - The file /cache/tmp/mail-fail makes wp_mail() report a failure.
 *
 * @package OrderFlowWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.ValidVariableName -- Test helper for the throwaway clone: PHPMailer property names are fixed, and a missing capture directory must never break a request.

add_action(
	'phpmailer_init',
	static function ( $mailer ): void {
		$dir = '/cache/tmp';
		if ( ! file_exists( $dir . '/capture-on' ) ) {
			return;
		}
		$attachments = array();
		$seq         = (int) @file_get_contents( $dir . '/att-seq' );
		foreach ( $mailer->getAttachments() as $attachment ) {
			++$seq;
			$source = (string) $attachment[0];
			$name   = (string) $attachment[2];
			@mkdir( $dir . '/att', 0777, true );
			$copy = $dir . '/att/' . $seq . '-' . preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
			@copy( $source, $copy );
			$attachments[] = array(
				'name'    => $name,
				'source'  => $source,
				'copy'    => $copy,
				'content' => is_readable( $copy ) ? filesize( $copy ) : -1,
			);
		}
		@file_put_contents( $dir . '/att-seq', (string) $seq );
		$to = array();
		foreach ( $mailer->getToAddresses() as $address ) {
			$to[] = $address[0];
		}
		$cc = array();
		foreach ( $mailer->getCcAddresses() as $address ) {
			$cc[] = $address[0];
		}
		$bcc = array();
		foreach ( $mailer->getBccAddresses() as $address ) {
			$bcc[] = $address[0];
		}
		$line = array(
			'to'           => $to,
			'cc'           => $cc,
			'bcc'          => $bcc,
			'from'         => $mailer->From,
			'subject'      => $mailer->Subject,
			'content_type' => $mailer->ContentType,
			'body'         => $mailer->Body,
			'alt_body'     => $mailer->AltBody,
			'attachments'  => $attachments,
			'reply_to'     => array_map( static fn( $a ) => $a[0], array_values( $mailer->getReplyToAddresses() ) ),
			'custom'       => $mailer->getCustomHeaders(),
		);
		@file_put_contents( $dir . '/mail.jsonl', wp_json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n", FILE_APPEND | LOCK_EX );
		$mailer->Mailer   = 'sendmail';
		$mailer->Sendmail = '/bin/true';
	},
	99999
);

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit ) {
		if ( file_exists( '/cache/tmp/mail-fail' ) ) {
			return false;
		}
		return $short_circuit;
	}
);
