#!/usr/bin/env node
/**
 * Test suite for Gallery for Immich, run against wp-env: `npm run test`.
 *
 * Needs a running wp-env (`npm run wp-env:start`) with a working Immich server URL and API key
 * in the plugin settings (see TESTING.md). The Immich server needs two albums with three or
 * more photos. Test pages are created before and removed after the run.
 *
 * Set BASE_URL to test another address than http://localhost:8888.
 */

const { execSync } = require( 'child_process' );
const path = require( 'path' );

const BASE_URL = ( process.env.BASE_URL || 'http://localhost:8888' ).replace( /\/$/, '' );
const PLUGIN_DIR = path.resolve( __dirname, '..' );
const UUID = 'a4138866-79fa-4e8a-af6a-1708463301b4';

const results = [];
let section = '';

function check( name, ok, detail = '' ) {
	results.push( { section, name, ok: !! ok, detail: ok ? '' : String( detail ) } );
}

/** Run a PHP file from tests/ inside wp-env and return the JSON it printed. */
function wpEval( file ) {
	const out = execSync(
		`npx wp-env run cli wp eval-file wp-content/plugins/gallery-for-immich/tests/${ file }`,
		{ cwd: PLUGIN_DIR, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	const match = out.match( /@@GFI-JSON@@([\s\S]*?)@@GFI-JSON@@/ );
	if ( ! match ) {
		throw new Error( `No output from tests/${ file }:\n${ out }` );
	}
	return JSON.parse( match[ 1 ] );
}

/** Run a WP-CLI command inside wp-env. */
function wpCli( args ) {
	execSync( `npx wp-env run cli wp ${ args }`, {
		cwd: PLUGIN_DIR,
		stdio: [ 'ignore', 'ignore', 'pipe' ],
	} );
}

function setVideoMode( mode ) {
	wpCli( `option patch update gallery_for_immich_settings video_mode ${ mode }` );
}

async function get( url, headers = {} ) {
	// Follow redirects: WordPress sends e.g. ?page_id=… to the pretty permalink.
	const res = await fetch( url.startsWith( 'http' ) ? url : BASE_URL + url, { headers } );
	const body = Buffer.from( await res.arrayBuffer() );
	return { status: res.status, headers: res.headers, body, text: body.toString( 'utf8' ) };
}

const proxyAssetIds = ( html ) =>
	[ ...html.matchAll( /gallery_for_immich_proxy=thumbnail&(?:amp;|#038;)?id=([a-f0-9-]{36})/gi ) ]
		.map( ( m ) => m[ 1 ] )
		.sort();

/** Log in through wp-login.php and return the cookies as one Cookie header. */
async function logIn( login, password ) {
	const res = await fetch( `${ BASE_URL }/wp-login.php`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
			Cookie: 'wordpress_test_cookie=WP%20Cookie%20check',
		},
		body: new URLSearchParams( { log: login, pwd: password, testcookie: '1' } ),
		redirect: 'manual',
	} );
	return res.headers.getSetCookie().map( ( c ) => c.split( ';' )[ 0 ] ).join( '; ' );
}

/** Undo (repeated) HTML escaping, as in a data-content attribute holding escaped HTML. */
function decodeEntities( text ) {
	let prev;
	do {
		prev = text;
		text = text.replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&quot;/g, '"' ).replace( /&#0?38;/g, '&' ).replace( /&amp;/g, '&' );
	} while ( text !== prev );
	return text;
}

/** The video URL in a lightbox link of a rendered page, or ''. */
function videoSource( html ) {
	const content = html.match( /data-content="([^"]*)"/ );
	const src = content && decodeEntities( content[ 1 ] ).match( /<source src="([^"]+)"/ );
	return src ? src[ 1 ] : '';
}

/** Call the Live Photo endpoint the way the lightbox script does, from the URL the page prints. */
async function livePhotoRequest( pageUrl, live ) {
	const html = ( await get( pageUrl ) ).text;
	const match = html.match( /var immichLivePhotoUrl = ("[^"]*")/ );
	if ( ! match ) {
		return { status: 0, code: 'immichLivePhotoUrl not found on the page', endpoint: '' };
	}
	const endpoint = JSON.parse( match[ 1 ] );
	const url = endpoint + ( endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) +
		`asset_id=${ live.asset_id }&sig=${ live.public_sig }&post=0`;
	const res = await get( url );
	let code = '';
	try {
		code = JSON.parse( res.text ).code;
	} catch ( e ) {}
	return { status: res.status, code, endpoint };
}

async function httpTests( setup ) {
	const { urls, pages } = setup;

	section = 'Proxy';
	check( 'invalid type → 400', ( await get( `/?gallery_for_immich_proxy=invalid&id=${ UUID }` ) ).status === 400 );
	check( 'invalid UUID → 400', ( await get( '/?gallery_for_immich_proxy=thumbnail&id=not-a-uuid' ) ).status === 400 );
	check( 'original → 400', ( await get( `/?gallery_for_immich_proxy=original&id=${ UUID }` ) ).status === 400 );
	check( 'unsigned URL → 403', ( await get( `/?gallery_for_immich_proxy=thumbnail&id=${ setup.asset }` ) ).status === 403 );
	check( 'wrong signature → 403', ( await get( urls.public.replace( /sig=[a-f0-9]+/, 'sig=' + 'a'.repeat( 64 ) ) ) ).status === 403 );
	check( 'public signature with another post → 403', ( await get( urls.public + `&post=${ pages.password.id }` ) ).status === 403 );

	const thumb = await get( urls.public );
	check( 'signed thumbnail → 200', thumb.status === 200, thumb.status );
	check( 'thumbnail has its real type (WebP)', thumb.headers.get( 'content-type' ) === 'image/webp', thumb.headers.get( 'content-type' ) );
	check( 'thumbnail body is WebP', thumb.body.subarray( 8, 12 ).toString() === 'WEBP' );
	check( 'public page image may be cached publicly', /^public/.test( thumb.headers.get( 'cache-control' ) || '' ), thumb.headers.get( 'cache-control' ) );

	const preview = await get( urls.preview );
	check( 'preview is JPEG', preview.status === 200 && preview.headers.get( 'content-type' ) === 'image/jpeg', preview.headers.get( 'content-type' ) );

	section = 'Password-protected and private pages';
	const anonPage = await get( pages.password.url );
	check( 'without password the page shows no photos', anonPage.status === 200 && ! anonPage.text.includes( 'gallery_for_immich_proxy' ) );
	const anonImage = await get( urls.password );
	check( 'image URL from the page without password → 403', anonImage.status === 403, anonImage.status );
	check( 'a refusal is not cached', /no-cache|no-store/.test( anonImage.headers.get( 'cache-control' ) || '' ), anonImage.headers.get( 'cache-control' ) );

	const login = await fetch( `${ BASE_URL }/wp-login.php?action=postpass`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { post_password: setup.password } ),
		redirect: 'manual',
	} );
	const cookie = login.headers.getSetCookie().map( ( c ) => c.split( ';' )[ 0 ] ).filter( ( c ) => c.startsWith( 'wp-postpass_' ) ).join( '; ' );
	check( 'entering the password sets the cookie', !! cookie );

	const page = await get( pages.password.url, { Cookie: cookie } );
	check( 'with password the page shows photos', proxyAssetIds( page.text ).length > 0 );
	check( 'its image URLs carry the post ID', page.text.includes( `post=${ pages.password.id }` ) );
	const image = await get( urls.password, { Cookie: cookie } );
	check( 'image URL with password → 200', image.status === 200, image.status );
	check( 'protected image is private to the browser', /^private/.test( image.headers.get( 'cache-control' ) || '' ), image.headers.get( 'cache-control' ) );

	const live = setup.live_photo;
	const liveUrl = `/wp-json/gallery-for-immich/v1/live-photo-url?asset_id=${ live.asset_id }&sig=${ live.sig }&post=${ live.post }`;
	check( 'Live Photo of a protected page without password → 403', ( await get( liveUrl ) ).status === 403 );

	check( 'private page image for a visitor → 403', ( await get( urls.private ) ).status === 403 );
	check( 'draft image for a visitor → 403', ( await get( urls.draft ) ).status === 403 );

	section = 'Logged-in editor';
	const editor = await logIn( setup.editor.login, setup.editor.password );
	check( 'the editor can log in', /wordpress_logged_in_/.test( editor ) );
	const privatePage = await get( pages.private.url, { Cookie: editor } );
	check( 'private page shows its photos to the editor', privatePage.text.includes( `post=${ pages.private.id }` ) );
	const privateImage = await get( urls.private, { Cookie: editor } );
	check( 'private page image for the editor → 200', privateImage.status === 200, privateImage.status );
	check( 'private page image is private to the browser', /^private/.test( privateImage.headers.get( 'cache-control' ) || '' ), privateImage.headers.get( 'cache-control' ) );
	const draftPage = await get( pages.draft.url, { Cookie: editor } );
	check( 'draft preview shows its photos to the editor', draftPage.text.includes( `post=${ pages.draft.id }` ) );
	check( 'draft image for the editor → 200', ( await get( urls.draft, { Cookie: editor } ) ).status === 200 );

	section = 'Permalinks';
	const pretty = await livePhotoRequest( pages.public.url, setup.live_photo );
	check( 'Live Photo request reaches the endpoint (pretty permalinks)', pretty.status === 404 && pretty.code === 'not_found', `${ pretty.status } ${ pretty.code } ${ pretty.endpoint }` );
	wpCli( `eval 'update_option("permalink_structure", ""); flush_rewrite_rules();'` );
	const plainLive = await livePhotoRequest( `${ BASE_URL }/?page_id=${ pages.public.id }`, setup.live_photo );
	check( 'plain permalinks use ?rest_route=', plainLive.endpoint.includes( 'rest_route=' ), plainLive.endpoint );
	check( 'Live Photo request reaches the endpoint (plain permalinks)', plainLive.status === 404 && plainLive.code === 'not_found', `${ plainLive.status } ${ plainLive.code } ${ plainLive.endpoint }` );
	const plainPage = await get( `${ BASE_URL }/?page_id=${ pages.public.id }` );
	const plainImages = proxyAssetIds( plainPage.text );
	check( 'photos render with plain permalinks', plainImages.length > 0 );
	const plainImageUrl = decodeEntities( ( plainPage.text.match( /src="([^"]*gallery_for_immich_proxy=thumbnail[^"]*)"/ ) || [] )[ 1 ] || '' );
	check( 'their image URLs work with plain permalinks', plainImageUrl && ( await get( plainImageUrl ) ).status === 200, plainImageUrl );
	wpCli( `eval 'update_option("permalink_structure", ${ JSON.stringify( setup.permalinks || '/%postname%/' ) }); flush_rewrite_rules();'` );

	section = 'Album from the URL';
	const plain = await get( pages.public.url );
	const other = await get( pages.public.url + ( pages.public.url.includes( '?' ) ? '&' : '?' ) + `gallery_for_immich=${ setup.album_b }` );
	check( 'album= page ignores another album in the URL', proxyAssetIds( plain.text ).length > 0 && proxyAssetIds( plain.text ).join() === proxyAssetIds( other.text ).join() );
	const sep = pages.overview.url.includes( '?' ) ? '&' : '?';
	const overviewOther = await get( `${ pages.overview.url }${ sep }gallery_for_immich=${ setup.album_b }` );
	check( 'albums= overview ignores an album outside its list', overviewOther.text.includes( `gallery_for_immich=${ setup.album_a }` ) && ! overviewOther.text.includes( 'class="immich-grid immich-album-grid"' ) );
	const overviewOwn = await get( `${ pages.overview.url }${ sep }gallery_for_immich=${ setup.album_a }` );
	check( 'albums= overview opens an album from its list', overviewOwn.text.includes( 'class="immich-grid immich-album-grid"' ) );

	section = 'REST';
	check( '/albums without login → 401', ( await get( '/wp-json/gallery-for-immich/v1/albums' ) ).status === 401 );
	check( 'live-photo-url with invalid ID → 400', ( await get( '/wp-json/gallery-for-immich/v1/live-photo-url?asset_id=invalid' ) ).status === 400 );
	check( 'live-photo-url without sig → 400', ( await get( `/wp-json/gallery-for-immich/v1/live-photo-url?asset_id=${ UUID }` ) ).status === 400 );
	check( 'live-photo-url with wrong sig → 403', ( await get( `/wp-json/gallery-for-immich/v1/live-photo-url?asset_id=${ UUID }&sig=${ 'a'.repeat( 64 ) }` ) ).status === 403 );

	section = 'Video';
	if ( ! urls.video ) {
		check( 'Immich has a video to test streaming with', false, 'no video found; streaming not tested' );
		return;
	}
	setVideoMode( 'shared' );
	const sharedPage = await get( pages.video_public.url );
	const sharedSrc = videoSource( sharedPage.text );
	check( 'mode "Shared links": a public page plays the video from an Immich shared link', sharedSrc.includes( '/video/playback?key=' ) && ! sharedSrc.includes( 'gallery_for_immich_proxy' ), sharedSrc || 'no video source' );
	const sharedVideo = sharedSrc ? await get( sharedSrc, { Range: 'bytes=0-99' } ) : { status: 0 };
	check( 'the shared link streams the video → 206', sharedVideo.status === 206, sharedVideo.status );

	const protectedPage = await get( pages.video_password.url, { Cookie: cookie } );
	const protectedSrc = videoSource( protectedPage.text );
	check( 'mode "Shared links": a password page streams the video through WordPress', protectedSrc.includes( 'gallery_for_immich_proxy=video' ) && protectedSrc.includes( `post=${ pages.video_password.id }` ), protectedSrc || 'no video source' );
	if ( protectedSrc ) {
		check( 'that video without the password → 403', ( await get( protectedSrc, { Range: 'bytes=0-99' } ) ).status === 403 );
		const protectedVideo = await get( protectedSrc, { Range: 'bytes=0-99', Cookie: cookie } );
		check( 'that video with the password → 206, private', protectedVideo.status === 206 && /^private/.test( protectedVideo.headers.get( 'cache-control' ) || '' ), `${ protectedVideo.status } ${ protectedVideo.headers.get( 'cache-control' ) }` );
	}

	setVideoMode( 'fopen' );
	const video = await get( urls.video, { Range: 'bytes=0-99' } );
	check( 'video streams with Range support → 206', video.status === 206, video.status );
	check( 'video sends exactly the requested bytes', video.body.length === 100, video.body.length );
	check( 'video has a video type', /^video\//.test( video.headers.get( 'content-type' ) || '' ), video.headers.get( 'content-type' ) );
	check( 'video passes Content-Range on', /^bytes 0-99\//.test( video.headers.get( 'content-range' ) || '' ), video.headers.get( 'content-range' ) );
	setVideoMode( 'ignore' );
	check( 'video in mode "ignore" → 403', ( await get( urls.video, { Range: 'bytes=0-99' } ) ).status === 403 );
}

async function main() {
	try {
		const ping = await fetch( BASE_URL + '/' );
		if ( ! ping.ok ) throw new Error( `HTTP ${ ping.status }` );
	} catch ( e ) {
		console.error( `wp-env is not reachable at ${ BASE_URL } (${ e.message }). Start it with: npm run wp-env:start` );
		process.exit( 2 );
	}

	console.log( 'Setting up test pages…' );
	const setup = wpEval( 'setup.php' );
	if ( setup.error ) {
		console.error( setup.error );
		wpEval( 'cleanup.php' );
		process.exit( 2 );
	}

	try {
		section = 'Internals';
		for ( const r of wpEval( 'unit.php' ).results ) {
			check( r.name, r.ok, r.detail );
		}
		await httpTests( setup );
	} catch ( e ) {
		check( 'test run completed', false, e.stack );
	} finally {
		console.log( 'Removing test pages…' );
		wpEval( 'cleanup.php' );
	}

	let current = '';
	for ( const r of results ) {
		if ( r.section !== current ) {
			current = r.section;
			console.log( `\n${ current }` );
		}
		console.log( `  ${ r.ok ? '✓' : '✗' } ${ r.name }${ r.ok ? '' : `\n      ${ r.detail }` }` );
	}

	const failed = results.filter( ( r ) => ! r.ok ).length;
	console.log( `\n${ results.length - failed } passed, ${ failed } failed` );
	process.exit( failed ? 1 : 0 );
}

main();
