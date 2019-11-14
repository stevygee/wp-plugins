<?php
/**
 * File description.
 *
 * @package Frontity_Headtags
 */

/**
 * Class that contains the main behavior.
 */
class Frontity_Headtags {
	/**
	 * Prefix used in transients and hooks.
	 *
	 * @access  private
	 * @var     string
	 */
	private $prefix = 'frontity_headtags_';

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
		// return get_transient( "{$this->prefix}{$key}" );
		return false; // Ignore cache.
	}

	/**
	 * Set cached head tags for this key.
	 *
	 * @param string      $key Transient key.
	 * @param array|mixed $headtags Head tags to cache.
	 * @return bool
	 */
	public function set_cached_headtags( $key, $headtags ) {
		return set_transient( "{$this->prefix}{$key}", $headtags, MONTH_IN_SECONDS );
	}

	/**
	 * Set cached head tags for this key.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	public function delete_cached_headtags( $key ) {
		return delete_transient( "{$this->prefix}{$key}" );
	}

	/**
	 * Fetch yoast meta and possibly json ld and store in transient if needed
	 *
	 * @param string       $key Transient key.
	 * @param string|array $query URL query string or array of vars.
	 * @return array|mixed
	 */
	public function get_headtags( $key, $query ) {
		// Get head tags from transients.
		$headtags = $this->get_cached_headtags( $key );

		// If head tags are not cached, compute and cache them.
		if ( empty( $headtags ) ) {
			$headtags = $this->compute_headtags( $query );
			$this->set_cached_headtags( $key, $headtags );
		}
		// Return the head tags obtained.
		return $headtags;
	}

	/**
	 * Fetch yoast meta and possibly json ld and store in transient if needed
	 *
	 * @param string|array $query_vars URL query string or array of vars.
	 * @return array
	 */
	public function compute_headtags( $query_vars ) {
		$this->backup_query();
		$this->replace_query( $query_vars );

		ob_start();
		do_action( 'wp_head' );
		$html = ob_get_clean();
		$html = html_entity_decode( $html ); // Replaces &hellip; to ...
		$html = preg_replace( '/&(?!#?[a-z0-9]+;)/', '&amp;', $html ); // Replaces & to '&amp;.

		// Parse the xml to create an array of meta items.
		$headtags = $this->parse( $html );

		// Filter not desired head tags.
		$headtags = apply_filters( "{$this->prefix}result", $headtags );

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
					$head_tag['attributes'][ $attr->nodeName ] = $attr->nodeValue;
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

		// Init integrations.
		do_action( "{$this->prefix}replace_query" );
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

		// Reset integrations.
		do_action( "{$this->prefix}restore_query" );
	}
}
