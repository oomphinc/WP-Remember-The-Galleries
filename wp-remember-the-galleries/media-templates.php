<script type="text/html" id="tmpl-gallery-selection">
	<div class="gallery-name-container">
		<input type="text" class="widefat gallery-name" placeholder="Gallery name..." value="{{ data.name }}" />
		<div class="gallery-actions">
			<div class="gallery-select-button"></div>
		</div>
		<div class="gallery-select-container ui-front"></div>
	</div>
</script>

<script type="text/html" id="tmpl-gallery-select">
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
