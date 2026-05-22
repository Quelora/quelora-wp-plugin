/*
 * Quelora — quelora-wp-plugin
 * Copyright (C) 2026 Germán Zelaya — https://quelora.org
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * This file is part of Quelora. See the LICENSE file for terms.
 */

/**
 * @file edit.js
 * @description React component for the Gutenberg editor interface.
 * Renders a placeholder block acting as a visual marker confirming that
 * Quelora assets are active on this post. The configuration popup targets
 * the embedded dashboard using a 24-character node ID derived from
 * `post-{postId}` via the shared Quelora identifier contract, with all
 * post hydration data passed as GET query parameters.
 *
 * @author Quelora Architecture Team
 */

import { useSelect } from '@wordpress/data';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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
 * @param {number|string} postId   - The current WordPress post ID.
 * @param {Object}        postData - Hydration data resolved from the editor store.
 * @param {string}        postData.title      - Post title.
 * @param {string}        postData.excerpt    - Raw post excerpt (may contain HTML).
 * @param {string[]}      postData.tags       - Array of tag name strings.
 * @param {string[]}      postData.categories - Array of category name strings.
 * @param {string}        postData.permalink  - Post permalink URL.
 * @return {Promise<string>} Resolves to the fully composed dashboard URL with GET params.
 */
async function buildDashboardUrl( postId, postData ) {
	const base = (
		window?.QueloraEditorConfig?.dashboardUrl ||
		'https://dashboard.quelora.dev/embed/post/'
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
 * Renders the Quelora Interaction Node editor interface.
 *
 * Displays a native Gutenberg Placeholder informing the editor that
 * Quelora assets will be injected on the frontend for this post.
 * Provides a primary action button to open the Quelora distributed
 * backend configuration in an isolated popup window, with all hydration
 * data passed as GET parameters for backend consumption.
 *
 * @function Edit
 * @return {JSX.Element} The rendered React component for the WordPress block editor.
 */
export default function Edit() {
	const blockProps = useBlockProps();

	const { postId, postData } = useSelect( ( select ) => {
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
			postId: pid,
			postData: {
				title:      editor.getEditedPostAttribute( 'title' )  || '',
				excerpt:    editor.getEditedPostAttribute( 'excerpt' ) || '',
				permalink:  editor.getPermalink()                      || '',
				tags,
				categories,
			},
		};
	}, [] );

	/**
	 * Opens the Quelora backend configuration popup for the current post.
	 *
	 * The popup is opened synchronously (blank URL) within the click handler
	 * to satisfy browser popup-blocker requirements, then navigated to the
	 * fully resolved URL — including the node ID and all hydration GET params —
	 * once the async operations complete.
	 *
	 * @return {void}
	 */
	const handleOpenConfig = () => {
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
		<div { ...blockProps }>
			<Placeholder
				icon="admin-comments"
				label={ __( 'Quelora Interaction Node', 'quelora' ) }
				instructions={ __(
					'This node is strictly active. The frontend assets and Quelora module configurations will be automatically injected on this page. Configure the interaction mechanics for this specific post directly in the Quelora Distributed Backend.',
					'quelora'
				) }
			>
				<Button variant="primary" onClick={ handleOpenConfig }>
					{ __( 'Configure Node in Quelora System', 'quelora' ) }
				</Button>
			</Placeholder>
		</div>
	);
}