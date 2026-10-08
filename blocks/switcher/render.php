<?php
/**
 * Language switcher: the current page in every site language.
 *
 * @package LangSail
 */

defined( 'ABSPATH' ) || exit;

echo \LangSail\switcher_markup( get_block_wrapper_attributes(), $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in switcher_markup().
