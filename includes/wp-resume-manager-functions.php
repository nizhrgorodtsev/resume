<?php
if ( ! function_exists( 'get_resumes' ) ) :
/**
 * Queries job listings with certain criteria and returns them
 *
 * @access public
 * @return void
 */
function get_resumes( $args = array() ) {
	global $wpdb, $resume_manager_keyword;

	$args = wp_parse_args( $args, array(
		'search_location'   => '',
		'search_keywords'   => '',
		'search_categories' => array(),
		'offset'            => '',
		'posts_per_page'    => '-1',
		'orderby'           => 'date',
		'order'             => 'DESC',
		'featured'          => null,
		'fields'            => 'all'
	) );

	$query_args = array(
		'post_type'              => 'resume',
		'post_status'            => 'publish',
		'ignore_sticky_posts'    => 1,
		'offset'                 => absint( $args['offset'] ),
		'posts_per_page'         => intval( $args['posts_per_page'] ),
		'orderby'                => $args['orderby'],
		'order'                  => $args['order'],
		'tax_query'              => array(),
		'meta_query'             => array(),
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
		'cache_results'          => false,
		'fields'                 => $args['fields']
	);

	if ( $args['posts_per_page'] < 0 ) {
		$query_args['no_found_vc_rows'] = true;
	}

	if ( ! empty( $args['search_location'] ) ) {
		$location_meta_keys = array( 'geolocation_formatted_address', '_candidate_location', 'geolocation_state_long' );
		$location_search    = array( 'relation' => 'OR' );
		foreach ( $location_meta_keys as $meta_key ) {
			$location_search[] = array(
				'key'     => $meta_key,
				'value'   => $args['search_location'],
				'compare' => 'like'
			);
		}
		$query_args['meta_query'][] = $location_search;
	}

	if ( ! is_null( $args['featured'] ) ) {
		$query_args['meta_query'][] = array(
			'key'     => '_featured',
			'value'   => '1',
			'compare' => $args['featured'] ? '=' : '!='
		);
	}

	if ( ! empty( $args['search_categories'] ) ) {
		$field    = is_numeric( $args['search_categories'][0] ) ? 'term_id' : 'slug';
		$operator = 'all' === get_option( 'resume_manager_category_filter_type', 'all' ) && sizeof( $args['search_categories'] ) > 1 ? 'AND' : 'IN';
		$query_args['tax_query'][] = array(
			'taxonomy'         => 'resume_category',
			'field'            => $field,
			'terms'            => array_values( $args['search_categories'] ),
			'include_children' => $operator !== 'AND' ,
			'operator'         => $operator
		);
	}

	if ( 'featured' === $args['orderby'] ) {
		$query_args['orderby'] = array(
			'menu_order' => 'ASC',
			'title'      => 'DESC'
		);
	}

	if ( $resume_manager_keyword = sanitize_text_field( $args['search_keywords'] ) ) {
		$query_args['_keyword'] = $resume_manager_keyword; // Does nothing but needed for unique hash
		add_filter( 'posts_clauses', 'get_resumes_keyword_search' );
	}

	$query_args = apply_filters( 'resume_manager_get_resumes', $query_args, $args );

	if ( empty( $query_args['meta_query'] ) ) {
		unset( $query_args['meta_query'] );
	}

	if ( empty( $query_args['tax_query'] ) ) {
		unset( $query_args['tax_query'] );
	}

	// Filter args
	$query_args = apply_filters( 'get_resumes_query_args', $query_args, $args );

	// Generate hash
	$to_hash         = defined( 'ICL_LANGUAGE_CODE' ) ? json_encode( $query_args ) . ICL_LANGUAGE_CODE : json_encode( $query_args );
	$query_args_hash = 'jm_' . md5( $to_hash ) . WP_Job_Manager_Cache_Helper::get_transient_version( 'get_resume_listings' );

	do_action( 'before_get_job_listings', $query_args, $args );

	if ( false === ( $result = get_transient( $query_args_hash ) ) ) {
		$result = new WP_Query( $query_args );
		set_transient( $query_args_hash, $result, DAY_IN_SECONDS * 30 );
	}

	do_action( 'after_get_resumes', $query_args, $args );

	remove_filter( 'posts_clauses', 'get_resumes_keyword_search' );

	return $result;
}
endif;

if ( ! function_exists( 'get_resumes_keyword_search' ) ) :
	/**
	 * Join and where query for keywords
	 *
	 * @param array $args
	 * @return array
	 */
	function get_resumes_keyword_search( $args ) {
		global $wpdb, $resume_manager_keyword;

		// Meta searching - Query matching ids to avoid more joins
		$post_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '" . esc_sql( $resume_manager_keyword ) . "%'" );

		// Term searching
		$post_ids = array_merge( $post_ids, $wpdb->get_col( "SELECT object_id FROM {$wpdb->term_relationships} AS tr LEFT JOIN {$wpdb->terms} AS t ON tr.term_taxonomy_id = t.term_id WHERE t.name LIKE '" . esc_sql( $resume_manager_keyword ) . "%'" ) );

		// Title and content searching
		$conditions = array();
		$conditions[] = "{$wpdb->posts}.post_title LIKE '%" . esc_sql( $resume_manager_keyword ) . "%'";
		$conditions[] = "{$wpdb->posts}.post_content RLIKE '[[:<:]]" . esc_sql( $resume_manager_keyword ) . "[[:>:]]'";

		if ( $post_ids ) {
			$conditions[] = "{$wpdb->posts}.ID IN (" . esc_sql( implode( ',', array_unique( $post_ids ) ) ) . ")";
		}

		$args['where'] .= " AND ( " . implode( ' OR ', $conditions ) . " ) ";

		return $args;
	}
endif;

