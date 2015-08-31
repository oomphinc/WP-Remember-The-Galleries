<input type="text" class="widefat" class="gallery-select" placeholder="<?php esc_attr_e( 'Select Existing Gallery...', 'wp-rtg' ); ?>" />
</script>

<script type="text/html" id="tmpl-gallery-result">
	<a class="gallery-name">
		{{ data.name }}
		<span class="gallery-ops">
			<span class="gallery-count">{{ data.count }}</span>
		</span>
	</a>
</script>
