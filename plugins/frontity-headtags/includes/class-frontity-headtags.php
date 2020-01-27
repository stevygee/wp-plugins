<?php
/**
 * File class-frontity-headtags.php
 *
 * @package Frontity_Headtags
 */

/**
 * Class that contains the main behavior.
 *
 * This class has methods to get head tags by entity (posts, pages, taxonomies, authors and archives),
 *
 * These methods deal with three tasks mainly:
 * - add head tags to cache and invalidate it (using transients)
 * - compute and parse head tags by entity
 * - replace wp_query in order to compute head tags
 */
class Frontity_Headtags {
	/**
	 * Variable to store the original WP Query object (needed to restore it).
	 *
	 * @access  private
	 * @var     array
	 */
	private $query_backup = array();

	/**
	 * Get cached head tags if found.
	 *
	 * @param string $key Transient key.
	 * @return array|mixed
	 */
	public function get_cached_headtags( $key ) {
		return get_transient( "frontity_headtags_{$key}" );
	}

	/**
	 * Set cached head tags for this key.
	 *
	 * @param string      $key Transient key.
	 * @param array|mixed $headtags Head tags to cache.
	 * @return bool
	 */
	public function set_cached_headtags( $key, $headtags ) {
		$transient_key = "frontity_headtags_{$key}";

		// Get the list of transients.
		$transient_list = get_option( 'frontity_headtags_transients', array() );

		if ( ! in_array( $transient_key, $transient_list, true ) ) {

			// Add the transient key if is not in the list.
			$transient_list[] = $transient_key;
			update_option( 'frontity_headtags_transients', $transient_list );
		}

		return set_transient( $transient_key, $headtags, MONTH_IN_SECONDS );
	}

	/**
	 * Set cached head tags for this key.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	public function delete_cached_headtags( $key ) {
		$transient_key = "frontity_headtags_{$key}";

		// Get the list of transients.
		$transient_list = get_option( 'frontity_headtags_transients', array() );

		if ( in_array( $transient_key, $transient_list, true ) ) {

			// Remove the transient key from the list of transients.
			$transient_list = array_values(
				array_diff( $transient_list, array( $transient_key ) )
			);

			// Update the list of transients.
			update_option( 'frontity_headtags_transients', $transient_list );
		}

		return delete_transient( $transient_key );
	}

	/**
	 * Remove all cached head tags.
	 *
	 * @return bool
	 */
	public function clear_cache() {
		// Remove all transients.
		$transient_list = get_option( 'frontity_headtags_transients', array() );
		foreach ( $transient_list as $transient ) {
			delete_transient( $transient );
		}

		return update_option( 'frontity_headtags_transients', array() );
	}


	/**
	 * Get head tags for this key (from cache if possible).
	 *
	 * @param string       $key Transient key.
	 * @param string|array $query URL query string or array of vars.
	 * @return array|mixed
	 */
	public function get_headtags( $key, $query ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$skip_cache = isset( $_GET['head_tags_skip_cache'] )
			? wp_validate_boolean( $_GET['head_tags_skip_cache'] )
			: isset( $_GET['skip_cache'] );
		// phpcs:enable

		// Get head tags from transients.
		$headtags = $skip_cache ? null : $this->get_cached_headtags( $key );

		// If head tags are not cached, compute and cache them.
		if ( empty( $headtags ) ) {
			$headtags = $this->compute_headtags( $query );
			$this->set_cached_headtags( $key, $headtags );
		}
		// Return the head tags obtained.
		return $headtags;
	}

	/**
	 * Compute head tags for a specific query_vars.
	 *
	 * @param string|array $query_vars URL query string or array of vars.
	 * @return array
	 */
	public function compute_headtags( $query_vars ) {
		$this->backup_query();
		$this->replace_query( $query_vars );

		ob_start();
		
		// Arguments to be used when doing 'template_redirect' action.
		$requested_url = null;
		$do_redirect   = false;

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'template_redirect', $requested_url, $do_redirect );
		do_action( 'wp_head' );
		// phpcs:enable

		// Get rendered <head> content.
		$html = ob_get_clean();

		// Parse the HTML to create an array of head tags.
		$headtags = $this->parse( $html );

		// Filter not desired head tags.
		$headtags = apply_filters( 'frontity_headtags_result', $headtags );

		$this->restore_query();

		return $headtags;
	}


	/**
	 * Parse HTML to an array of meta key/value pairs using DOMDocument.
	 *
	 * @param string $html The HTML as generated by Yoast SEO.
	 * @return array An array containing all meta key/value pairs.
	 */
	private function parse( $html ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$headtags = array();

		$dom = new DOMDocument();
		$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		$nodes = $dom->getElementsByTagName( 'head' )[0]->childNodes;
		foreach ( $nodes as $node ) {

			// Ignore comments, texts, etc.
			if ( get_class( $node ) !== 'DOMElement' ) {
				continue;
			}

			// Create the tag object.
			$head_tag = array(
				'tag' => $node->tagName,
			);

			// Add attributes.
			if ( $node->hasAttributes() ) {
				$head_tag['attributes'] = array();
				foreach ( $node->attributes as $attr ) {
					$head_tag['attributes'][ $attr->nodeName ] = html_entity_decode( $attr->nodeValue );
				}
			}

			// Add content.
			if ( '' !== $node->textContent ) {
				$head_tag['content'] = $node->textContent;
			}

			// Append this tag to response.
			$headtags[] = $head_tag;
		}

		// phpcs:enable
		return $headtags;
	}

	/**
	 * Backup current query.
	 *
	 * @access private
	 */
	private function backup_query() {
		global $wp_query, $wp_the_query;
		// Store current values.
		array_push(
			$this->query_backup,
			array(
				'wp_query'     => $wp_query,
				'wp_the_query' => $wp_the_query,
			)
		);

	}

	/**
	 * Replace current query by the given one.
	 *
	 * @access private
	 * @param string|array $query_vars URL query string or array of vars.
	 */
	private function replace_query( $query_vars ) {
		global $wp_query, $wp_the_query;

		// Create an emtpy query.
		$new_query = new WP_Query();

		// Query elements using $query_vars.
		$new_query->query( $query_vars );

		// If $query_vars is empty, that means it's the home query.
		if ( empty( $query_vars ) ) {
			$new_query->is_home       = true;
			$new_query->is_posts_page = true;
		}

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $new_query;
		$wp_the_query = $new_query;
		// phpcs:enable

		// Allow 'redirect_canonical' action to receive arguments.
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_action( 'template_redirect', 'redirect_canonical', 10, 2 );

		// Init integrations.
		do_action( 'frontity_headtags_replace_query' );
	}

	/**
	 * Reset postdata and wp_query to previous values.
	 *
	 * @access private
	 */
	private function restore_query() {
		global $wp_query, $wp_the_query;
		$backup = array_pop( $this->query_backup );
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $backup['wp_query'];
		$wp_the_query = $backup['wp_the_query'];
		// phpcs:enable
		wp_reset_postdata();

		// Reset 'redirect_canonical' action.
		remove_action( 'template_redirect', 'redirect_canonical', 10, 2 );
		add_action( 'template_redirect', 'redirect_canonical' );

		// Reset integrations.
		do_action( 'frontity_headtags_restore_query' );
	}
}
