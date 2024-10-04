<?php
/**
 * Single Resource Price
 *
 * This template can be overridden by copying it to yourtheme/easyreservations/single-resource/price.php.
 *
 * HOWEVER, on occasion easyReservations will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @package easyReservations/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

global $resource;

?>
<p class="<?php echo esc_attr( apply_filters( 'easyreservations_resource_price_class', 'price' ) ); ?>">
	<?php esc_html_e( 'From:', 'easyReservations' ); ?><?php echo $resource->get_price_html(); ?>
</p>
<hr>