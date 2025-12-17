import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, CheckboxControl, RadioControl, RangeControl, TextControl } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const Edit = ({ attributes, setAttributes }) => {
	const { mode, album, albums, asset, show, order, size, title_size, description_size, date_size } = attributes;
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
	
	const orderOptions = [
		{ label: __('Default', 'gallery-for-immich'), value: '' },
		{ label: __('Newest first', 'gallery-for-immich'), value: 'date_desc' },
		{ label: __('Oldest first', 'gallery-for-immich'), value: 'date_asc' },
		{ label: __('A-Z (albums only)', 'gallery-for-immich'), value: 'name_asc' },
		{ label: __('Z-A (albums only)', 'gallery-for-immich'), value: 'name_desc' },
		{ label: __('A-Z by description (photos only)', 'gallery-for-immich'), value: 'description_asc' },
		{ label: __('Z-A by description (photos only)', 'gallery-for-immich'), value: 'description_desc' }
	];
	
	const handleShowToggle = (value, checked) => {
		const newShow = checked
			? [...show, value]
			: show.filter(item => item !== value);
		setAttributes({ show: newShow });
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
					
					{mode !== 'asset' && (
						<SelectControl
							label={__('Sort Order', 'gallery-for-immich')}
							value={order}
							options={orderOptions}
							onChange={(value) => setAttributes({ order: value })}
						/>
					)}
				
				<RangeControl
					label={__('Thumbnail Size', 'gallery-for-immich')}
					value={size || 200}
					onChange={(value) => setAttributes({ size: value })}
					min={100}
					max={500}
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
