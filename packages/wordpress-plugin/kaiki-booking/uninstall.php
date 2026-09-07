<?php
/**
 * What is left behind when the plugin is deleted: nothing.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * WPP-11 asks for uninstall cleanup, and there is a second reason for it.
 *
 * A plugin that leaves rows behind is one an operator cannot cleanly retry.
 * The support path for almost everything is "deactivate it, delete it, install
 * it again" — and if the second installation finds the first one's settings, a
 * broken key stays broken through the one remedy anybody knows.
 *
 * The **secret key** matters most here. An operator who removes the plugin has
 * withdrawn its access; a secret sitting in `wp_options` afterwards is a
 * credential nobody is watching on a site nobody is thinking about.
 *
 * Deliberately not deleted: `kaiki_trip` posts. They are content with URLs that
 * a search engine has indexed and a visitor may have bookmarked, and removing
 * an operator's pages because they uninstalled a plugin is a decision that is
 * theirs to make in the posts list, not ours to make for them.
 */

require_once __DIR__ . '/src/Uninstall.php';

Kaiki\Booking\Uninstall::run();
