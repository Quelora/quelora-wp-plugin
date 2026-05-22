/*
 * Quelora — quelora-wp-plugin
 * Copyright (C) 2026 Germán Zelaya — https://quelora.org
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * This file is part of Quelora. See the LICENSE file for terms.
 */

/**
 * @file index.js
 * @description Entry point for the Quelora Sidebar Integration.
 * Registers a Document Setting Panel in the Gutenberg Sidebar to manage 
 * post-specific Quelora interactions via metadata.
 * @author Quelora Architecture Team
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import QueloraSidebar from './sidebar';

/**
 * Registers the Quelora Sidebar Plugin.
 * This component renders a native panel in the "Post" settings tab, 
 * providing a seamless UX for news editors.
 */
registerPlugin( 'quelora-sidebar-plugin', {
	render: () => {
		return (
			<PluginDocumentSettingPanel
				name="quelora-sidebar-panel"
				title={ __( 'Quelora Community', 'quelora' ) }
				className="quelora-sidebar-panel"
			>
				<QueloraSidebar />
			</PluginDocumentSettingPanel>
		);
	},
	icon: 'admin-comments',
} );