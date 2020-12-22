<?php 
	$category = get_the_resume_category();
	$user_id = get_user_resume_id(get_the_ID());
	$avatar = wp_get_attachment_image_url(get_user_meta($user_id, 'yith-wcmap-avatar', true), 'medium');
	global $job_manager_bookmarks;
?>
<li class="candidate">
	<div class="thumb">
		<a href="<?php the_resume_permalink(); ?>">
			<img src="<?php echo $avatar; ?>" alt="alt">
		</a>
	</div>
	<div class="body">
		<div class="content">
			<h4><a href="<?php the_resume_permalink(); ?>"><?php the_title(); ?></a></h4>
			<div class="info">
				<span class="work-post"><a href="#"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-check-square"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg><?php the_candidate_title(); ?></a></span>
				<span class="location"><a href="#"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-map-pin"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg><?php the_candidate_location( false ); ?></a></span>
			</div>
		</div>
		<div class="button-area">
			<input type="hidden" name="bookmark_post_id" value="<?php echo get_the_ID(); ?>">
			<input type="hidden" name="bookmark_post_type" value="resume">
			<a class="bookmark_button favourite <?php if($job_manager_bookmarks->is_bookmarked(get_the_ID())){ echo 'active';}?>" onclick="bookmark_this(this)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-heart"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg></a>
			<a href="<?php the_resume_permalink(); ?>" class="resume_view">View Resume</a>
		</div>
	</div>
</li>