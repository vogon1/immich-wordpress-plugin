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
		{ label: __('Select an album...', 'immich-gallery'), value: '' },
		...availableAlbums
			.sort((a, b) => a.name.localeCompare(b.name))
			.map(albumItem => ({
				label: albumItem.name,
				value: albumItem.id
			}))
	];
	
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
				<PanelBody title={__('Gallery Settings', 'immich-gallery')} initialOpen={true}>
					<RadioControl
						label={__('Display Mode', 'immich-gallery')}
						selected={mode}
						options={[
							{ label: __('All albums overview', 'immich-gallery'), value: 'overview' },
							{ label: __('Single album', 'immich-gallery'), value: 'single' },
							{ label: __('Multiple albums', 'immich-gallery'), value: 'multiple' },
							{ label: __('Single photo', 'immich-gallery'), value: 'asset' }
						]}
						onChange={handleModeChange}
					/>
					
				{mode === 'asset' && (
					<TextControl
						label={__('Photo ID', 'immich-gallery')}
						value={asset}
						onChange={(value) => setAttributes({ asset: value })}
						placeholder="e.g., 3c874076-ba9e-410a-8501-ef3cca897bcd"
						help={__('Enter the photo ID from your Immich URL', 'immich-gallery')}
					/>
				)}					{mode === 'single' && (
						<SelectControl
							label={__('Select Album', 'immich-gallery')}
							value={album}
							options={albumOptions}
							onChange={(value) => setAttributes({ album: value })}
							disabled={loading}
						/>
					)}
					
					{mode === 'multiple' && !loading && availableAlbums.length > 0 && (
						<div style={{ marginTop: '12px' }}>
							<label style={{ fontWeight: 600, marginBottom: '8px', display: 'block' }}>
								{__('Select Albums', 'immich-gallery')}
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
					
					<SelectControl
						label={__('Sort Order', 'immich-gallery')}
						value={order}
						options={orderOptions}
					onChange={(value) => setAttributes({ order: value })}
				/>
				
				<RangeControl
					label={__('Thumbnail Size', 'immich-gallery')}
					value={size || 200}
					onChange={(value) => setAttributes({ size: value })}
					min={100}
					max={500}
					step={50}
				/>
			</PanelBody>
			
			<PanelBody title={__('Text Sizes', 'immich-gallery')} initialOpen={false}>
				<RangeControl
					label={__('Title Size', 'immich-gallery')}
					value={title_size || 16}
					onChange={(value) => setAttributes({ title_size: value })}
					min={10}
					max={30}
					step={1}
				/>
				
				<RangeControl
					label={__('Description Size', 'immich-gallery')}
					value={description_size || 14}
					onChange={(value) => setAttributes({ description_size: value })}
					min={10}
					max={30}
					step={1}
				/>
				
				<RangeControl
					label={__('Date Size', 'immich-gallery')}
					value={date_size || 13}
					onChange={(value) => setAttributes({ date_size: value })}
					min={10}
					max={30}
					step={1}
				/>
			</PanelBody>				<PanelBody title={__('Display Options', 'immich-gallery')} initialOpen={false}>
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
					<p>{__('Loading albums...', 'immich-gallery')}</p>
				) : (
					<div>
						<p style={{ marginBottom: '8px', fontWeight: 'bold', fontSize: '14px' }}>
							{__('Immich Gallery', 'immich-gallery')}
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
								<strong>{__('⚠️ No album selected', 'immich-gallery')}</strong>
								<p style={{ margin: '4px 0 0 0', fontSize: '12px' }}>
									{__('Please select an album from the sidebar to display.', 'immich-gallery')}
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
								<strong>{__('⚠️ No albums selected', 'immich-gallery')}</strong>
								<p style={{ margin: '4px 0 0 0', fontSize: '12px' }}>
									{__('Please select at least one album from the sidebar to display.', 'immich-gallery')}
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
								<strong>{__('⚠️ No photo ID entered', 'immich-gallery')}</strong>
								<p style={{ margin: '4px 0 0 0', fontSize: '12px' }}>
									{__('Please enter a photo ID from your Immich server.', 'immich-gallery')}
								</p>
							</div>
						)}
						
						<code style={{ display: 'block', padding: '8px', backgroundColor: 'white', borderRadius: '3px', fontSize: '12px' }}>
							{generateShortcode()}
						</code>
						{mode === 'overview' && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Will display all albums from Immich', 'immich-gallery')}
							</p>
						)}
						{mode === 'asset' && asset && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Single photo: ', 'immich-gallery')}{asset.substring(0, 8)}...
							</p>
						)}
						{mode === 'single' && album && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Selected: ', 'immich-gallery')}
								{availableAlbums.find(a => a.id === album)?.name || album}
							</p>
						)}
						{mode === 'multiple' && albums.length > 0 && (
							<p style={{ marginTop: '8px', fontSize: '11px', color: '#666' }}>
								{__('Selected albums: ', 'immich-gallery')}{albums.length}
							</p>
						)}
					</div>
				)}
			</div>
		</div>
	);
};

registerBlockType('immich-gallery/gallery', {
	edit: Edit,
	save: () => null
});