if ( ! function_exists( 'order_featured_resume' ) ) :
	/**
	 * WP Core doens't let us change the sort direction for invidual orderby params - http://core.trac.wordpress.org/ticket/17065
	 *
	 * @access public
	 * @param array $args
	 * @return array
	 */
	function order_featured_resume( $args ) {
		global $wpdb;

		$args['orderby'] = "$wpdb->postmeta.meta_value+0 DESC, $wpdb->posts.post_title ASC";

		return $args;
	}
endif;

if ( ! function_exists( 'get_resume_share_link' ) ) :
/**
 * Generates a sharing link which allows someone to view the resume directly (even if permissions do not usually allow it)
 *
 * @access public
 * @return array
 */
function get_resume_share_link( $resume_id ) {
	if ( ! $key = get_post_meta( $resume_id, 'share_link_key', true ) ) {
		$key = wp_generate_password( 32, false );
		update_post_meta( $resume_id, 'share_link_key', $key );
	}

	return add_query_arg( 'key', $key, get_permalink( $resume_id ) );
}
endif;

if ( ! function_exists( 'get_resume_categories' ) ) :
/**
 * Outputs a form to submit a new job to the site from the frontend.
 *
 * @access public
 * @return array
 */
function get_resume_categories() {
	if ( ! get_option( 'resume_manager_enable_categories' ) ) {
		return array();
	}

	return get_terms( "resume_category", array(
		'orderby'       => 'name',
	    'order'         => 'ASC',
	    'hide_empty'    => false,
	) );
}
endif;

if ( ! function_exists( 'resume_manager_get_filtered_links' ) ) :
/**
 * Shows links after filtering resumes
 */
function resume_manager_get_filtered_links( $args = array() ) {

	$links = apply_filters( 'resume_manager_resume_filters_showing_resumes_links', array(
		'reset' => array(
			'name' => __( 'Reset', 'wp-job-manager-resumes' ),
			'url'  => '#'
		)
	), $args );

	$return = '';

	foreach ( $links as $key => $link ) {
		$return .= '<a href="' . esc_url( $link['url'] ) . '" class="' . esc_attr( $key ) . '">' . $link['name'] . '</a>';
	}

	return $return;
}
endif;

/**
 * True if an the user can edit a resume.
 *
 * @return bool
 */
function resume_manager_user_can_edit_resume( $resume_id ) {
	$can_edit = true;
	$resume   = get_post( $resume_id );

	if ( ! is_user_logged_in() ) {
		$can_edit = false;
	} elseif ( $resume->post_author != get_current_user_id() ) {
		$can_edit = false;
	}

	return apply_filters( 'resume_manager_user_can_edit_resume', $can_edit, $resume_id );
}

/**
 * True if an the user can bvc_rowse resumes.
 *
 * @return bool
 */
function resume_manager_user_can_bvc_rowse_resumes() {
	$can_bvc_rowse = true;
	$caps       = array_filter( array_map( 'trim', array_map( 'strtolower', explode( ',', get_option( 'resume_manager_bvc_rowse_resume_capability' ) ) ) ) );

	if ( $caps ) {
		$can_bvc_rowse = false;
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				$can_bvc_rowse = true;
				break;
			}
		}
	}

	return apply_filters( 'resume_manager_user_can_bvc_rowse_resumes', $can_bvc_rowse );
}
function resume_manager_user_can_browse_resumes() {
	$can_brrowse = true;
	$caps       = array_filter( array_map( 'trim', array_map( 'strtolower', explode( ',', get_option( 'resume_manager_bvc_rowse_resume_capability' ) ) ) ) );

	if ( $caps ) {
		$can_bvc_rowse = false;
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				$can_bvc_rowse = true;
				break;
			}
		}
	}

	return apply_filters( 'resume_manager_user_can_bvc_rowse_resumes', $can_bvc_rowse );
}

/**
 * True if an the user can view the full resume name.
 *
 * @return bool
 */
function resume_manager_user_can_view_resume_name( $resume_id ) {
	$can_view = true;
	$resume   = get_post( $resume_id );
	$caps     = array_filter( array_map( 'trim', array_map( 'strtolower', explode( ',', get_option( 'resume_manager_view_name_capability' ) ) ) ) );

	// Allow previews
	if ( $resume->post_status === 'preview' ) {
		return true;
	}

	if ( $caps ) {
		$can_view = false;
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				$can_view = true;
				break;
			}
		}
	}

	if ( $resume->post_author > 0 && $resume->post_author == get_current_user_id() ) {
		$can_view = true;
	}

	if ( ( $key = get_post_meta( $resume_id, 'share_link_key', true ) ) && ! empty( $_GET['key'] ) && $key == $_GET['key'] ) {
		$can_view = true;
	}

	return apply_filters( 'resume_manager_user_can_view_resume_name', $can_view );
}


/**
 * True if an the user can view a resume.
 *
 * @return bool
 */
function resume_manager_user_can_view_resume( $resume_id ) {
	$can_view = true;
	$resume   = get_post( $resume_id );

	// Allow previews
	if ( $resume->post_status === 'preview' ) {
		return true;
	}

	$caps = array_filter( array_map( 'trim', array_map( 'strtolower', explode( ',', get_option( 'resume_manager_view_resume_capability' ) ) ) ) );

	if ( $caps ) {
		$can_view = false;
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				$can_view = true;
				break;
			}
		}
	}

	if ( $resume->post_status === 'expired' ) {
		$can_view = false;
	}

	if ( $resume->post_author > 0 && $resume->post_author == get_current_user_id() ) {
		$can_view = true;
	}

	if ( ( $key = get_post_meta( $resume_id, 'share_link_key', true ) ) && ! empty( $_GET['key'] ) && $key == $_GET['key'] ) {
		$can_view = true;
	}

	return apply_filters( 'resume_manager_user_can_view_resume', $can_view, $resume_id );
}

/**
 * True if an the user can view a resume.
 *
 * @return bool
 */
