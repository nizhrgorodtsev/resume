<?php
switch ( $resume->post_status ) :
	case 'publish' :
		if ( resume_manager_user_can_view_resume( $resume->ID ) ) {
			printf( '<div class="success-window">' . __( 'Your resume has been submitted successfully. To view your resume <a href="%s">click here</a>.', 'wp-job-manager-resumes' ) . '</div>', get_permalink( $resume->ID ) );
		} else {
			print( '<div class="success-window">' . __( 'Your resume has been submitted successfully.', 'wp-job-manager-resumes' ) . '</div>' );
		}
	break;
	case 'pending' :
		print( '<div class="success-window">' . __( 'Your resume has been submitted successfully and is pending approval.', 'wp-job-manager-resumes' ) . '</div>' );
	break;
	default :
		do_action( 'resume_manager_resume_submitted_content_' . str_replace( '-', '_', sanitize_title( $resume->post_status ) ), $resume );
	break;
endswitch;
