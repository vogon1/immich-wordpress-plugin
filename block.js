(function() {
	const { registerBlockType } = wp.blocks;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, CheckboxControl, RadioControl } = wp.components;
	const { createElement: el, useState, useEffect } = wp.element;
	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;

	const Edit = function(props) {
		const { attributes, setAttributes } = props;
		const { mode, album, albums, show, order } = attributes;
		const blockProps = useBlockProps();
		
		const [availableAlbums, setAvailableAlbums] = useState([]);
		const [loading, setLoading] = useState(true);
		
		// Fetch albums from REST API
		useEffect(() => {
			apiFetch({ path: '/immich-gallery/v1/albums' })
				.then(data => {
					if (data && data.albums) {
						setAvailableAlbums(data.albums);
					}
					setLoading(false);
				})
				.catch(error => {
					console.error('Error fetching albums:', error);
					setLoading(false);
				});
		}, []);
		
		// Generate shortcode preview
		const generateShortcode = () => {
			let shortcode = '[immich_gallery';
			
			if (mode === 'single' && album) {
				shortcode += ' album="' + album + '"';
			} else if (mode === 'multiple' && albums.length > 0) {
				shortcode += ' albums="' + albums.join(',') + '"';
			}
			
			if (show && show.length > 0) {
				shortcode += ' show="' + show.join(',') + '"';
			}
			
			if (order) {
				shortcode += ' order="' + order + '"';
			}
			
			shortcode += ']';
			return shortcode;
		};
		
		const albumOptions = [
			{ label: __('Select an album...', 'immich-gallery'), value: '' }
		].concat(
			availableAlbums.map(function(albumItem) {
				return {
					label: albumItem.name,
					value: albumItem.id
				};
			})
		);
		
		const showOptions = [
			{ label: __('Gallery name', 'immich-gallery'), value: 'gallery_name' },
			{ label: __('Gallery description', 'immich-gallery'), value: 'gallery_description' },
			{ label: __('Asset date', 'immich-gallery'), value: 'asset_date' },
			{ label: __('Asset description', 'immich-gallery'), value: 'asset_description' }
		];
		
		const orderOptions = [
			{ label: __('Default', 'immich-gallery'), value: '' },
			{ label: __('Newest first', 'immich-gallery'), value: 'date_desc' },
			{ label: __('Oldest first', 'immich-gallery'), value: 'date_asc' },
			{ label: __('A-Z (albums only)', 'immich-gallery'), value: 'name_asc' },
			{ label: __('Z-A (albums only)', 'immich-gallery'), value: 'name_desc' },
			{ label: __('A-Z by description (photos only)', 'immich-gallery'), value: 'description_asc' },
			{ label: __('Z-A by description (photos only)', 'immich-gallery'), value: 'description_desc' }
		];
		
		const handleShowToggle = function(value, checked) {
			const newShow = checked
				? show.concat([value])
				: show.filter(function(item) { return item !== value; });
			setAttributes({ show: newShow });
		};
		
		const handleAlbumToggle = function(albumId, checked) {
			const newAlbums = checked
				? albums.concat([albumId])
				: albums.filter(function(id) { return id !== albumId; });
			setAttributes({ albums: newAlbums });
		};
		
		return el('div', blockProps,
			el(InspectorControls, {},
				el(PanelBody, { title: __('Gallery Settings', 'immich-gallery'), initialOpen: true },
					el(RadioControl, {
						label: __('Display Mode', 'immich-gallery'),
						selected: mode,
						options: [
							{ label: __('All albums overview', 'immich-gallery'), value: 'overview' },
							{ label: __('Single album', 'immich-gallery'), value: 'single' },
							{ label: __('Multiple albums', 'immich-gallery'), value: 'multiple' }
						],
						onChange: function(value) { setAttributes({ mode: value }); }
					}),
					
					mode === 'single' && el(SelectControl, {
						label: __('Select Album', 'immich-gallery'),
						value: album,
						options: albumOptions,
						onChange: function(value) { setAttributes({ album: value }); },
						disabled: loading
					}),
					
					mode === 'multiple' && !loading && availableAlbums.length > 0 && 
						el('div', { style: { marginTop: '12px' } },
							el('label', { style: { fontWeight: 600, marginBottom: '8px', display: 'block' } },
								__('Select Albums', 'immich-gallery')
							),
							availableAlbums.map(function(albumItem) {
								return el(CheckboxControl, {
									key: albumItem.id,
									label: albumItem.name,
									checked: albums.indexOf(albumItem.id) !== -1,
									onChange: function(checked) { handleAlbumToggle(albumItem.id, checked); }
								});
							})
						),
					
					el(SelectControl, {
						label: __('Sort Order', 'immich-gallery'),
						value: order,
						options: orderOptions,
						onChange: function(value) { setAttributes({ order: value }); }
					})
				),
				
				el(PanelBody, { title: __('Display Options', 'immich-gallery'), initialOpen: false },
					showOptions.map(function(option) {
						return el(CheckboxControl, {
							key: option.value,
							label: option.label,
							checked: show.indexOf(option.value) !== -1,
							onChange: function(checked) { handleShowToggle(option.value, checked); }
						});
					})
				)
			),
			
			el('div', { 
				style: { 
					padding: '20px', 
					backgroundColor: '#f0f0f0', 
					borderRadius: '4px',
					fontFamily: 'monospace'
				} 
			},
				loading 
					? el('p', {}, __('Loading albums...', 'immich-gallery'))
					: el('div', {},
						el('p', { style: { marginBottom: '10px', fontWeight: 'bold' } }, 
							__('Immich Gallery', 'immich-gallery')
						),
						el('code', { style: { display: 'block', padding: '10px', backgroundColor: 'white', borderRadius: '3px' } },
							generateShortcode()
						),
						mode === 'overview' && el('p', { style: { marginTop: '10px', fontSize: '12px' } },
							__('Will display all albums from Immich', 'immich-gallery')
						),
						mode === 'single' && album && el('p', { style: { marginTop: '10px', fontSize: '12px' } },
							__('Selected: ', 'immich-gallery') + (availableAlbums.find(function(a) { return a.id === album; }) || {}).name || album
						),
						mode === 'multiple' && albums.length > 0 && el('p', { style: { marginTop: '10px', fontSize: '12px' } },
							__('Selected albums: ', 'immich-gallery') + albums.length
						)
					)
			)
		);
	};

	registerBlockType('immich-gallery/gallery', {
		edit: Edit,
		save: function() {
			return null;
		}
	});
})();
