import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, CheckboxControl, RadioControl, RangeControl, TextControl, ToggleControl } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const Edit = ({ attributes, setAttributes }) => {
	const { mode, album, albums, asset, show, order, size, title_size, description_size, date_size } = attributes;
	const link     = attributes.link     || 'lightbox';
	const link_url = attributes.link_url || '';
	const link_target = attributes.link_target || 'new';
	const limit = attributes.limit || 0;
	const pick  = attributes.pick  || 'first';
	// undefined = album pages opened from the overview use `show` as well.
	const detail_show = attributes.detail_show;
	const hasAlbumPages = mode === 'overview' || mode === 'multiple';
	const blockProps = useBlockProps();
	
	const [availableAlbums, setAvailableAlbums] = useState([]);
	const [loading, setLoading] = useState(true);
	
	// Fetch albums from REST API
	useEffect(() => {
		apiFetch({ path: '/gallery-for-immich/v1/albums' })
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
		let shortcode = '[gallery_for_immich';
		
		if (mode === 'asset' && asset) {
			shortcode += ` asset="${asset}"`;
		} else if (mode === 'single' && album) {
			shortcode += ` album="${album}"`;
		} else if (mode === 'multiple' && albums.length > 0) {
			shortcode += ` albums="${albums.join(',')}"`;
		}
		
		if (show && show.length > 0) {
			shortcode += ` show="${show.join(',')}"`;
		}

		if (hasAlbumPages && Array.isArray(detail_show)) {
			shortcode += ` detail_show="${detail_show.join(',')}"`;
		}
		
		if (order) {
			shortcode += ` order="${order}"`;
		}
		
		if (size && size !== 200) {
			shortcode += ` size="${size}"`;
		}
		
		if (title_size && title_size !== 16) {
			shortcode += ` title_size="${title_size}"`;
		}
		
		if (description_size && description_size !== 14) {
			shortcode += ` description_size="${description_size}"`;
		}
		
		if (date_size && date_size !== 13) {
			shortcode += ` date_size="${date_size}"`;
		}

		// limit / pick (albums and photos; pick only matters with a limit)
		if (mode !== 'asset' && limit > 0) {
			shortcode += ` limit="${limit}"`;
			if (pick !== 'first') {
				shortcode += ` pick="${pick}"`;
			}
		}

		// align (only for single photo mode)
		if (mode === 'asset' && attributes.align && attributes.align !== 'none') {
			shortcode += ` align="${attributes.align}"`;
		}

		// link (single photo and single album; omit when default 'lightbox')
		if ((mode === 'asset' || mode === 'single') && link !== 'lightbox') {
			if (link === 'custom' && link_url) {
				shortcode += ` link="${link_url}"`;   // URL goes directly into link=
				if (link_target !== 'new') {
					shortcode += ` link_target="${link_target}"`;
				}
			} else if (link !== 'custom') {
				shortcode += ` link="${link}"`;
			}
		}

		shortcode += ']';
		return shortcode;
	};
	
	const albumOptions = [
		{ label: __('Select an album...', 'gallery-for-immich'), value: '' },
		...availableAlbums
			.sort((a, b) => a.name.localeCompare(b.name))
			.map(albumItem => ({
				label: albumItem.name,
				value: albumItem.id
			}))
	];
	
	const showOptions = [
		{ label: __('Gallery name', 'gallery-for-immich'), value: 'gallery_name' },
		{ label: __('Gallery description', 'gallery-for-immich'), value: 'gallery_description' },
		{ label: __('Asset date', 'gallery-for-immich'), value: 'asset_date' },
		{ label: __('Asset description', 'gallery-for-immich'), value: 'asset_description' }
	];
	
	// Sort options that apply to the chosen mode: albums sort by name, photos by description.
	// 'multiple' has none: its albums are shown in the order they were selected.
	const getOrderOptions = (forMode) => {
		const options = [
			{ label: __('Default', 'gallery-for-immich'), value: '' },
			{ label: __('Newest first', 'gallery-for-immich'), value: 'date_desc' },
			{ label: __('Oldest first', 'gallery-for-immich'), value: 'date_asc' },
		];
		if (forMode === 'overview') {
			options.push(
				{ label: __('A-Z', 'gallery-for-immich'), value: 'name_asc' },
				{ label: __('Z-A', 'gallery-for-immich'), value: 'name_desc' }
			);
		} else if (forMode === 'single') {
			options.push(
				{ label: __('A-Z by description', 'gallery-for-immich'), value: 'description_asc' },
				{ label: __('Z-A by description', 'gallery-for-immich'), value: 'description_desc' }
			);
		}
		return options;
	};
	const orderOptions = getOrderOptions(mode);
	
	const handleShowToggle = (value, checked) => {
		const newShow = checked
			? [...show, value]
			: show.filter(item => item !== value);
		setAttributes({ show: newShow });
	};

	const handleDetailShowToggle = (value, checked) => {
		const newDetailShow = checked
			? [...detail_show, value]
			: detail_show.filter(item => item !== value);
		setAttributes({ detail_show: newDetailShow });
	};
	
	const handleAlbumToggle = (albumId, checked) => {
		const newAlbums = checked
			? [...albums, albumId]
			: albums.filter(id => id !== albumId);
		setAttributes({ albums: newAlbums });
	};
	
	const handleModeChange = (newMode) => {
		// Clear mode-specific attributes when switching modes
		const updates = { mode: newMode };
		
		if (newMode !== 'asset') {
			updates.asset = '';
		}
		if (newMode !== 'single') {
			updates.album = '';
		}
		if (newMode !== 'multiple') {
			updates.albums = [];
		}
		if (newMode !== 'overview' && newMode !== 'multiple') {
			updates.detail_show = undefined;
		}
		if (newMode !== 'asset' && newMode !== 'single') {
			updates.link = 'lightbox';
			updates.link_url = '';
			updates.link_target = 'new';
		}
		// Drop a sort order that does not apply to the new mode.
		if (newMode === 'multiple' || !getOrderOptions(newMode).some(option => option.value === order)) {
			updates.order = '';
		}
		
		setAttributes(updates);
	};
	
	return (
		<div {...blockProps}>
			<InspectorControls>
				<PanelBody title={__('Gallery Settings', 'gallery-for-immich')} initialOpen={true}>
					<RadioControl
						label={__('Display Mode', 'gallery-for-immich')}
						selected={mode}
						options={[
							{ label: __('All albums overview', 'gallery-for-immich'), value: 'overview' },
							{ label: __('Single album', 'gallery-for-immich'), value: 'single' },
							{ label: __('Multiple albums', 'gallery-for-immich'), value: 'multiple' },
							{ label: __('Single photo', 'gallery-for-immich'), value: 'asset' }
						]}
						onChange={handleModeChange}
					/>
					
				{mode === 'asset' && (
				<>
					<TextControl
						label={__('Photo ID', 'gallery-for-immich')}
						value={asset}
						onChange={(value) => setAttributes({ asset: value })}
						placeholder="e.g., 3c874076-ba9e-410a-8501-ef3cca897bcd"
						help={__('Enter the photo ID from your Immich URL', 'gallery-for-immich')}
					/>
					<SelectControl
						label={__('Alignment', 'gallery-for-immich')}
						value={attributes.align || 'none'}
						onChange={(value) => setAttributes({ align: value })}
						options={[
							{ label: __('Default (none)', 'gallery-for-immich'), value: 'none' },
							{ label: __('Left (text wraps right)', 'gallery-for-immich'), value: 'left' },
							{ label: __('Right (text wraps left)', 'gallery-for-immich'), value: 'right' },
							{ label: __('Center', 'gallery-for-immich'), value: 'center' }
						]}
						help={__('Choose alignment for text wrapping around the photo', 'gallery-for-immich')}
					/>
				</>
				)}					{mode === 'single' && (
						<SelectControl
							label={__('Select Album', 'gallery-for-immich')}
							value={album}
							options={albumOptions}
							onChange={(value) => setAttributes({ album: value })}
							disabled={loading}
						/>
					)}

					
					{mode === 'multiple' && !loading && availableAlbums.length > 0 && (
						<div style={{ marginTop: '12px' }}>
							<label style={{ fontWeight: 600, marginBottom: '8px', display: 'block' }}>
								{__('Select Albums', 'gallery-for-immich')}
							</label>
							<p style={{ marginTop: 0, fontSize: '12px', color: '#757575' }}>
								{__('Albums are shown in the order you select them.', 'gallery-for-immich')}
							</p>
							{availableAlbums.map(albumItem => (
								<CheckboxControl
									key={albumItem.id}
									label={albumItem.name}
									checked={albums.includes(albumItem.id)}
									onChange={(checked) => handleAlbumToggle(albumItem.id, checked)}
								/>
							))}
						</div>
					)}
					
					{(mode === 'overview' || mode === 'single') && (
						<SelectControl
							label={__('Sort Order', 'gallery-for-immich')}
							value={order}
							options={orderOptions}
							onChange={(value) => setAttributes({ order: value })}
						/>
					)}

					{mode !== 'asset' && (
						<TextControl
							label={mode === 'single' ? __('Maximum number of photos', 'gallery-for-immich') : __('Maximum number of albums', 'gallery-for-immich')}
							type="number"
							min={0}
							max={1000}
							value={limit}
							onChange={(value) => {
								const n = parseInt(value, 10);
								setAttributes({ limit: n > 0 ? Math.min(n, 1000) : 0 });
							}}
							help={__('0 shows all', 'gallery-for-immich')}
						/>
					)}

					{mode !== 'asset' && limit > 0 && (
						<SelectControl
							label={__('Which ones', 'gallery-for-immich')}
							value={pick}
							options={[
								{ label: __('First in sort order', 'gallery-for-immich'), value: 'first'  },
								{ label: __('Random selection',    'gallery-for-immich'), value: 'random' },
							]}
							onChange={(value) => setAttributes({ pick: value })}
						/>
					)}

					{(mode === 'asset' || mode === 'single') && (
					<>
					<SelectControl
						label={__('Link behavior', 'gallery-for-immich')}
						value={link}
						onChange={(value) => setAttributes({ link: value, link_url: '', link_target: 'new' })}
						options={[
							{ label: __('Lightbox (default)', 'gallery-for-immich'), value: 'lightbox' },
							{ label: __('No link',            'gallery-for-immich'), value: 'none'     },
							{ label: __('Custom URL',         'gallery-for-immich'), value: 'custom'   },
						]}
						help={mode === 'single'
							? __('Choose what happens when a photo is clicked, e.g. link a single latest photo to your full gallery page', 'gallery-for-immich')
							: __('Choose how the photo behaves when clicked', 'gallery-for-immich')}
					/>
					{link === 'custom' && (
						<TextControl
							label={__('Link URL', 'gallery-for-immich')}
							value={link_url}
							onChange={(value) => setAttributes({ link_url: value })}
							placeholder="https://..."
							type="url"
							help={__('The URL to open when the photo is clicked', 'gallery-for-immich')}
						/>
					)}
					{link === 'custom' && (
						<ToggleControl
							label={__('Open in new tab', 'gallery-for-immich')}
							checked={link_target === 'new'}
							onChange={(checked) => setAttributes({ link_target: checked ? 'new' : 'same' })}
						/>
					)}
					</>
					)}
				
				<RangeControl
					label={mode === 'asset' ? __('Max width', 'gallery-for-immich') : __('Thumbnail Size', 'gallery-for-immich')}
					value={size || 200}
					onChange={(value) => setAttributes({ size: value })}
					min={100}
					max={mode === 'asset' ? 1200 : 500}
					step={50}
				/>
			</PanelBody>
			
			<PanelBody title={__('Text Sizes', 'gallery-for-immich')} initialOpen={false}>
				<RangeControl
					label={__('Title Size', 'gallery-for-immich')}
					value={title_size || 16}
					onChange={(value) => setAttributes({ title_size: value })}
					min={10}
					max={30}
					step={1}
				/>
				
				<RangeControl
					label={__('Description Size', 'gallery-for-immich')}
					value={description_size || 14}
					onChange={(value) => setAttributes({ description_size: value })}
					min={10}
					max={30}
					step={1}
				/>
				
				<RangeControl
					label={__('Date Size', 'gallery-for-immich')}
					value={date_size || 13}
					onChange={(value) => setAttributes({ date_size: value })}
					min={10}
					max={30}
					step={1}
				/>
			</PanelBody>				<PanelBody title={__('Display Options', 'gallery-for-immich')} initialOpen={false}>
					{showOptions.map(option => (
						<CheckboxControl
							key={option.value}
							label={option.label}
							checked={show.includes(option.value)}
							onChange={(checked) => handleShowToggle(option.value, checked)}
						/>
					))}

					{hasAlbumPages && (
						<>
							<ToggleControl
								label={__('Same on album page', 'gallery-for-immich')}
								help={__('The page that opens when a visitor clicks an album', 'gallery-for-immich')}
								checked={!Array.isArray(detail_show)}
								onChange={(checked) => setAttributes({ detail_show: checked ? undefined : [...show] })}
							/>
							{Array.isArray(detail_show) && (
								<p style={{ fontWeight: 600, margin: '16px 0 8px' }}>
									{__('On the album page', 'gallery-for-immich')}
								</p>
							)}
							{Array.isArray(detail_show) && showOptions.map(option => (
								<CheckboxControl
									key={`detail-${option.value}`}
									label={option.label}
									checked={detail_show.includes(option.value)}
									onChange={(checked) => handleDetailShowToggle(option.value, checked)}
								/>
							))}
						</>
					)}
				</PanelBody>
			</InspectorControls>
			
			<div style={{ 
				padding: '20px', 
				backgroundColor: (mode === 'single' && !album) || (mode === 'multiple' && albums.length === 0) || (mode === 'asset' && !asset) ? '#fef2f2' : '#f0f0f0', 
				borderRadius: '4px',
				fontFamily: 'monospace',
				fontSize: '13px',
				border: (mode === 'single' && !album) || (mode === 'multiple' && albums.length === 0) || (mode === 'asset' && !asset) ? '2px solid #ef4444' : 'none'
			}}>
				{loading ? (
					<p>{__('Loading albums...', 'gallery-for-immich')}</p>
				) : (
					<div>
						<p style={{ marginBottom: '8px', fontWeight: 'bold', fontSize: '14px' }}>
							{__('Gallery for Immich', 'gallery-for-immich')}
						</p>
						
						{mode === 'single' && !album && (
							<div style={{ 
								padding: '12px', 
								backgroundColor: '#fee2e2', 
								border: '1px solid #ef4444',
								borderRadius: '4px',
								marginBottom: '12px',
								color: '#991b1b'
							}}>
								<strong>{__('⚠️ No album selected', 'gallery-for-immich')}</strong>
								<p style={{ margin: '4px 0 0 0', fontSize: '12px' }}>
									{__('Please select an album from the sidebar to display.', 'gallery-for-immich')}
								</p>
							</div>
						)}
						
						{mode === 'multiple' && albums.length === 0 && (
							<div style={{ 
								padding: '12px', 
								backgroundColor: '#fee2e2', 
								border: '1px solid #ef4444',
								borderRadius: '4px',
								marginBottom: '12px',
								color: '#991b1b'
							}}>
								<strong>{__('⚠️ No albums selected', 'gallery-for-immich')}</strong>
								<p style={{ margin: '4px 0 0 0', fontSize: '12px' }}>
									{__('Please select at least one album from the sidebar to display.', 'gallery-for-immich')}
								</p>
							</div>
						)}
						
						{mode === 'asset' && !asset && (
							<div style={{ 
								padding: '12px', 
								backgroundColor: '#fee2e2', 
								border: '1px solid #ef4444',
								borderRadius: '4px',
								marginBottom: '12px',
								color: '#991b1b'
							}}>
								<strong>{__('⚠️ No photo ID entered', 'gallery-for-immich')}</strong>
								<p style={{ margin: '4px 0 0 0', fontSize: '12px' }}>
									{__('Please enter a photo ID from your Immich server.', 'gallery-for-immich')}
								</p>
							</div>
						)}
						
						<code style={{ display: 'block', padding: '8px', backgroundColor: 'white', borderRadius: '3px', fontSize: '12px' }}>
							{generateShortcode()}
						</code>
						{mode === 'overview' && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Will display all albums from Immich', 'gallery-for-immich')}
							</p>
						)}
						{mode === 'asset' && asset && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Single photo: ', 'gallery-for-immich')}{asset.substring(0, 8)}...
							</p>
						)}
						{mode === 'single' && album && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Selected: ', 'gallery-for-immich')}
								{availableAlbums.find(a => a.id === album)?.name || album}
							</p>
						)}
						{mode === 'multiple' && albums.length > 0 && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Selected albums: ', 'gallery-for-immich')}{albums.length}
							</p>
						)}
					</div>
				)}
			</div>
		</div>
	);
};

registerBlockType('gallery-for-immich/gallery', {
	edit: Edit,
	save: () => null
});