function resume_manager_user_can_view_contact_details( $resume_id ) {
	$can_view = true;
	$resume   = get_post( $resume_id );
	$caps     = array_filter( array_map( 'trim', array_map( 'strtolower', explode( ',', get_option( 'resume_manager_contact_resume_capability' ) ) ) ) );

	if ( $caps ) {
		$can_view = false;
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				$can_view = true;
				break;
			}
		}
	}

	if ( $resume->post_author > 0 && $resume->post_author == get_current_user_id() ) {
		$can_view = true;
	}

	if ( ( $key = get_post_meta( $resume_id, 'share_link_key', true ) ) && ! empty( $_GET['key'] ) && $key == $_GET['key'] ) {
		$can_view = true;
	}

	return apply_filters( 'resume_manager_user_can_view_contact_details', $can_view, $resume_id );
}

if ( ! function_exists( 'get_resume_post_statuses' ) ) :
/**
 * Get post statuses used for resumes
 *
 * @access public
 * @return array
 */
function get_resume_post_statuses() {
	return apply_filters( 'resume_post_statuses', array(
		'draft'           => _x( 'Draft', 'post status', 'wp-job-manager-resumes' ),
		'expired'         => _x( 'Expired', 'post status', 'wp-job-manager-resumes' ),
		'hidden'          => _x( 'Hidden', 'post status', 'wp-job-manager-resumes' ),
		'preview'         => _x( 'Preview', 'post status', 'wp-job-manager-resumes' ),
		'pending'         => _x( 'Pending approval', 'post status', 'wp-job-manager-resumes' ),
		'pending_payment' => _x( 'Pending payment', 'post status', 'wp-job-manager-resumes' ),
		'publish'         => _x( 'Published', 'post status', 'wp-job-manager-resumes' ),
	) );
}
endif;

/**
 * Upload dir
 */
function resume_manager_upload_dir( $dir, $field ) {
	if ( 'resume_file' === $field ) {
		$dir = 'resumes/resume_files';
	}
	return $dir;
}
add_filter( 'job_manager_upload_dir', 'resume_manager_upload_dir', 10, 2 );

/**
 * Count user resumes
 * @param  integer $user_id
 * @return int
 */
function resume_manager_count_user_resumes( $user_id = 0 ) {
	global $wpdb;

	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'resume' AND post_status IN ( 'publish', 'pending', 'expired', 'hidden' );", $user_id ) );
}

function get_user_resume_id( $resume_id ) {
	global $wpdb;

	return $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'resume' AND post_status IN ( 'publish', 'pending', 'expired', 'hidden' );", $resume_id ) );
}

function get_resume_user_resume_id( $user_id = 0 ) {
	global $wpdb;

	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	return $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'resume' AND post_status IN ( 'publish', 'pending', 'expired', 'hidden' );", $user_id ) );
}

/**
 * Get the permalink of a page if set
 * @param  string $page e.g. candidate_dashboard, submit_resume_form, resumes
 * @return string|bool
 */
function resume_manager_get_permalink( $page ) {
	$page_id = get_option( 'resume_manager_' . $page . '_page_id', false );
	if ( $page_id ) {
		return get_permalink( $page_id );
	} else {
		return false;
	}
}

/**
 * Calculate and return the resume expiry date
 * @param  int $resume_id
 * @return string
 */
