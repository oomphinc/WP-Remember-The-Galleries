/**
 * WP "Remember the Galleries" management.
 *
 * Extend the Media Manager to allow for saving galleries
 */
(function($) {
	var media = wp.media, galleryFrame;

	var CreateGalleryButton = media.view.Button.extend({
		initialize: function() {
			media.view.Button.prototype.initialize.apply( this, arguments );

			if ( this.options.filters ) {
				this.listenTo( this.options.filters.model, 'change', this.filterChange );
			}

			this.listenTo( this.controller, 'selection:toggle', this.toggleDisabled );
			this.listenTo( this.controller, 'select:activate select:deactivate', this.toggleBulkEditHandler );
			this.listenTo( this.controller, 'selection:action:done', this.back );
		},

		filterChange: function( model ) {
			if ( 'trash' === model.get( 'status' ) ) {
				this.model.set( 'disabled', true );
			} else {
				this.model.unset('disabled');
			}
		},

		toggleDisabled: function() {
			this.model.set( 'disabled', !this.controller.state().get('selection').length );
		},

		render: function() {
			media.view.Button.prototype.render.apply( this, arguments );

			if ( this.controller.isModeActive( 'select' ) ) {
				this.$el.addClass( 'delete-selected-button' );
			} else {
				this.$el.addClass( 'delete-selected-button hidden' );
			}
			this.toggleDisabled();
			return this;
		}
	});

	/**
	 * A gallery selection box
	 */
	var GallerySelect = media.View.extend({
		className: 'gallery-select-list',
		template: media.template('gallery-select'),
		initialize: function() {
			this.$el.hide();
		},
	});

	/**
	 * A gallery autocomplete result
	 */
	var GalleryResult = media.View.extend({
		tagName: 'li',
		className: 'gallery-result',
		template: media.template('gallery-result'),
		prepare: function() {
			return this.model.toJSON();
		}
	});

	/**
	 * Specify a gallery name using UI autocomplete
	 */
	var GalleryName = media.view.PriorityList.extend({
		className: 'gallery-name-container',

		events: {
			'keyup .gallery-name-input': 'updateName',
			'click .gallery-select-button': 'selectGalleryPopup'
		},

		initialize: function() {
			this.model.on('change:library', this.render, this);
			this.model.on('change:name', this.updateName, this);

			this.nameInput = new media.View({
				tagName: 'input',
				className: 'gallery-name-input widefat',
				attributes: {
					placeholder: wpRememberTheGalleries['gallery-name']
				}
			});

			this.selectButton = new media.view.Button({
				className: 'media-button gallery-select-button',
				text: wpRememberTheGalleries['select']
			});

			this.nameLabel = new media.View({
				className: 'gallery-name-label',
				tagName: 'label'
			});

			this.views.add('', this.nameInput);
			this.views.add('', this.nameLabel);
			this.views.add('', this.selectButton);
		},

		selectGalleryPopup: function() {
			if(!this.selectPopup) {
				this.selectPopup = new GallerySelect();
				this.selectPopup.on('select', this.selectGallery);
				this.selectButton.model.set('text', wpRememberTheGalleries['cancel']);
				this.views.add('', this.selectPopup);
			}
			else {
				return this.cancelSelect();
			}

			this.selectPopup.$el.show();

			var self = this
			  , $input = this.selectPopup.$('input');

			$input.autocomplete({
				source: function(request, response) {
					wp.ajax.post('rtg_gallery_search', { term: request.term })
						.done(function(data) {
							response(_.map(data, function(v) {
								v.id = v.name;

								var itemModel = new Backbone.Model(v);

								return {
									label: v.name,
									value: v.name,
									model: itemModel
								}
							}));
						})
					.fail(function(data) {
						response([]);
					});
				},
				select: function(ev, ui) {
					self.selectGallery(ui.item.model);
				},
				position: {
					my: 'right top',
					at: 'right bottom'
				},
				minLength: 0,
			}).data('ui-autocomplete')._renderItem = function(ul, item) {
				item.model.view = new GalleryResult({
					model: item.model
				}).render();

				item.model.view.$el.appendTo(ul);

				return item.model.view.$el;
			};

			// Focus and execute initial search
			$input.focus();
			$input.autocomplete('search');

			$input.on('keyup', function(ev) {
				if(ev.keyCode === 27) {
					self.cancelSelect();
				}
			});
		},

		cancelSelect: function() {
			this.selectPopup.remove();

			delete this.selectPopup;

			this.selectButton.model.set('text', wpRememberTheGalleries['load']);
		},

		selectGallery: function(model) {
			this.cancelSelect();

			this.model.set(model.attributes);
			this.$('.gallery-name').val(this.model.get('name'));
			this.controller.setIds(this.model.get('ids'));
		},

		prepare: function() {
			return this.model.toJSON();
		},

		updateName: function(ev) {
			var state = this.controller.state();

			this.model.set('name', this.nameInput.$el.val());

			this.nameLabel.$el.text(this.model.get('name'));

			state && state.frame.updateButtonState();
		},

		render: function() {
			var $input;

			media.View.prototype.render.apply(this, arguments);

			// When looking at the full library, indicate that we're adding to a new
			// or existing gallery
			if(this.model.get('library')) {
				this.$('.gallery-actions').hide();

				$input = this.$('.gallery-name');

				$input
					.attr('disabled', 'disabled')
					.attr('placeholder', wpRememberTheGalleries['add-new-gallery']);

				if(this.model.get('id')) {
					$input.val(wpRememberTheGalleries['add-existing'].replace(/%s/, this.model.get('name')));
				}
			}

			this.updateName();
		},

		get: function() {
		}
	});

	media.controller.GalleryEdit.prototype.defaults.router = 'gallery-name';
	media.controller.GalleryAdd.prototype.defaults.router = 'gallery-name';

	var GalleryAttachments = media.model.Attachments.extend({
		sync: function( method, collection, options ) {
			if( 'read' === method ) {
				options.data = _.extend(options.data || {}, {
					'action': 'rtg_query_attachments',
					ids: collection.pluck('id')
				});

				return wp.media.ajax(options);
			}
		}

	});

	var GalleryPostFrame = function(parent) {
		return {
			initialize: function() {
				if(this.options.galleryDetails) {
					this.galleryDetails = this.options.galleryDetails;
				}
				else {
					this.galleryDetails = GalleryPostFrame.galleryDetails;

					if(!this.galleryDetails) {
						this.galleryDetails = GalleryPostFrame.galleryDetails = new Backbone.Model({
							id: null,
							name: null,
							ids: []
						});
					}
				}

				if(!this.options.router) {
					this.options.router = new GalleryName({
						controller: this,
						model: this.galleryDetails
					});
				}

				parent.prototype.initialize.apply(this, arguments);
			},

			updateGalleryIds: function(model, ids) {
				this.setIds(ids);
			},

			setIds: function(ids) {
				if(!this.content.get('gallery')) {
					return;
				}

				var attachments = new GalleryAttachments(_.map(ids, function(id) {
					return { id: id };
				}));

				attachments.fetch();

				var collection = this.content.get('gallery').collection;

				collection.reset();
				collection.unobserve().observe(attachments).props.set({ignore: (+ new Date())});
			},

			bindHandlers: function() {
				parent.prototype.bindHandlers && parent.prototype.bindHandlers.apply(this, arguments);

				this.on( 'menu:create:gallery', this.createMenu, this );
				this.on( 'router:create:gallery-name', this.createGalleryRouter, this );
				this.on( 'router:render:gallery-name', this.renderGalleryRouter, this );

				this.on( 'open', this.activate, this );
				this.state('gallery-edit').on('activate', this.activate, this);

				if(this.galleryDetails) {
					this.galleryDetails.on('change:name', this.updateButtonState, this);
					this.galleryDetails.on('change:ids', this.updateGalleryIds, this);
				}
			},

			createGalleryRouter: function( router ) {
				router.view = this.options.router;
			},

			renderGalleryRouter: function( router ) {
				var state = this.state();

				if(state && state.id == 'gallery-library') {
					this.galleryDetails.set('library', true);
				}
				else {
					this.galleryDetails.unset('library');
				}
			},

			saveGallery: function() {
				var selection = this.state().get('library');

				var saveData = {
					images: selection.map(function(item) {
						return { id: item.id, caption: item.caption };
					}),
					name: this.galleryDetails.get('name'),
					settings: selection.gallery.attributes
				};

				var save = function() {
					media.post('rtg_save_gallery', saveData).done(function(data) {
						media.frame && media.frame.close();
						location.reload();
					}).fail(function(data) {
						if(data === 'empty-name') {
							this.router.get().$el.find('input').focus();
						}
						else if(data === 'need-confirm') {
							if(confirm(wpRememberTheGalleries['are-you-sure'])) {
								saveData.yes = true;
								save();
							}
						}
						else {
							alert( "Failed!" );
						}
					});
				}

				save();
			},

			galleryEditToolbar: function(toolbar) {
				if(wp.media.frame.id != 'library-gallery') {
					postEditToolbar.apply(this, arguments);
				}
				else {
					this.toolbar.set(new media.view.Toolbar({ controller: this }));
				}

				this.saveGalleryButton = new media.view.Button({
					click: function() {
						this.controller.saveGallery();
					},
					disabled: true,
					style: 'primary',
					text: "Save Gallery",
					controller: this,
				});

				this.toolbar.get().set({
					'save-gallery': this.saveGalleryButton
				});
			},

			updateButtonState: function() {
				this.saveGalleryButton && this.saveGalleryButton.model.set('disabled', !this.galleryDetails.get('name') || this.galleryDetails.get('rename'));
			},

			activate: function() {
				parent.prototype.activate && parent.prototype.activate.apply(this, arguments);

				this.updateButtonState();
			}
		};
	}

	// Save a reference to the original toolbar creation method so we
	// can call it in our frame without a 'parent' reference
	var postEditToolbar = media.view.MediaFrame.Post.prototype.galleryEditToolbar;

	_.each([ 'Post', 'Select' ], function(frame) {
		media.view.MediaFrame[frame] = media.view.MediaFrame[frame].extend(GalleryPostFrame(media.view.MediaFrame[frame]));
	});

	/**
	 * Define a simple media frame for use on the library screen only, further extended by
	 * GalleryPostFrame
	 */
	var GalleryFrame = media.view.MediaFrame.extend({
		initialize: function() {
			// Call 'initialize' directly on the parent class.
			media.view.MediaFrame.prototype.initialize.apply( this, arguments );

			_.defaults( this.options, {
				selection: [],
				library:   {},
				multiple:  false,
				state:    'gallery-edit'
			});

			// Use the "Gallery Edit" and "Gallery Add" states.
			this.states.add([
				new media.controller.GalleryEdit({
					library: this.options.selection,
					editing: this.options.editing,
					menu:    'gallery',
					router:  'gallery-name'
				}),

				new media.controller.GalleryAdd({
					router: 'gallery-name'
				})
			]);

			this.bindHandlers();
		},

		bindHandlers: function() {
			this.on( 'content:create:browse', this.browseContent, this );
			this.on( 'toolbar:render:gallery-edit', this.galleryEditToolbar, this );
			this.on( 'toolbar:render:gallery-add', media.view.MediaFrame.Post.prototype.galleryAddToolbar, this );
		},

		createToolbar: media.view.MediaFrame.Post.prototype.createToolbar,
		browseContent: media.view.MediaFrame.Post.prototype.browseContent,
	});

	GalleryFrame = GalleryFrame.extend(GalleryPostFrame(GalleryFrame));

	// No great way to simply extend the tool bar here, so inject code into the
	// call chain
	var createToolbar = media.view.AttachmentsBrowser.prototype.createToolbar;

	media.view.AttachmentsBrowser = media.view.AttachmentsBrowser.extend({
		createToolbar: function() {
			createToolbar.apply(this, arguments);

			if(!this.controller.modal) {
				this.toolbar.set( 'createGallery', new CreateGalleryButton({
					style: 'secondary',
					text: "Save to Gallery...",
					filters: this.toolbar.get('filters'),
					controller: this.controller,
					priority: -70,
					click: function() {
						wp.media.frame = new GalleryFrame({
							id: 'library-gallery',
							selection: this.controller.state().get('selection'),
							library: this.controller.state().get('library'),
							title: "Select Gallery"
						});

						wp.media.frame.galleryDetails.set({
							ids: this.controller.state().get('selection').pluck('id'),
						});

						wp.media.frame.open();
					}
				}).render() );
			}
		}
	});

	// Extend wp.media.gallery.attachments, transforming slugs into ids
	if(wp.media.gallery) {
		var galleryAttachments = wp.media.gallery.attachments;

		wp.media.gallery.attachments = function(shortcode) {
			if(shortcode.attrs.named['slug'] && wpRememberTheGalleries.slugs[shortcode.attrs.named['slug']]) {
				shortcode.atts.named['ids'] = wpRememberTheGalleries.slugs[shortcode.attrs.named['slug']];
			}

			return galleryAttachments(shortcode);
		}
	}

	// Pop up media manager with a gallery loaded on Media Library bulk select
	// screen
	$('body').on('click', '.open-gallery-edit', function() {
		var name = $(this).data('name');
		var ids = ($(this).data('ids') + "").split(',');
		var id = $(this).data('id');

		if(!wp.media.frame) {
			wp.media.frame = new GalleryFrame({
				selection: new media.model.Selection([], { multiple: true }),
				library: media.query({ type: 'image' }),
			});
		}

		var details = wp.media.frame.galleryDetails;

		details.set('id', id);
		details.set('name', name);

		wp.media.frame.open();
		wp.media.frame.setIds(ids);
	});

	// Pop up media manager with edit gallery screen selected, for creating
	// new galleries from the gallery list screen. We want to discourage
	// usage of the "edit post" screen for galleries, at least for now.
	$('body.post-type-wp_rtg').on('click', '.add-new-h2', function(ev) {
		wp.media.frame = new GalleryFrame({
			selection: new media.model.Selection([], { multiple: true }),
			library: media.query({ type: 'image' }),
		});

		wp.media.frame.open();
		wp.media.frame.setState('gallery-library');

		ev.stopPropagation();
		ev.preventDefault();
	});
})(jQuery);
