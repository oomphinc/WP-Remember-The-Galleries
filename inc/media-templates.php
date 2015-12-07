<script type="text/html" id="tmpl-gallery-selection">
	<div class="gallery-name-container ui-front">
		<input type="text" class="widefat" id="gallery-name-select" class="gallery-name-select" placeholder="Select gallery or name a new gallery" value="{{ data.name }}" />
		<div class="gallery-load-button"></div>
	</div>
</script>

<script type="text/html" id="tmpl-gallery-result">
	<a class="gallery-name">
		{{ data.name }}
		<span class="gallery-ops">
			<span class="gallery-count">{{ data.count }}</span>
		</span>
	</a>
</script>