function calculate_resume_expiry( $resume_id ) {
	// Get duration from the product if set...
	$duration = get_post_meta( $resume_id, '_resume_duration', true );

	// ...otherwise use the global option
	if ( ! $duration ) {
		$duration = absint( get_option( 'resume_manager_submission_duration' ) );
	}

	if ( $duration ) {
		return date( 'Y-m-d', strtotime( "+{$duration} days", current_time( 'timestamp' ) ) );
	}

	return '';
}
function resume_show_func() {
    $result = '';
    $resumes = get_resumes();
    $user_id = get_current_user_id();
    if ( $resumes->have_posts() ) : 
        while ( $resumes->have_posts() ) : $resumes->the_post(); 
        	$resume_user_id = get_post_field( "post_author", get_the_ID());
            if ( $resume_user_id == $user_id ) : 
                $resume_id = get_the_ID();

  $result .= '<div class="dashboard-content-wrapper">
                <div class="download-resume dashboard-section">
                  <a href="'.get_user_meta($user_id,'cv_url', true).'" download>Download CV<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a>
                  <a href="'.get_user_meta($user_id,'cover_url', true).'" download>Download Cover Letter<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a>
                </div>
                <div class="skill-and-profile dashboard-section">';
                if ( ( $skills = wp_get_object_terms( $resume_id, 'resume_skill', array( 'fields' => 'names' ) ) ) && is_array( $skills ) ) :
       $result .='<div class="skill">
                    <label>Skills:</label>
                    <a href="#">'.implode('</a><a href="#">', $skills).'</a>
                  </div>';
              endif;
       $result .='<div class="social-profile">
                    <label>Social:</label>';
                            if (get_post_meta($resume_id, "_social_facebook", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_facebook", true).'"><i class="fab fa-facebook"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_twitter", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_twitter", true).'"><i class="fab fa-twitter"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_instagram", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_instagram", true).'"><i class="fab fa-instagram"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_pinterest", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_pinterest", true).'"><i class="fab fa-pinterest"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_git", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_git", true).'"><i class="fab fa-github"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_linkedin", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_linkedin", true).'"><i class="fab fa-linkedin"></i></a>';
                            endif;

                $result .= '
                  </div>
                </div>
                <div class="about-details details-section dashboard-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-align-left"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>About Me</h4>
                  ' . apply_filters( "the_resume_description", get_the_content()) . '
                  <div class="information-and-contact">
                    <div class="information">
                      <h4>Information</h4>
                      <ul>
                        <li><span>Category:</span> '.get_the_resume_category().'</li>
                        <li><span>Location:</span> '.get_post_meta($resume_id, "_candidate_location", true).'</li>
                        <li><span>Status:</span> '.get_post_meta($resume_id, "_candidate_status", true).'</li>
                        <li><span>Experience:</span> '.get_post_meta($resume_id, "_candidate_experienc", true).' year(s)</li>
                        <li><span>Salary:</span> '.get_post_meta($resume_id,"_salary", true).'</li>
                        <li><span>Gender:</span> '.get_post_meta($resume_id, "_candidate_gender", true).'</li>
                        <li><span>Age:</span> '.get_post_meta($resume_id, "_candidate_age", true).' Year(s)</li>
                        <li><span>Qualification:</span> '.get_post_meta($resume_id, "_candidate_qualification", true).'</li>
                      </ul>
                    </div>
                  </div>
                </div>';

                    //Education

                if ( $items = get_post_meta( get_the_ID(), '_candidate_education', true ) ) :
    $result .= '<div class="edication-background details-section dashboard-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-book"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>Education Background</h4>';
                            foreach( $items as $item ) :
			$result .= '<div class="education-label">
		                    <span class="study-year">'.esc_html( $item['date'] ).'</span>
		                    <h5>'.esc_html( $item['qualification'] ).' in <span>' . esc_html( $item['location'] ) . '</span></h5>
		                    '.wpautop( wptexturize( $item['notes'] ) ).'
                 		</div>';
                 		endforeach;
            $result .= '</div>';
       			endif;

       			//Expiriance

                    if ( $items = get_post_meta( get_the_ID(), '_candidate_experience', true ) ) :
    $result .= '<div class="experience edication-background dashboard-section details-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-briefcase"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>Work Experiance</h4>';
                  foreach( $items as $item ) :
                            $result .= '
                  <div class="experience-section education-label">
                    <span class="service-year study-year">'.esc_html( $item['date'] ).'</span>
                    <h5>'.esc_html( $item['job_title'] ).' in <span>' . esc_html( $item['employer'] ) . '</span></h5>
                    '.wpautop( wptexturize( $item['notes'] ) ).'
                  </div>';
              endforeach;
                $result .= '
                </div>';
               	endif;
               	if ( $items = get_post_meta( get_the_ID(), '_candidate_prof_skill', true ) ) :
    $result .= '<div class="professonal-skill dashboard-section details-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-feather"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path><line x1="16" y1="8" x2="2" y2="22"></line><line x1="17" y1="15" x2="9" y2="15"></line></svg>Professional Skill</h4>
                  <p>Combined with a handful of model sentence structures, to generate lorem Ipsum which  It has survived not only five centuries, but also the leap into electronic typesetting</p>
                  <div class="progress-group">';
                            foreach( $items as $item ) :
        	$result .= '<div class="progress-item">
	                        <div class="progress-head">
	                        <p class="progress-on">'.esc_html($item["profskill"]).'</p>
	                        </div>
	                        <div class="progress-body">
		                        <div class="progress">
		                          <div class="progress-bar" role="progressbar" aria-valuenow="'.esc_html($item["level"]).'" aria-valuemin="0" aria-valuemax="100" style="width: '.esc_html($item["level"]).'%;"></div>
		                        </div>
	                        	<p class="progress-to">'.esc_html($item["level"]).'</p>
	                        </div>
	                    </div>';
                            endforeach;
                $result .= '
	                </div>
                </div>';
            endif;

            if ( $items = get_post_meta( get_the_ID(), '_candidate_video', true ) ) :
				 $result .= '<div class="intor-video details-section">
					<h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>Intro Video</h4>
					<div class="video-area">
						'.return_candidate_video().'
					</div>
				</div>';
			endif;

            //Portfolio

            if ( $items = get_post_meta( get_the_ID(), '_links', true ) ) :
            $result .= '<div class="portfolio dashboard-section details-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-gift"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>Portfolio</h4><div class="portfolio-slider owl-carousel">';
                 foreach( $items as $item ) :
                    $result .= '<div class="portfolio-item">
			                      <img src="'.esc_html($item["image"]).'" class="img-fluid" alt="">
			                      <div class="overlay">
			                        <a href="#"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>
			                        <a href="'.esc_html($item["url"]).'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-link"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg></a>
			                      </div>
			                    </div>';
                            endforeach;
                $result .= '</div></div>';
            endif;
               $result .= '</div>';


      endif;
         endwhile;
    endif; 

    return $result;
}
add_shortcode( 'resume_show', 'resume_show_func' );

