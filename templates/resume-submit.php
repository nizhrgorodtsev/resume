<?php
/**
 * Resume Submission Form
 */
ini_set('error_reporting', 0);
if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_script( 'wp-resume-manager-resume-submission' );
?>
<form action="<?php echo $action; ?>" method="post" id="submit-resume-form" class="job-manager-form" enctype="multipart/form-data">

	<?php do_action( 'submit_resume_form_start' ); ?>


	<?php if ( resume_manager_user_can_post_resume() ) : ?>

		<?php if ( get_option( 'resume_manager_linkedin_import' ) ) : ?>

			<?php get_job_manager_template( 'linkedin-import.php', '', 'wp-job-manager-resumes', RESUME_MANAGER_PLUGIN_DIR . '/templates/' ); ?>

		<?php endif; ?>

		<!-- Resume Fields -->
		<?php do_action( 'submit_resume_form_resume_fields_start' ); ?>

		<?php foreach ( $resume_fields as $key => $field ) : ?>
			<fieldset class="vc_row fieldset-<?php esc_attr_e( $key ); ?>">
				<div class="vc_col-sm-3">
					<label for="<?php esc_attr_e( $key ); ?>"><?php echo $field['label'] . apply_filters( 'submit_resume_form_required_label', $field['required'] ? '' : ' <small>' . __( '(optional)', 'wp-job-manager-resumes' ) . '</small>', $field ); ?></label>
				</div>
				<div class="vc_col-sm-9 field">
					<?php if($key == "resume_file") : ?>
						<div class="add-cv">
					<?php endif; ?>
					<?php if($key == "candidate_photo") : ?>
						<div class="add-cv">
					<?php endif; ?>
					<?php $class->get_field_template( $key, $field ); ?>
					<?php if($key == "resume_file") : ?>
						CW File
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-upload-cloud"><polyline points="16 16 12 12 8 16"></polyline><line x1="12" y1="12" x2="12" y2="21"></line><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path><polyline points="16 16 12 12 8 16"></polyline></svg>
					</div>
					<?php endif; ?>
					<?php if($key == "candidate_photo") : ?>
						Upload Photo
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-upload-cloud"><polyline points="16 16 12 12 8 16"></polyline><line x1="12" y1="12" x2="12" y2="21"></line><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path><polyline points="16 16 12 12 8 16"></polyline></svg>
					</div>
					<?php endif; ?>
				</div>
			</fieldset>

			<?php if($key == "candidate_name") : ?>
			<div class="vc_row cand_info">
				<div class="vc_col-sm-3">
					<label>Information</label>
				</div>
				<div class="vc_col-sm-9">
			<?php endif; ?>

			<?php if($key == "candidate_email") : ?>
			</div>
		</div>
			<?php endif; ?>

			<?php if($field['label'] == "Hourly rate") : ?>
			<div class="vc_row cand_info cand_social">
				<div class="vc_col-sm-3">
					<label>Social</label>
				</div>
				<div class="vc_col-sm-9">
			<?php endif; ?>

			<?php if($key == "social_git") : ?>
			</div>
		</div>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php do_action( 'submit_resume_form_resume_fields_end' ); ?>

		<p>
			<?php wp_nonce_field( 'submit_form_posted' ); ?>
			<input type="hidden" name="resume_manager_form" value="<?php echo $form; ?>" />
			<input type="hidden" name="resume_id" value="<?php echo esc_attr( $resume_id ); ?>" />
			<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>" />
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
			<input type="submit" name="submit_resume" class="button" value="<?php esc_attr_e( $submit_button_text ); ?>" />
		</p>

	<?php else : ?>

		<?php do_action( 'submit_resume_form_disabled' ); ?>

	<?php endif; ?>

	<?php do_action( 'submit_resume_form_end' ); ?>
</form>
