/**
 * @file sidebar.js
 * @description React component for the Quelora Sidebar Panel.
 * Manages the `_quelora_active` post meta and provides the interface
 * for opening the Quelora configuration backend.
 *
 * The activation state resolves with the following precedence:
 *  1. Explicit `_quelora_active` meta saved on the post.
 *  2. Global default (`window.QueloraEditorConfig.defaultActive`) injected
 *     by the PHP layer from the plugin settings page.
 *
 * The dashboard URL is constructed as:
 *  `{dashboardUrl}{nodeId}?title=...&description=...&tags=...&category=...&language=...&link=...`
 * where `nodeId` is derived from `post-{postId}` using the shared SHA-256
 * truncation contract, and all hydration parameters are passed as GET params.
 *
 * @author Quelora Architecture Team
 */

import { useSelect, useDispatch } from '@wordpress/data';
import { ToggleControl, Button, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Returns the effective Quelora activation state for the current post.
 *
 * When the post has never been explicitly saved with a `_quelora_active`
 * meta value, WordPress returns `false` as the registered default —
 * indistinguishable from an intentional "off". This function resolves
 * that ambiguity by checking the globally injected `defaultActive` flag
 * from `window.QueloraEditorConfig`.
 *
 * A post is considered "explicitly set" once the editor has dispatched
 * an `editPost` action for `_quelora_active`, which marks the post as dirty.
 * Before that first explicit interaction, the global default governs.
 *
 * @param {Object}  meta     - The current edited post meta object.
 * @param {boolean} isDirty  - Whether the post has unsaved changes.
 * @return {boolean} The resolved activation state.
 */
function resolveActiveState( meta, isDirty ) {
	const globalDefault =
		window?.QueloraEditorConfig?.defaultActive === true;

	if ( ! isDirty && meta._quelora_active === false ) {
		return globalDefault;
	}

	return !! meta._quelora_active;
}

/**
 * Derives a 24-character hexadecimal node identifier from a raw input string.
 *
 * This function mirrors the backend identifier contract exactly:
 *  1. If the input is already a 24-char lowercase hex string, it is returned as-is.
 *  2. Otherwise, the input is encoded as UTF-8, hashed with SHA-256 via the
 *     Web Crypto API, converted to a hex string, and truncated to 24 characters.
 *
 * Input convention: `post-{postId}` (e.g. `post-34`).
 *
 * @param {string|number} input - Raw post identifier (e.g. `post-34`).
 * @return {Promise<string>} Resolves to a 24-character lowercase hex node ID.
 */
async function toNodeId( input ) {
	const str = String( input );

	if ( /^[0-9a-f]{24}$/.test( str.toLowerCase() ) ) {
		return str.toLowerCase();
	}

	const data       = new TextEncoder().encode( str );
	const hashBuffer = await crypto.subtle.digest( 'SHA-256', data );

	return Array.from( new Uint8Array( hashBuffer ) )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' )
		.substring( 0, 24 )
		.toLowerCase();
}

/**
 * Resolves the description for a post.
 *
 * Uses the post excerpt when available. Falls back to the title repeated
 * twice (separated by a space) and truncated to 1000 characters — mirroring
 * the PHP fallback logic — to ensure the backend required field is never empty.
 *
 * @param {string} excerpt - Raw post excerpt from the WordPress editor store.
 * @param {string} title   - Post title used as fallback source.
 * @return {string} Resolved description, max 1000 characters.
 */
function resolveDescription( excerpt, title ) {
	const clean = ( excerpt || '' ).replace( /(<([^>]+)>)/gi, '' ).trim();
	if ( clean.length > 0 ) {
		return clean.substring( 0, 1000 );
	}
	return ( title + ' ' + title ).substring( 0, 1000 );
}

/**
 * Builds the Quelora dashboard embed URL for a given post, including all
 * hydration parameters as URL-encoded GET query parameters.
 *
 * Parameters appended:
 *  - `title`       — Post title.
 *  - `description` — Post excerpt or fallback (max 1000 chars).
 *  - `tags`        — Comma-separated list of tag names.
 *  - `category`    — Comma-separated list of category names.
 *  - `language`    — WordPress locale string (e.g. `en_US`).
 *  - `link`        — Post permalink.
 *
 * @param {number|string} postId     - The current WordPress post ID.
 * @param {Object}        postData   - Hydration data resolved from the editor store.
 * @param {string}        postData.title       - Post title.
 * @param {string}        postData.excerpt     - Raw post excerpt (may contain HTML).
 * @param {string[]}      postData.tags        - Array of tag name strings.
 * @param {string[]}      postData.categories  - Array of category name strings.
 * @param {string}        postData.permalink   - Post permalink URL.
 * @return {Promise<string>} Resolves to the fully composed dashboard URL with GET params.
 */
async function buildDashboardUrl( postId, postData ) {
	const base = (
		window?.QueloraEditorConfig?.dashboardUrl ||
		'https://dashboard.quelora.local/embed/post/'
	).replace( /\/$/, '' );

	const nodeId = await toNodeId( `post-${ postId || 0 }` );

	const params = new URLSearchParams( {
		title:       postData.title,
		description: resolveDescription( postData.excerpt, postData.title ),
		tags:        ( postData.tags || [] ).join( ',' ),
		category:    ( postData.categories || [] ).join( ',' ),
		language:    window?.QueloraEditorConfig?.language || 'en_US',
		link:        postData.permalink || '',
	} );

	return `${ base }/${ nodeId }?${ params.toString() }`;
}

/**
 * QueloraSidebar Component.
 *
 * Renders the activation toggle and configuration button inside the
 * Gutenberg Document Settings sidebar panel.
 *
 * @return {JSX.Element} The rendered sidebar panel content.
 */
const QueloraSidebar = () => {
	const { meta, postId, isPostDirty, postData } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const core   = select( 'core' );
		const pid    = editor.getCurrentPostId();

		const tagIds      = editor.getEditedPostAttribute( 'tags' )       || [];
		const categoryIds = editor.getEditedPostAttribute( 'categories' ) || [];

		const tags = tagIds.map( ( id ) => {
			const term = core.getEntityRecord( 'taxonomy', 'post_tag', id );
			return term ? term.name : null;
		} ).filter( Boolean );

		const categories = categoryIds.map( ( id ) => {
			const term = core.getEntityRecord( 'taxonomy', 'category', id );
			return term ? term.name : null;
		} ).filter( Boolean );

		return {
			meta:        editor.getEditedPostAttribute( 'meta' ) || {},
			postId:      pid,
			isPostDirty: editor.isEditedPostDirty(),
			postData: {
				title:      editor.getEditedPostAttribute( 'title' )   || '',
				excerpt:    editor.getEditedPostAttribute( 'excerpt' )  || '',
				permalink:  editor.getPermalink()                       || '',
				tags,
				categories,
			},
		};
	}, [] );

	const { editPost } = useDispatch( 'core/editor' );

	const isActive = resolveActiveState( meta, isPostDirty );

	/**
	 * Toggles the `_quelora_active` meta field for the current post.
	 * Once called, the per-post value takes explicit precedence over
	 * the global default for the remainder of the editing session.
	 *
	 * @return {void}
	 */
	const toggleActive = () => {
		editPost( {
			meta: {
				...meta,
				_quelora_active: ! isActive,
			},
		} );
	};

	/**
	 * Opens the Quelora embedded dashboard in an isolated popup window.
	 *
	 * The popup is opened synchronously (blank URL) within the click handler
	 * to satisfy browser popup-blocker requirements, then navigated to the
	 * fully resolved URL once the async node ID and params are ready.
	 *
	 * @return {void}
	 */
	const openDashboard = () => {
		const popup = window.open(
			'',
			'QueloraNodeConfiguration',
			'width=1024,height=768,resizable=yes,scrollbars=yes,status=no,location=no'
		);

		buildDashboardUrl( postId, postData ).then( ( targetUrl ) => {
			if ( popup && ! popup.closed ) {
				popup.location.href = targetUrl;
			}
		} );
	};

	return (
		<>
			<ToggleControl
				label={ __( 'Enable Quelora', 'quelora' ) }
				help={
					isActive
						? __( 'Quelora scripts and styles are active for this post.', 'quelora' )
						: __( 'Quelora is disabled for this post.', 'quelora' )
				}
				checked={ isActive }
				onChange={ toggleActive }
			/>

			<div style={ { marginTop: '15px', borderTop: '1px solid #ddd', paddingTop: '15px' } }>
				<Button
					variant="primary"
					disabled={ ! isActive }
					onClick={ openDashboard }
					style={ { width: '100%', justifyContent: 'center' } }
				>
					{ __( 'Configure in Quelora', 'quelora' ) }
				</Button>

				<p className="description" style={ { marginTop: '10px', textAlign: 'center' } }>
					{ __( 'Official reference:', 'quelora' ) }<br />
					<ExternalLink href="https://www.quelora.org">
						www.quelora.org
					</ExternalLink>
				</p>
			</div>
		</>
	);
};

export default QueloraSidebar;