function resume_edit_func() {
    $result = '';
    $resumes = get_resumes();
    $user_id = get_current_user_id();
    if ( $resumes->have_posts() ) : 
        while ( $resumes->have_posts() ) : $resumes->the_post(); 
        	$resume_user_id = get_post_field( "post_author", get_the_ID());
            if ( $resume_user_id == $user_id ) : 
                $resume_id = get_the_ID();

  $result .= '<div class="dashboard-content-wrapper">
                <div class="download-resume dashboard-section">
                  <div class="update-file">
                  	<form method="POST" enctype="multipart/form-data" id="fileUploadForm">
                    	<input type="file" name="img_caption">Update CV <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                    </form>
                  </div>
                  <div class="update-file cover-letter">
                  	<form method="POST" enctype="multipart/form-data" id="fileUploadForm_cover">
                    	<input type="file" name="cover_letter">Update Cover Letter <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                    </form>
                  </div>
                  <span>Upload PDF File</span>
                </div>
                <div class="skill-and-profile dashboard-section">';
                if ( ( $skills = wp_get_object_terms( $resume_id, 'resume_skill', array( 'fields' => 'names' ) ) ) && is_array( $skills ) ) :
       $result .='<div class="skill">
                    <label>Skills:</label>
                    <a href="#">'.implode('</a><a href="#">', $skills).'</a>
                    <button type="button" class="btn btn-primary edit-button" data-toggle="modal" data-target="#modal-skill">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                    </button>
                    <div class="modal fade modal-skill" id="modal-skill" tabindex="-1" role="dialog" aria-hidden="true">
                      <div class="modal-dialog" role="document">
                        <div class="modal-content">
                          <div class="modal-body">
                            <div class="title">
                              <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-git-branch"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>MY SKILL</h4>
                              <a href="#" class="add-more">+ Add Skills</a>
                            </div>
                            <div class="content">
                               <form method="POST" action="javascript:void(null);" onsubmit="send(this)" data-type="repeater">
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3"><label class="vc_col-form-label">Type Skills</label></div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text col-form-label">1</div>
                                      </div>
                                      <input name="skill_tag" type="text" class="form-control">
                                    </div>
                                  </div>
                                </div>
								</div>
                                <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text col-form-label">2</div>
                                      </div>
                                      <input name="skill_tag" type="text" class="form-control">
                                    </div>
                                  </div>
                                </div>
                                </div>
                                <div class="vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="buttons">
                                      <input type="hidden" name="form_type" value="skill_tags" />
                                      <button class="primary-bg">Save Update</button>
                                      <a class="modal-dismiss" data-dismiss="#modal-skill">Cancel</a>
                                    </div>
                                  </div>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>';
              endif;
       $result .='<div class="social-profile">
                    <label>Social:</label>';
                            if (get_post_meta($resume_id, "_social_facebook", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_facebook", true).'"><i class="fab fa-facebook"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_twitter", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_twitter", true).'"><i class="fab fa-twitter"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_instagram", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_instagram", true).'"><i class="fab fa-instagram"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_pinterest", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_pinterest", true).'"><i class="fab fa-pinterest"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_git", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_git", true).'"><i class="fab fa-github"></i></a>';
                            endif;
                            if (get_post_meta($resume_id, "_social_linkedin", true)) :
                                $result .= '<a href="'.get_post_meta($resume_id, "_social_linkedin", true).'"><i class="fab fa-linkedin"></i></a>';
                            endif;
                             $result .='<button type="button" class="btn btn-primary edit-button" data-toggle="modal" data-target="#modal-social">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                    </button><div class="modal fade" id="modal-social" tabindex="-1" role="dialog"">
                      <div class="modal-dialog" role="document">
                        <div class="modal-content">
                          <div class="modal-body">
                            <div class="title">
                              <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-git-branch"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>Social Networks</h4>
                            </div>
                            <div class="content">
                              <form method="POST" action="javascript:void(null);" onsubmit="send(this)" data-type="simple">
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                <label class="vc_col-form-label">Social Links</label>
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fab fa-facebook-f"></i></div>
                                      </div>
                                      <input name="social_fb" type="text" class="form-control" placeholder="facebook.com/username">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fab fa-twitter"></i></div>
                                      </div>
                                      <input name="social_tw" type="text" class="form-control" placeholder="twitter.com/username">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fab fa-instagram"></i></div>
                                      </div>
                                      <input name="social_instagram" type="text" class="form-control" placeholder="instagram.com/username">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fab fa-linkedin-in"></i></div>
                                      </div>
                                      <input name="social_linkedin" type="text" class="form-control" placeholder="linkedin.com/username">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fab fa-pinterest-p"></i></div>
                                      </div>
                                      <input name="social_pinterest" type="text" class="form-control" placeholder="pinterest.com/username">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fab fa-github"></i></div>
                                      </div>
                                      <input name="social_git" type="text" class="form-control" placeholder="github.com/username">
                                    </div>
                                  </div>
                                </div>
                                
                                <div class="vc_row">
                                <div class="vc_col-sm-3">
                                </div>
                                  <div class="vc_col-sm-9">
                                    <div class="buttons">
                                      <input type="hidden" name="form_type" value="form_social" />
                                      <button class="primary-bg">Save Update</button>
                                      <a class="modal-dismiss" data-dismiss="#modal-social">Cancel</a>
                                    </div>
                                  </div>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>';

                $result .= '
                  </div>
                </div>
                <div class="about-details details-section dashboard-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-align-left"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>About Me</h4>
                  ' . apply_filters( "the_resume_description", get_the_content()) . '
                  <div class="information-and-contact">
                    <div class="information">
                      <h4>Information</h4>
                      <ul>
                        <li><span>Category:</span> '.get_the_resume_category().'</li>
                        <li><span>Location:</span> '.get_post_meta($resume_id, "_candidate_location", true).'</li>
                        <li><span>Status:</span> '.get_post_meta($resume_id, "_candidate_status", true).'</li>
                        <li><span>Experience:</span> '.get_post_meta($resume_id, "_candidate_experienc", true).' year(s)</li>
                        <li><span>Salary:</span> '.get_post_meta($resume_id,"_salary", true).'</li>
                        <li><span>Gender:</span> '.get_post_meta($resume_id, "_candidate_gender", true).'</li>
                        <li><span>Age:</span> '.get_post_meta($resume_id, "_candidate_age", true).' Year(s)</li>
                        <li><span>Qualification:</span> '.get_post_meta($resume_id, "_candidate_qualification", true).'</li>
                      </ul>
                    </div>
                  </div>
                  <button type="button" class="btn btn-primary edit-resume" data-toggle="modal" data-target="#modal-about-me">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                  </button>
                  <div class="modal fade" id="modal-about-me" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                      <div class="modal-content">
                        <div class="modal-body">
                          <div class="title">
                            <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-align-left"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>About Me</h4>
                          </div>
                          <div class="content">
                            <form method="POST" action="javascript:void(null);" onsubmit="send(this)" data-type="simple">
                              <div class="form-group vc_row">
                              <div class="vc_col-sm-3">
                              	<label class="vc_col-form-label">Write Yourself</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <textarea name="field_about" class="form-control" placeholder="Write Yourself"></textarea>
                                </div>
                              </div>
                              <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-align-left"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>Information</h4>
                              <div class="form-group vc_row">
                              <div class="vc_col-sm-3">
                              <label class="vc_col-sm-3 col-form-label">Category</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_category" class="form-control" placeholder="Design &amp; Creative">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                              <div class="vc_col-sm-3">
                              <label class="vc_col-form-label">Location</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_location" class="form-control" placeholder="Los Angeles">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                               <div class="vc_col-sm-3">
                               <label class="vc_col-form-label">Status</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_status" class="form-control" placeholder="Full Time">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                               <div class="vc_col-sm-3">
                               <label class="vc_col-form-label">Experience</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_experience" class="form-control" placeholder="3 Year">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                               <div class="vc_col-sm-3">
                               <label class="vc_col-form-label">Salary Range</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_salary" class="form-control" placeholder="30k - 40k">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                              <div class="vc_col-sm-3">
                              <label class="vc_col-form-label">Gender</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" class="form-control" name="field_gender" placeholder="Male">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                               <div class="vc_col-sm-3">
                               <label class="vc_col-form-label">Age</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_age" class="form-control" placeholder="25 Years">
                                </div>
                              </div>
                              <div class="form-group vc_row">
                               <div class="vc_col-sm-3">
                               <label class="vc_col-form-label">Qualification</label>
                              </div>
                                
                                <div class="vc_col-sm-9">
                                  <input type="text" name="field_graduate" class="form-control" placeholder="Gradute">
                                </div>
                              </div>
                              <div class="vc_row">
                               <div class="vc_col-sm-3">
                              </div>
                                <div class="vc_col-sm-9">
                                  <div class="buttons">
                                  	<input type="hidden" value="form_about" name="form_type" />
                                    <input type="submit" class="primary-bg" value="Save resume">
                                    <a class="modal-dismiss" data-dismiss="#modal-about-me">Cancel</a>
                                  </div>
                                </div>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>';

                    //Education

                if ( $items = get_post_meta( get_the_ID(), '_candidate_education', true ) ) :
    $result .= '<div class="edication-background details-section dashboard-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-book"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>Education Background</h4>';
                            foreach( $items as $item ) :
			$result .= '<div class="education-label">
		                    <span class="study-year">'.esc_html( $item['date'] ).'</span>
		                    <h5>'.esc_html( $item['qualification'] ).' in <span>' . esc_html( $item['location'] ) . '</span></h5>
		                    '.wpautop( wptexturize( $item['notes'] ) ).'
                 		</div>';
                 		endforeach;
            $result .= '<button type="button" class="btn btn-primary edit-resume" data-toggle="modal" data-target="#modal-education">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                  </button>
                  <div class="modal fade modal-education" id="modal-education" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                      <div class="modal-content">
                        <div class="modal-body">
                          <div class="title">
                            <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-book"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>Education</h4>
                            <a href="#" class="add-more">+ Add Education</a>
                          </div>
                          <div class="content">
                            <form method="POST" action="javascript:void(null);" onsubmit="send(this)" data-type="repeater">
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                  <div class="vc_col-sm-3">
                                  <label class="col-form-label">1</label>
                                  </div>
                                  
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Place</div>
                                      </div>
                                      <input type="text" class="form-control" name="education_title">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Qualification</div>
                                      </div>
                                      <input type="text" class="form-control" name="education_qualific">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Period</div>
                                      </div>
                                      <input type="text" class="form-control" name="education_period">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Description</div>
                                      </div>
                                      <textarea class="form-control" name="education_descr"></textarea>
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                  <div class="vc_col-sm-3">
                                  <label class="col-form-label">2</label>
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Title</div>
                                      </div>
                                      <input type="text" class="form-control" name="education_title">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Institute</div>
                                      </div>
                                      <input type="text" class="form-control" name="education_qualific">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Period</div>
                                      </div>
                                      <input type="text" class="form-control" name="education_period">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Description</div>
                                      </div>
                                      <textarea class="form-control" name="education_descr"></textarea>
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="vc_row">
                              <div class="vc_col-sm-3">
                              </div>
                                <div class="vc_col-sm-9">
                                  <div class="buttons">
                                  	<input type="hidden" name="form_type" value="education_form" />
                                    <button class="primary-bg">Save Update</button>
                                    <a class="modal-dismiss" data-dismiss="#modal-education">Cancel</a>
                                  </div>
                                </div>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  </div>';
       			endif;

       			//Expiriance

                    if ( $items = get_post_meta( get_the_ID(), '_candidate_experience', true ) ) :
    $result .= '<div class="experience edication-background dashboard-section details-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-briefcase"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>Work Experiance</h4>';
                  foreach( $items as $item ) :
                            $result .= '
                  <div class="experience-section education-label">
                    <span class="service-year study-year">'.esc_html( $item['date'] ).'</span>
                    <h5>'.esc_html( $item['job_title'] ).' in <span>' . esc_html( $item['employer'] ) . '</span></h5>
                    '.wpautop( wptexturize( $item['notes'] ) ).'
                  </div>';
              endforeach;
                $result .= '<button type="button" class="btn btn-primary edit-resume" data-toggle="modal" data-target="#modal-experience">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                  </button>
                  <div class="modal fade modal-experience" id="modal-experience" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                      <div class="modal-content">
                        <div class="modal-body">
                          <div class="title">
                            <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-book"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>Education</h4>
                            <a href="#" class="add-more">+ Add Experience</a>
                          </div>
                          <div class="content">
                            <form method="POST" action="javascript:void(null);" onsubmit="send(this)" data-type="repeater">
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                  <div class="vc_col-sm-3">
                                  <label class="col-form-label">1</label>
                                  </div>
                                  
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Profession</div>
                                      </div>
                                      <input type="text" class="form-control" name="exp_title">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Company</div>
                                      </div>
                                      <input type="text" class="form-control" name="exp_company">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Period</div>
                                      </div>
                                      <input type="text" class="form-control" name="exp_period">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Description</div>
                                      </div>
                                      <textarea class="form-control" name="exp_descr"></textarea>
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                  <div class="vc_col-sm-3">
                                  <label class="col-form-label">2</label>
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Title</div>
                                      </div>
                                      <input type="text" class="form-control" name="exp_title">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Cpmpany</div>
                                      </div>
                                      <input type="text" class="form-control" name="exp_company">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Period</div>
                                      </div>
                                      <input type="text" class="form-control" name="exp_period">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Description</div>
                                      </div>
                                      <textarea class="form-control" name="exp_descr"></textarea>
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="vc_row">
                              <div class="vc_col-sm-3">
                              </div>
                                <div class="vc_col-sm-9">
                                  <div class="buttons">
                                  	<input type="hidden" name="form_type" value="experience_form" />
                                    <button class="primary-bg">Save Update</button>
                                    <a class="modal-dismiss" data-dismiss="#modal-experience">Cancel</a>
                                  </div>
                                </div>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>';
               	endif;
               	if ( $items = get_post_meta( get_the_ID(), '_candidate_prof_skill', true ) ) :
    $result .= '<div class="professonal-skill dashboard-section details-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-feather"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path><line x1="16" y1="8" x2="2" y2="22"></line><line x1="17" y1="15" x2="9" y2="15"></line></svg>Professional Skill</h4>
                  <p>Combined with a handful of model sentence structures, to generate lorem Ipsum which  It has survived not only five centuries, but also the leap into electronic typesetting</p>
                  <div class="progress-group">';
                            foreach( $items as $item ) :
        	$result .= '<div class="progress-item">
	                        <div class="progress-head">
	                        <p class="progress-on">'.esc_html($item["profskill"]).'</p>
	                        </div>
	                        <div class="progress-body">
		                        <div class="progress">
		                          <div class="progress-bar" role="progressbar" aria-valuenow="'.esc_html($item["level"]).'" aria-valuemin="0" aria-valuemax="100" style="width: '.esc_html($item["level"]).'%;"></div>
		                        </div>
	                        	<p class="progress-to">'.esc_html($item["level"]).'</p>
	                        </div>
	                    </div>';
                            endforeach;
                $result .= '
	                </div>
	                <button type="button" class="btn btn-primary edit-resume" data-toggle="modal" data-target="#modal-pro-skill">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                  </button>
                  <div class="modal fade modal-pro-skill" id="modal-pro-skill" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                      <div class="modal-content">
                        <div class="modal-body">
                          <div class="title">
                            <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-feather"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path><line x1="16" y1="8" x2="2" y2="22"></line><line x1="17" y1="15" x2="9" y2="15"></line></svg>Professional Skill</h4>
                            <a href="#" class="add-more">+ Add Skill</a>
                          </div>
                          <div class="content">
                            <form method="POST" action="javascript:void(null);" onsubmit="send(this)" data-type="repeater">
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                   <div class="vc_col-sm-3">
                                  <label class="col-form-label">1</label>
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Skill Name</div>
                                      </div>
                                      <input type="text" class="form-control" name="skill">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                 <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Percentage</div>
                                      </div>
                                      <input type="text" class="form-control" name="percent">
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                   <div class="vc_col-sm-3">
                                  <label class="col-form-label">2</label>
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Skill Name</div>
                                      </div>
                                      <input type="text" class="form-control" name="skill">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                 <div class="vc_col-sm-3">
                                  </div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Percentage</div>
                                      </div>
                                      <input type="text" class="form-control" name="percent">
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="vc_row">
                               <div class="vc_col-sm-3">
                                  </div>
                                <div class="vc_col-sm-9">
                                  <div class="buttons">
                                  	<input type="hidden" name="form_type" value="prof_skill_form" />
                                    <button class="primary-bg">Save Update</button>
                                    <a class="modal-dismiss" data-dismiss="#modal-pro-skill">Cancel</a>
                                  </div>
                                </div>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>';
            endif;

            //Portfolio

            if ( $items = get_post_meta( get_the_ID(), '_links', true ) ) :
            $result .= '<div class="portfolio dashboard-section details-section">
                  <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-gift"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>Portfolio</h4><div class="portfolio-slider owl-carousel">';
                 foreach( $items as $item ) :
                    $result .= '<div class="portfolio-item">
			                      <img src="'.esc_html($item["image"]).'" class="img-fluid" alt="">
			                      <div class="overlay">
			                        <a href="#"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>
			                        <a href="'.esc_html($item["url"]).'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-link"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg></a>
			                      </div>
			                    </div>';
                            endforeach;
                $result .= '</div><button type="button" class="btn btn-primary edit-resume" data-toggle="modal" data-target="#modal-portfolio">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                  </button><div class="modal fade modal-portfolio" id="modal-portfolio" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                      <div class="modal-content">
                        <div class="modal-body">
                          <div class="title">
                            <h4><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-grid"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>Portfolio</h4>
                            <a href="#" class="add-more">+ Add Another</a>
                          </div>
                          <div class="content">
                            <form method="POST" enctype="multipart/form-data" action="javascript:void(null);" onsubmit="send_portfolio(this)" data-type="repeater">
                              <div class="input-block-wrap">
                                <div class="form-group vc_row">
                                  <div class="vc_col-sm-3"><label class="col-form-label">1</label></div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Title</div>
                                      </div>
                                      <input type="text" class="form-control" name="port_name">
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3"></div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Image</div>
                                      </div>
                                      <div class="upload-profile-photo">
                                        <div class="file-upload">            
                                          <input type="file" class="file-input" name="port_image">
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                                <div class="form-group vc_row">
                                <div class="vc_col-sm-3"></div>
                                  <div class="vc_col-sm-9">
                                    <div class="input-group">
                                      <div class="input-group-prepend">
                                        <div class="input-group-text">Link</div>
                                      </div>
                                      <input type="text" class="form-control" name="port_link">
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="vc_row">
                              <div class="vc_col-sm-3"></div>
                                <div class="vc_col-sm-9">
                                  <div class="buttons">
                                    <button class="primary-bg">Save Update</button>
                                    <a class="modal-dismiss" data-dismiss="#modal-portfolio">Cancel</a>
                                  </div>
                                </div>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div></div>';
            endif;
               $result .= '</div><div class="success_popup"></div>';


      endif;
         endwhile;
    endif; 

    return $result;
   
}
add_shortcode( 'resume_edit', 'resume_edit_func' );

