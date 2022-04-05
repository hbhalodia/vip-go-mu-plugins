<?php

/**
 * This is a list of performance tweaks that will become default for All VIP sites
 */
add_action( 'after_setup_theme', 'wpcom_vip_enable_performance_tweaks' );
function wpcom_vip_enable_performance_tweaks() {
	// Disables the adjacent_post links in the header that are almost never beneficial and are very slow to compute.
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0 );

	if ( function_exists( 'wpcom_vip_enable_old_slug_redirect_caching' ) ) {
		wpcom_vip_enable_old_slug_redirect_caching();
	}

	if ( function_exists( 'wpcom_vip_enable_maybe_skip_old_slug_redirect' ) ) {
		wpcom_vip_enable_maybe_skip_old_slug_redirect();
	}

	if ( is_admin() ) {
		// Cache the available months for filtering on posts/attachments/CPTs.
		add_filter( 'media_library_months_with_files', 'wpcom_vip_media_library_months_with_files' );
		add_filter( 'pre_months_dropdown_query', 'wpcom_vip_available_post_listing_months', 10, 2 );
	}

	// Busts the month's filtering caches when needed for both attachments and posts/CPTs.
	add_action( 'add_attachment', 'wpcom_vip_maybe_bust_available_months_cache' );
	add_action( 'save_post', 'wpcom_vip_maybe_bust_available_months_cache' );

	// This disables and replaces the SQL_CALC_FOUND_ROWS call that Core makes with a more performant `count` query
	add_filter( 'pre_get_posts', 'wpcom_vip_set_no_found_rows', 10, 1 );
	add_filter( 'posts_clauses', 'wpcom_vip_set_found_post_value', 10, 2 );
}


function wpcom_vip_is_no_found_rows_disabled() {
	if ( ! apply_filters( 'wpcom_vip_no_found_rows_disable', true ) ) {
		return false;
	}
 
	return true;
}

/**
 * Use this function to disable the loading of performance tweaks
 */
function wpcom_vip_disable_performance_tweaks() {
	remove_action( 'after_setup_theme', 'wpcom_vip_enable_performance_tweaks' );
}

if ( ! function_exists( 'wpcom_vip_set_no_found_rows' ) ) {
	/**
	 * In the WP_Query class this stops the use of SQL_CALC_FOUND_ROWS in the MySQL query it generates
	 */
	function wpcom_vip_set_no_found_rows( \WP_Query $wp_query ) {
		if ( ! wpcom_vip_is_no_found_rows_disabled() ) {
			return false;
		}
		
		$wp_query->set( 'no_found_rows', true );
	}
}

if ( ! function_exists( 'wpcom_vip_set_found_post_value' ) ) {
	/**
	 * Utilises query clauses to reconstruct a replacement for the SQL_CALC_FOUND_ROWS which is disabled via wpcom_vip_set_no_found_rows 
	 * Then constructing and returning the pagination rules
	 */
	function wpcom_vip_set_found_post_value( $clauses, \WP_Query $wp_query ) {
		if ( ! wpcom_vip_is_no_found_rows_disabled() ) {
			return false;
		}
		// Don't proceed if it's a singular page.
		if ( $wp_query->is_singular()  ) {
			return $clauses;
		}
	
		if ( ! wpcom_vip_is_no_found_rows_disabled() ) {
			return false;
		}

		global $wpdb;
	
		// Check if any clauses are set.
		$where = isset( $clauses[ 'where' ] ) ? $clauses[ 'where' ] : '';
		$join = isset( $clauses[ 'join' ] ) ? $clauses[ 'join' ] : '';
		$distinct = isset( $clauses[ 'distinct' ] ) ? $clauses[ 'distinct' ] : '';
	
		//Replace the SQL_CALC_FOUND_ROWS query with a count call https://dev.mysql.com/doc/refman/8.0/en/information-functions.html#function_found-rows
		$wp_query->found_posts = $wpdb->get_var( "SELECT $distinct COUNT(*) FROM {$wpdb->posts} $join WHERE 1=1 $where" );
	
		// Work out how many posts per page there should be.
		$posts_per_page = ( ! empty( $wp_query->query_vars['posts_per_page'] ) ? absint( $wp_query->query_vars['posts_per_page'] ) : absint( get_option( 'posts_per_page' ) ) );
	
		// Set the max_num_pages.
		$wp_query->max_num_pages = ceil( $wp_query->found_posts / $posts_per_page );
	
		// Return the $clauses so the main query can run.
		return $clauses;
	}
}

/**
 * Caches an expensive query used to generate the available
 * months/years used in filters in the media library.
 *
 * @param object[]|null $months
 * @return object[]
 */
function wpcom_vip_media_library_months_with_files( $months ) {
	if ( null !== $months ) {
		// Something is already filtering, abort.
		return $months;
	}

	return wpcom_vip_get_available_months_for_filters( 'attachment' );
}

/**
 * Caches an expensive query used to generate the available
 * months/years used in filters on posts/CPTs admin pages.
 *
 * @param object[]|false $months
 * @param string         $post_type
 * @return object[]|false
 */
function wpcom_vip_available_post_listing_months( $months, $post_type ) {
	if ( false !== $months ) {
		// Something is already filtering, abort.
		return $months;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['post_status'] ) ) {
		// Avoid interferring if user filtered by a particular post status.
		return false;
	}

	return wpcom_vip_get_available_months_for_filters( $post_type );
}

/**
 * Query wrapper that caches the results of available months given a particular post type.
 *
 * See WP_List_Table::months_dropdown() & wp_enqueue_media() in WP core.
 *
 * @param string $post_type
 * @return object[]
 */
function wpcom_vip_get_available_months_for_filters( $post_type ) {
	global $wpdb;

	$cache_key = "available_filter_months_$post_type";
	$months    = wp_cache_get( $cache_key, 'vip' );
	if ( is_array( $months ) ) {
		// Happiest-path, cache exists already :).
		return $months;
	}

	$extra_checks = '';
	if ( 'attachment' !== $post_type ) {
		$extra_checks = " AND post_status != 'auto-draft' AND post_status != 'trash'";
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$months = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
			FROM $wpdb->posts
			WHERE post_type = %s
			$extra_checks
			ORDER BY post_date DESC
			",
			$post_type
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	wp_cache_set( $cache_key, $months, 'vip', DAY_IN_SECONDS );
	return $months;
}

/**
 * Helper method to bust the "available months" cache when needed.
 *
 * Note that we only bust the cache when a new month is added,
 * For deletions, we just let the 24-hour TTL handle that.
 *
 * @param string $post_type
 * @return object[]
 */
function wpcom_vip_maybe_bust_available_months_cache( $post_id ) {
	$post_type = get_post_type( $post_id );
	$cache_key = "available_filter_months_$post_type";

	$existing_months = wp_cache_get( $cache_key, 'vip' );
	if ( ! is_array( $existing_months ) ) {
		// No cache to bust.
		return false;
	}

	$post_year  = get_the_time( 'Y', $post_id );
	$post_month = get_the_time( 'n', $post_id );

	$found = false;
	foreach ( $existing_months as $date ) {
		if ( $post_year === $date->year && $post_month === $date->month ) {
			$found = true;
			break;
		}
	}

	if ( ! $found ) {
		// The month/year doesn't exist yet, so just bust the cache and let it re-generate.
		wp_cache_delete( $cache_key, 'vip' );
	}
}
