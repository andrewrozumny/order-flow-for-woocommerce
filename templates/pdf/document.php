<?php
/**
 * Order document template.
 *
 * Override in your theme: yourtheme/order-flow-for-woocommerce/pdf/document.php
 *
 * Available: $ofwc_doc (array) with the keys order, status, title, status_label, order_number, date,
 * logo_src, store_details, text, billing, shipping, items, totals, site_url.
 * dompdf supports CSS 2.1, so the layout uses tables.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template variables, see the header comment.
 *
 * @var array<string, mixed> $ofwc_doc
 */
$ofwc_accent = '#1d2327';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( (string) $ofwc_doc['title'] ); ?></title>
<style>
	@page { margin: 40px 44px 60px 44px; }
	body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1d2327; line-height: 1.45; }
	table { border-collapse: collapse; width: 100%; }
	td, th { vertical-align: top; }
	.head td { padding-bottom: 18px; border-bottom: 2px solid <?php echo esc_attr( $ofwc_accent ); ?>; }
	.logo { max-height: 56px; max-width: 220px; }
	.store { text-align: right; color: #50575e; font-size: 9px; }
	h1 { font-size: 22px; margin: 22px 0 4px 0; }
	.meta { margin-bottom: 18px; color: #50575e; font-size: 10px; }
	.meta strong { color: #1d2327; }
	.badge { background: #f0f0f1; border: 1px solid #c3c4c7; padding: 1px 6px; font-size: 9px; }
	.addr td { width: 50%; padding: 10px 12px; background: #f6f7f7; }
	.addr h3 { margin: 0 0 4px 0; font-size: 9px; text-transform: uppercase; color: #50575e; }
	.items { margin-top: 20px; }
	.items th { background: <?php echo esc_attr( $ofwc_accent ); ?>; color: #fff; text-align: left; padding: 6px 8px; font-size: 9px; }
	.items td { padding: 6px 8px; border-bottom: 1px solid #dcdcde; }
	.items th.num, .items td.num { text-align: right; }
	.items .col-qty { width: 40px; }
	.sku { color: #787c82; font-size: 8px; }
	.totals { width: 55%; margin-left: 45%; margin-top: 10px; }
	.totals th { text-align: left; padding: 4px 8px; font-weight: normal; color: #50575e; }
	.totals td { text-align: right; padding: 4px 8px; }
	.totals .last th, .totals .last td { font-weight: bold; color: #1d2327; border-top: 2px solid <?php echo esc_attr( $ofwc_accent ); ?>; font-size: 11px; }
	.text { margin-top: 22px; padding-top: 12px; border-top: 1px solid #dcdcde; }
	.footer { position: fixed; bottom: -36px; left: 0; right: 0; text-align: center; color: #787c82; font-size: 8px; border-top: 1px solid #dcdcde; padding-top: 6px; }
</style>
</head>
<body>
<div class="footer"><?php echo esc_html( (string) $ofwc_doc['site_url'] ); ?></div>

<table class="head"><tr>
	<td>
		<?php if ( '' !== $ofwc_doc['logo_src'] ) : ?>
			<img class="logo" src="<?php echo esc_attr( (string) $ofwc_doc['logo_src'] ); ?>" alt="">
		<?php endif; ?>
	</td>
	<td class="store"><?php echo wp_kses_post( (string) $ofwc_doc['store_details'] ); ?></td>
</tr></table>

<h1><?php echo esc_html( (string) $ofwc_doc['title'] ); ?></h1>
<div class="meta">
	<?php esc_html_e( 'Order', 'order-flow-for-woocommerce' ); ?> <strong>#<?php echo esc_html( (string) $ofwc_doc['order_number'] ); ?></strong>
	&nbsp;|&nbsp; <?php echo esc_html( (string) $ofwc_doc['date'] ); ?>
	&nbsp;|&nbsp; <span class="badge"><?php echo esc_html( (string) $ofwc_doc['status_label'] ); ?></span>
</div>

<table class="addr"><tr>
	<td>
		<h3><?php esc_html_e( 'Billing address', 'order-flow-for-woocommerce' ); ?></h3>
		<?php echo '' !== $ofwc_doc['billing'] ? wp_kses_post( (string) $ofwc_doc['billing'] ) : '-'; ?>
	</td>
	<td>
		<h3><?php esc_html_e( 'Shipping address', 'order-flow-for-woocommerce' ); ?></h3>
		<?php echo '' !== $ofwc_doc['shipping'] ? wp_kses_post( (string) $ofwc_doc['shipping'] ) : '-'; ?>
	</td>
</tr></table>

<table class="items">
	<thead><tr>
		<th><?php esc_html_e( 'Product', 'order-flow-for-woocommerce' ); ?></th>
		<th class="num col-qty"><?php esc_html_e( 'Qty', 'order-flow-for-woocommerce' ); ?></th>
		<th class="num"><?php esc_html_e( 'Unit price', 'order-flow-for-woocommerce' ); ?></th>
		<th class="num"><?php esc_html_e( 'Total', 'order-flow-for-woocommerce' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( (array) $ofwc_doc['items'] as $ofwc_row ) : ?>
		<tr>
			<td>
				<?php echo esc_html( (string) $ofwc_row['name'] ); ?>
				<?php if ( '' !== $ofwc_row['sku'] ) : ?>
					<div class="sku"><?php esc_html_e( 'SKU:', 'order-flow-for-woocommerce' ); ?> <?php echo esc_html( (string) $ofwc_row['sku'] ); ?></div>
				<?php endif; ?>
			</td>
			<td class="num"><?php echo esc_html( (string) $ofwc_row['qty'] ); ?></td>
			<td class="num"><?php echo wp_kses_post( (string) $ofwc_row['price'] ); ?></td>
			<td class="num"><?php echo wp_kses_post( (string) $ofwc_row['total'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php
$ofwc_totals = (array) $ofwc_doc['totals'];
$ofwc_last   = array_key_last( $ofwc_totals );
?>
<table class="totals">
	<?php foreach ( $ofwc_totals as $ofwc_key => $ofwc_total ) : ?>
		<tr<?php echo $ofwc_key === $ofwc_last ? ' class="last"' : ''; ?>>
			<th><?php echo esc_html( rtrim( (string) $ofwc_total['label'], ':' ) ); ?></th>
			<td><?php echo wp_kses_post( (string) $ofwc_total['value'] ); ?></td>
		</tr>
	<?php endforeach; ?>
</table>

<?php if ( '' !== trim( (string) $ofwc_doc['text'] ) ) : ?>
	<div class="text"><?php echo wp_kses_post( (string) $ofwc_doc['text'] ); ?></div>
<?php endif; ?>
</body>
</html>