function resume_edit_script() {
	

	$user_id = get_current_user_id();

	$resume_id = get_resume_user_resume_id($user_id);

	// portfolio

	if($_POST['count'] != NULL) {

		if ( ! function_exists( 'wp_handle_upload' ) ) 
			require_once( ABSPATH . 'wp-admin/includes/file.php' );

		$array = array();

		$overrides = [ 'test_form' => false ];

		for ($i=0; $i <= $_POST['count']; $i++) {

			$file = & $_FILES['file' . $i];
			parse_str($_POST['array' . $i], $data);
			$movefile = wp_handle_upload( $file, $overrides );
			$array[] = array(
				"image" => $movefile['url'],
				"url" => $data['port_link']
			);

		}

		update_post_meta($resume_id, '_links', $array);
	}

	// CV
	

	if(!empty($_FILES['file'])){

		if ( ! function_exists( 'wp_handle_upload' ) ) 
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
	
		$file = & $_FILES['file'];

		$overrides = [ 'test_form' => false ];
		$movefile = wp_handle_upload( $file, $overrides );

		if($_POST['file_type'] == "cv") {
			update_user_meta( $user_id, 'cv_url', $movefile['url']);
		}
		if($_POST['file_type'] == "cover_letter") {
			update_user_meta( $user_id, 'cover_url', $movefile['url']);
		}

	}

	// about

	if($_POST['fields_type'] == "simple") {

		parse_str($_POST['array'], $data);

		if(!empty($data['form_type']) && $data['form_type'] == "form_about") {

			if(!empty($data['field_about'])){
				$my_post = array();
				$my_post['ID'] = $resume_id;
				$my_post['post_content'] = $data['field_about'];
				wp_update_post( wp_slash($my_post) );
			}
			if(!empty($data['field_category'])){
				wp_set_post_categories($resume_id, $data['field_category'], true);
			}
			if(!empty($data['field_location'])){
				update_post_meta($resume_id, '_candidate_location', $data['field_location']);
			}
			if(!empty($data['field_status'])){
				update_post_meta($resume_id, '_candidate_status', $data['field_status']);
			}
			if(!empty($data['field_experience'])){
				update_post_meta($resume_id, '_candidate_experienc', $data['field_experience']);
			}
			if(!empty($data['field_salary'])){
				update_post_meta($resume_id, '_salary', $data['field_salary']);
			}
			if(!empty($data['field_gender'])){
				update_post_meta($resume_id, '_candidate_gender', $data['field_gender']);
			}
			if(!empty($data['field_age'])){
				update_post_meta($resume_id, '_candidate_age', $data['field_age']);
			}
			if(!empty($data['field_graduate'])){
				update_post_meta($resume_id, '_candidate_qualification', $data['field_graduate']);
			}

			
		}

		// Social

		if(!empty($data['form_type']) && $data['form_type'] == "form_social") {

			if(!empty($data['social_fb'])){
				update_post_meta($resume_id, '_social_facebook', $data['social_fb']);
			}
			if(!empty($data['social_tw'])){
				update_post_meta($resume_id, '_social_twitter', $data['social_tw']);
			}
			if(!empty($data['social_instagram'])){
				update_post_meta($resume_id, '_social_instagram', $data['social_instagram']);
			}
			if(!empty($data['social_pinterest'])){
				update_post_meta($resume_id, '_social_pinterest', $data['social_pinterest']);
			}
			if(!empty($data['social_linkedin'])){
				update_post_meta($resume_id, '_social_linkedin', $data['social_linkedin']);
			}
			if(!empty($data['social_git'])){
				update_post_meta($resume_id, '_social_git', $data['social_git']);
			}
			
		}

		// Repeaters: education, skills, experience

	}else if($_POST['fields_type'] == "repeater") {

		$array = array();

		foreach ($_POST['array'] as $data_array) {
			parse_str($data_array, $data);

			if($_POST['form_type'] == "education_form") {

				if(!empty($data['education_title']) || !empty($data['education_qualific']) || !empty($data['education_period']) || !empty($data['education_descr'])) {

					$array[] = array(
						"location" => $data['education_title'],
					    "qualification" => $data['education_qualific'],
					    "date" => $data['education_period'],
					    "notes" => $data['education_descr']
					);
				}
			}

			if($_POST['form_type'] == "experience_form") {

				if(!empty($data['exp_title']) || !empty($data['exp_company']) || !empty($data['exp_period']) || !empty($data['exp_descr'])) {
					
					$array[] = array(
						"job_title" => $data['exp_title'],
					    "employer" => $data['exp_company'],
					    "date" => $data['exp_period'],
					    "notes" => $data['exp_descr']
					);
				}
			}
			if($_POST['form_type'] == "prof_skill_form") {

				if(!empty($data['skill']) || !empty($data['percent'])) {

					$array[] = array(
						"profskill" => $data['skill'],
					    "level" => $data['percent']
					);
				}
			}
			if($_POST['form_type'] == "skill_tags") {

				if(!empty($data['skill_tag'])) {

					$array[] = $data['skill_tag'];
				}
			}


		}
		if($_POST['form_type'] == "education_form" && !empty($array)) {

			update_post_meta($resume_id, "_candidate_education", $array);
		}
		if($_POST['form_type'] == "experience_form" && !empty($array)) {

			update_post_meta($resume_id, "_candidate_experience", $array);
		}
		if($_POST['form_type'] == "prof_skill_form" && !empty($array)) {

			update_post_meta($resume_id, "_candidate_prof_skill", $array);
		}
		if($_POST['form_type'] == "skill_tags" && !empty($array)) {
			wp_set_object_terms( $resume_id, $array, 'resume_skill', false );
		}
	}
	
	
	
	wp_die();
}

add_action( 'wp_ajax_resume_edit', 'resume_edit_script' );
add_action( 'wp_ajax_nopriv_resume_edit', 'resume_edit_script');
