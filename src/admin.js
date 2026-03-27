/**
 * @file admin.js
 * @description React Single Page Application (SPA) for the Quelora Integration settings dashboard.
 * Provides a zero-reload interface for configuration, health checking, and synchronization monitoring.
 * @author Quelora Architecture Team
 */

import { render, useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	TextControl,
	ToggleControl,
	RadioControl,
	TextareaControl,
	Notice,
	TabPanel,
	Panel,
	PanelBody,
	ProgressBar
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Global configuration data injected by the PHP backend via `wp_localize_script`.
 * @typedef {Object} QueloraAdminData
 * @property {string} ajaxUrl The WordPress AJAX endpoint URL.
 * @property {string} nonce The cryptographic nonce for request validation.
 * @property {Object} settings The initial state dictionary.
 */
const { ajaxUrl, nonce, settings: initialSettings } = window.QueloraAdminData;

/**
 * Performs a URL-encoded POST request to the WordPress AJAX endpoint.
 *
 * @param {string} action The registered AJAX action hook.
 * @param {Object} data Key-value payload to transmit.
 * @return {Promise<Object>} Resolves with the parsed JSON response.
 */
const apiPost = async ( action, data = {} ) => {
	const formData = new URLSearchParams();
	formData.append( 'action', action );
	formData.append( 'nonce', nonce );
	
	for ( const [ key, value ] of Object.entries( data ) ) {
		formData.append( key, typeof value === 'object' ? JSON.stringify( value ) : value );
	}

	const response = await fetch( ajaxUrl, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: formData,
	} );

	if ( ! response.ok ) {
		throw new Error( `HTTP Error ${ response.status }` );
	}

	const json = await response.json();
	if ( ! json.success ) {
		throw new Error( json.data.message || 'API request failed.' );
	}

	return json.data;
};

/**
 * Main application component for the Quelora Settings SPA.
 *
 * @return {JSX.Element} The rendered React application.
 */
function AdminApp() {
	const [ state, setState ] = useState( initialSettings );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	
	// Wizard specific state
	const [ connectionString, setConnectionString ] = useState( '' );
	const [ cid, setCid ] = useState( '' );
	const [ useManualUpload, setUseManualUpload ] = useState( false );
	const [ fileContent, setFileContent ] = useState( '' );

	// Health Check state
	const [ isCheckingHealth, setIsCheckingHealth ] = useState( false );
	const [ healthStatus, setHealthStatus ] = useState( null );

	// Config sync state
	const [ isSyncingConfig, setIsSyncingConfig ] = useState( false );

	// Sync state
	const [ syncPosts, setSyncPosts ] = useState( { status: 'idle', synced: 0, total: 0, lastRun: 0 } );
	const [ syncUsers, setSyncUsers ] = useState( { status: 'idle', synced: 0, total: 0, lastRun: 0 } );
	const syncIntervalRef = useRef( null );

	/**
	 * Updates a specific key in the application state.
	 *
	 * @param {string} key The state dictionary key.
	 * @param {*} value The new value.
	 * @return {void}
	 */
	const updateState = ( key, value ) => {
		setState( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	/**
	 * Displays a temporary notice message.
	 *
	 * @param {string} status 'success' or 'error'.
	 * @param {string} content The message text.
	 * @return {void}
	 */
	const showNotice = ( status, content ) => {
		setNotice( { status, content } );
		setTimeout( () => setNotice( null ), 5000 );
	};

	/**
	 * Polls the server for synchronization status.
	 *
	 * @return {Promise<void>}
	 */
	const fetchSyncStatus = async () => {
		try {
			const data = await apiPost( 'quelora_sync_status' );
			if ( data.posts ) { setSyncPosts( data.posts ); }
			if ( data.users ) { setSyncUsers( data.users ); }

			const isRunning = data.posts?.status === 'running' || data.users?.status === 'running';
			
			if ( ! isRunning && syncIntervalRef.current ) {
				clearInterval( syncIntervalRef.current );
				syncIntervalRef.current = null;
			}
		} catch ( error ) {
			console.error( 'Sync poll failed:', error );
		}
	};

	/**
	 * Triggers a specific synchronization process.
	 *
	 * @param {string} type 'posts' or 'users'.
	 * @return {Promise<void>}
	 */
	const startSync = async ( type ) => {
		if ( ! state.ssoSecretKey ) {
			showNotice( 'error', __( 'Please configure the Shared Secret Key before starting a sync.', 'quelora' ) );
			return;
		}

		try {
			await apiPost( `quelora_trigger_${ type }_sync` );
			showNotice( 'success', __( 'Sync initiated successfully.', 'quelora' ) );
			
			if ( ! syncIntervalRef.current ) {
				syncIntervalRef.current = setInterval( fetchSyncStatus, 3000 );
			}
			
			fetch( window.location.origin + '/wp-cron.php?doing_wp_cron=' + Date.now(), { mode: 'no-cors' } ).catch( () => {} );
		} catch ( error ) {
			showNotice( 'error', error.message );
		}
	};

	/**
	 * Aborts a running synchronization process.
	 *
	 * @param {string} type 'posts' or 'users'.
	 * @return {Promise<void>}
	 */
	const abortSync = async ( type ) => {
		try {
			await apiPost( 'quelora_abort_sync', { type } );
			showNotice( 'success', __( 'Sync aborted.', 'quelora' ) );
			fetchSyncStatus();
		} catch ( error ) {
			showNotice( 'error', error.message );
		}
	};

	useEffect( () => {
		if ( state.isConfigured ) {
			fetchSyncStatus();
		}
		return () => {
			if ( syncIntervalRef.current ) {
				clearInterval( syncIntervalRef.current );
			}
		};
	}, [ state.isConfigured ] );

	/**
	 * Parses the magical "Connection String" (URL:CID:SECRET) securely.
	 * Splits from the end to support colons within the URL structure (e.g., https:// or :8080).
	 *
	 * @param {string} str The raw connection string.
	 * @return {Object|null} The parsed components or null if invalid.
	 */
	const parseConnectionString = ( str ) => {
		const parts = str.trim().split( ':' );
		if ( parts.length < 3 ) {
			return null;
		}
		const secret = parts.pop().trim();
		const extractedCid = parts.pop().trim();
		const dashboardApiUrl = parts.join( ':' ).trim(); // Re-join remaining parts to form the URL
		
		return { dashboardApiUrl, cid: extractedCid, secret };
	};

	/**
	 * Handles the submission of the Quick Setup Wizard.
	 *
	 * @param {Event} e Form submission event.
	 * @return {Promise<void>}
	 */
	const handleWizardSubmit = async ( e ) => {
		e.preventDefault();
		setIsSaving( true );

		try {
			if ( ! useManualUpload ) {
				const parsedParams = parseConnectionString( connectionString );
				
				if ( ! parsedParams ) {
					throw new Error( __( 'Invalid Connection String format. Expected format: DashboardURL:CID:Secret', 'quelora' ) );
				}

				try {
					// 1. Fetch structured configuration securely
					const data = await apiPost( 'quelora_fetch_config', { 
						dashboardApiUrl: parsedParams.dashboardApiUrl,
						cid: parsedParams.cid,
						secret: parsedParams.secret
					} );
					
					// 2. Transmit parsed JSON object to the backend persister
					await apiPost( 'quelora_save_settings', {
						payload: {
							wizard: true,
							ssoSecretKey: parsedParams.secret,
							cid: parsedParams.cid,
							dashboardApiUrl: parsedParams.dashboardApiUrl,
							configObject: data.config,
							apiUrl: data.apiUrl,
							dashboardUrl: data.dashboardUrl,
						}
					} );
				} catch ( fetchError ) {
					setUseManualUpload( true );
					setIsSaving( false );
					showNotice( 'error', __( 'Could not securely fetch configuration. Please check your Connection String or use the manual fallback.', 'quelora' ) );
					return;
				}
			} else {
				// Fallback: Transmit manual data
				await apiPost( 'quelora_save_settings', {
					payload: {
						wizard: true,
						ssoSecretKey: state.ssoSecretKey,
						cid: cid,
						configScriptRaw: fileContent,
					}
				} );
			}

			window.location.reload();
		} catch ( error ) {
			showNotice( 'error', error.message );
			setIsSaving( false );
		}
	};

	/**
	 * Handles the submission of standard SPA settings.
	 *
	 * @return {Promise<void>}
	 */
	const handleSettingsSubmit = async () => {
		setIsSaving( true );
		try {
			await apiPost( 'quelora_save_settings', { payload: state } );
			showNotice( 'success', __( 'Settings saved successfully.', 'quelora' ) );
		} catch ( error ) {
			showNotice( 'error', error.message );
		} finally {
			setIsSaving( false );
		}
	};

	/**
	 * Re-fetches the integration configuration from the Quelora Dashboard API
	 * and updates the stored settings without a full wizard re-run.
	 *
	 * @return {Promise<void>}
	 */
	const syncConfig = async () => {
		setIsSyncingConfig( true );
		try {
			await apiPost( 'quelora_sync_config' );
			showNotice( 'success', __( 'Configuration synced successfully. Reloading…', 'quelora' ) );
			setTimeout( () => window.location.reload(), 1500 );
		} catch ( error ) {
			showNotice( 'error', error.message );
		} finally {
			setIsSyncingConfig( false );
		}
	};

	/**
	 * Performs a diagnostic health check against the remote Main API.
	 *
	 * @return {Promise<void>}
	 */
	const performHealthCheck = async () => {
		setIsCheckingHealth( true );
		setHealthStatus( null );
		try {
			const res = await apiPost( 'quelora_health_check' );
			setHealthStatus( { type: 'success', message: res.message } );
		} catch ( error ) {
			setHealthStatus( { type: 'error', message: error.message } );
		} finally {
			setIsCheckingHealth( false );
		}
	};

	/**
	 * Reads a local file into memory for the manual upload fallback.
	 *
	 * @param {Event} e File input change event.
	 * @return {void}
	 */
	const handleFileRead = ( e ) => {
		const file = e.target.files[0];
		if ( ! file ) { return; }
		const reader = new FileReader();
		reader.onload = ( evt ) => {
			setFileContent( evt.target.result );
		};
		reader.readAsText( file );
	};

	if ( ! state.isConfigured ) {
		return (
			<div className="wrap quelora-settings-wrap" style={ { maxWidth: '800px', marginTop: '20px' } }>
				<h1>{ __( 'Quelora Integration - Quick Setup', 'quelora' ) }</h1>
				
				{ notice && (
					<Notice status={ notice.status } isDismissible={ false }>
						{ notice.content }
					</Notice>
				) }

				<Panel>
					<PanelBody title={ __( 'Welcome to Quelora', 'quelora' ) } initialOpen={ true }>
						<p>{ __( 'Paste your Auto-Configuration String to securely connect your website. If you prefer, you can switch to the manual configuration mode.', 'quelora' ) }</p>
						
						<form onSubmit={ handleWizardSubmit }>
							{ ! useManualUpload ? (
								<TextControl
									label={ __( 'Connection String', 'quelora' ) }
									type="text"
									value={ connectionString }
									onChange={ setConnectionString }
									required
									help={ __( 'Format: URL:CID:Secret (e.g., https://api-dashboard.quelora.local:QU-XXXXX:MySecret)', 'quelora' ) }
								/>
							) : (
								<>
									<TextControl
										label={ __( 'Client ID (CID)', 'quelora' ) }
										value={ cid }
										onChange={ setCid }
										required
										help={ __( 'The unique identifier for your Quelora community.', 'quelora' ) }
									/>

									<TextControl
										label={ __( 'JWT Secret Key', 'quelora' ) }
										type="password"
										value={ state.ssoSecretKey }
										onChange={ ( val ) => updateState( 'ssoSecretKey', val ) }
										required
										help={ __( 'The HMAC-SHA256 master secret for authentication.', 'quelora' ) }
									/>

									<div style={ { marginTop: '20px', padding: '15px', background: '#f6f7f7', border: '1px solid #dcdcde' } }>
										<p><strong>{ __( 'Configuration File', 'quelora' ) }</strong></p>
										<input type="file" accept=".js" required onChange={ handleFileRead } />
									</div>
								</>
							) }

							<div style={ { marginTop: '20px' } }>
								<Button isPrimary type="submit" isBusy={ isSaving } disabled={ isSaving }>
									{ isSaving ? __( 'Configuring...', 'quelora' ) : __( 'Complete Setup', 'quelora' ) }
								</Button>
								
								<Button isLink onClick={ () => setUseManualUpload( ! useManualUpload ) } style={ { marginLeft: '15px' } }>
									{ useManualUpload ? __( 'Switch to Auto-Configuration', 'quelora' ) : __( 'I prefer manual configuration', 'quelora' ) }
								</Button>
							</div>
						</form>
					</PanelBody>
				</Panel>
			</div>
		);
	}

	return (
		<div className="wrap quelora-settings-wrap" style={ { maxWidth: '900px', marginTop: '20px' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' } }>
				<h1>{ __( 'Quelora Integration', 'quelora' ) }</h1>
				<Button isPrimary onClick={ handleSettingsSubmit } isBusy={ isSaving } disabled={ isSaving }>
					{ isSaving ? __( 'Saving...', 'quelora' ) : __( 'Save Settings', 'quelora' ) }
				</Button>
			</div>

			{ notice && (
				<Notice status={ notice.status } isDismissible={ false } onRemove={ () => setNotice( null ) }>
					{ notice.content }
				</Notice>
			) }

			<TabPanel
				className="quelora-spa-tabs"
				activeClass="active-tab"
				tabs={ [
					{ name: 'general', title: __( 'General', 'quelora' ) },
					{ name: 'integration', title: __( 'Integration', 'quelora' ) },
					{ name: 'assets', title: __( 'Assets', 'quelora' ) },
					{ name: 'customisation', title: __( 'Customisation', 'quelora' ) },
					{ name: 'sync', title: __( 'Synchronization', 'quelora' ) },
				] }
			>
				{ ( tab ) => (
					<div style={ { marginTop: '20px' } }>
						{ tab.name === 'general' && (
							<>
								<PanelBody title={ __( 'Activation Controls', 'quelora' ) }>
									<ToggleControl
										label={ __( 'Enable Quelora by default', 'quelora' ) }
										checked={ state.defaultActive }
										onChange={ ( val ) => updateState( 'defaultActive', val ) }
										help={ __( 'Assets are injected on every post unless disabled per-post.', 'quelora' ) }
									/>
									<ToggleControl
										label={ __( 'Hide WordPress comments', 'quelora' ) }
										checked={ state.hideWpComments }
										onChange={ ( val ) => updateState( 'hideWpComments', val ) }
										help={ __( 'Suppresses native WordPress comment threads when Quelora is active.', 'quelora' ) }
									/>
								</PanelBody>

								<PanelBody title={ __( 'Placement Strategy', 'quelora' ) }>
									<RadioControl
										selected={ state.placementStrategy }
										options={ [
											{ label: __( 'Both hooks (Recommended)', 'quelora' ), value: 'both' },
											{ label: __( 'Content filter only', 'quelora' ), value: 'content' },
											{ label: __( 'Comment form only', 'quelora' ), value: 'comment_form' },
											{ label: __( 'IIFE only (No PHP hooks)', 'quelora' ), value: 'iife_only' },
										] }
										onChange={ ( val ) => updateState( 'placementStrategy', val ) }
									/>
									<TextControl
										label={ __( 'Header Widget Selector', 'quelora' ) }
										value={ state.headerSelector }
										onChange={ ( val ) => updateState( 'headerSelector', val ) }
										style={ { marginTop: '15px' } }
									/>
								</PanelBody>

								<PanelBody title={ __( 'Mount Points & APIs', 'quelora' ) }>
									<TextControl
										label={ __( 'Client ID (CID)', 'quelora' ) }
										value={ state.clientId }
										onChange={ ( val ) => updateState( 'clientId', val ) }
									/>
									<TextControl
										label={ __( 'Main API URL', 'quelora' ) }
										value={ state.apiUrl }
										onChange={ ( val ) => updateState( 'apiUrl', val ) }
										help={ __( 'Base URL for the primary Quelora API (e.g., https://api.quelora.local).', 'quelora' ) }
									/>
									<TextControl
										label={ __( 'Dashboard API URL', 'quelora' ) }
										value={ state.dashboardApiUrl }
										onChange={ ( val ) => updateState( 'dashboardApiUrl', val ) }
										help={ __( 'Base URL for Dashboard synchronization (e.g., https://api-dashboard.quelora.local).', 'quelora' ) }
									/>
									<TextControl
										label={ __( 'Frontend Dashboard URL', 'quelora' ) }
										value={ state.dashboardUrl }
										onChange={ ( val ) => updateState( 'dashboardUrl', val ) }
										help={ __( 'Base embed URL for the frontend interface.', 'quelora' ) }
									/>
								</PanelBody>
							</>
						) }

						{ tab.name === 'integration' && (
							<PanelBody title={ __( 'Identity & Security', 'quelora' ) }>
								<ToggleControl
									label={ __( 'Enable WordPress SSO', 'quelora' ) }
									checked={ state.ssoEnabled }
									onChange={ ( val ) => updateState( 'ssoEnabled', val ) }
									help={ __( 'Redirects Quelora authentication to the native WordPress login.', 'quelora' ) }
								/>

								<div style={ { opacity: state.ssoEnabled ? 1 : 0.5, pointerEvents: state.ssoEnabled ? 'auto' : 'none' } }>
									<TextControl
										label={ __( 'Shared Secret Key', 'quelora' ) }
										type="password"
										value={ state.ssoSecretKey }
										onChange={ ( val ) => updateState( 'ssoSecretKey', val ) }
									/>
									
									<TextControl
										label={ __( 'Token Lifetime (seconds)', 'quelora' ) }
										type="number"
										value={ state.ssoTokenTtl }
										onChange={ ( val ) => updateState( 'ssoTokenTtl', parseInt( val, 10 ) ) }
									/>

									<div style={ { marginTop: '20px', padding: '15px', background: '#f0f6fc', borderLeft: '4px solid #2271b1' } }>
										<p style={ { marginTop: 0 } }><strong>{ __( 'Diagnostic Tools', 'quelora' ) }</strong></p>
										<Button isSecondary onClick={ performHealthCheck } isBusy={ isCheckingHealth } disabled={ isCheckingHealth }>
											{ __( 'Test Connection', 'quelora' ) }
										</Button>

										{ healthStatus && (
											<p style={ { color: healthStatus.type === 'error' ? '#d63638' : '#00a32a', fontWeight: 'bold', marginTop: '10px' } }>
												{ healthStatus.message }
											</p>
										) }
									</div>

									<div style={ { marginTop: '15px', padding: '15px', background: '#fff8e5', borderLeft: '4px solid #dba617' } }>
										<p style={ { marginTop: 0 } }><strong>{ __( 'Sync Configuration', 'quelora' ) }</strong></p>
										<p style={ { fontSize: '13px', color: '#50575e' } }>
											{ __( 'Re-fetch the integration configuration from the Quelora Dashboard. Use this after updating settings in the dashboard.', 'quelora' ) }
										</p>
										<Button isSecondary onClick={ syncConfig } isBusy={ isSyncingConfig } disabled={ isSyncingConfig }>
											{ __( 'Sync Now', 'quelora' ) }
										</Button>
									</div>
								</div>
							</PanelBody>
						) }

						{ tab.name === 'assets' && (
							<PanelBody title={ __( 'Asset Delivery', 'quelora' ) }>
								<RadioControl
									label={ __( 'Asset Source', 'quelora' ) }
									selected={ state.assetSource }
									options={ [
										{ label: __( 'Local Package (Recommended for Staging)', 'quelora' ), value: 'local' },
										{ label: __( 'External CDN (Production)', 'quelora' ), value: 'cdn' },
									] }
									onChange={ ( val ) => updateState( 'assetSource', val ) }
								/>

								<div style={ { opacity: state.assetSource === 'cdn' ? 1 : 0.5, pointerEvents: state.assetSource === 'cdn' ? 'auto' : 'none', marginTop: '20px' } }>
									<TextControl
										label={ __( 'JS Bundle URL', 'quelora' ) }
										value={ state.cdnJsUrl }
										onChange={ ( val ) => updateState( 'cdnJsUrl', val ) }
									/>
									<TextControl
										label={ __( 'CSS Bundle URL', 'quelora' ) }
										value={ state.cdnCssUrl }
										onChange={ ( val ) => updateState( 'cdnCssUrl', val ) }
									/>
								</div>
							</PanelBody>
						) }

						{ tab.name === 'customisation' && (
							<>
								<PanelBody title={ __( 'Configuration Payload', 'quelora' ) }>
									<TextareaControl
										value={ state.configScript }
										onChange={ ( val ) => updateState( 'configScript', val ) }
										rows={ 10 }
										help={ __( 'The static window.QUELORA_CONFIG object.', 'quelora' ) }
									/>
								</PanelBody>

								<PanelBody title={ __( 'Custom CSS', 'quelora' ) }>
									<TextareaControl
										value={ state.customCss }
										onChange={ ( val ) => updateState( 'customCss', val ) }
										rows={ 10 }
										help={ __( 'Injected globally to override Quelora defaults.', 'quelora' ) }
									/>
								</PanelBody>
							</>
						) }

						{ tab.name === 'sync' && (
							<>
								<PanelBody title={ __( 'Post Data Sync', 'quelora' ) }>
									<TextControl
										label={ __( 'Endpoint URL', 'quelora' ) }
										value={ state.syncPostsEndpoint }
										onChange={ ( val ) => updateState( 'syncPostsEndpoint', val ) }
									/>
									
									<div style={ { background: '#f6f7f7', padding: '15px', borderRadius: '4px', marginBottom: '15px' } }>
										<p><strong>{ __( 'Status:', 'quelora' ) }</strong> { syncPosts.status }</p>
										<ProgressBar value={ syncPosts.total > 0 ? ( syncPosts.synced / syncPosts.total ) * 100 : 0 } />
										<p style={ { fontSize: '12px', color: '#666', marginTop: '5px' } }>
											{ syncPosts.synced } / { syncPosts.total } { __( 'Processed', 'quelora' ) }
										</p>
									</div>

									{ syncPosts.status === 'running' ? (
										<Button isDestructive onClick={ () => abortSync( 'posts' ) }>
											{ __( 'Abort Post Sync', 'quelora' ) }
										</Button>
									) : (
										<Button isPrimary onClick={ () => startSync( 'posts' ) }>
											{ __( 'Start Post Sync', 'quelora' ) }
										</Button>
									) }
								</PanelBody>

								<PanelBody title={ __( 'User Data Sync', 'quelora' ) }>
									<TextControl
										label={ __( 'Endpoint URL', 'quelora' ) }
										value={ state.syncUsersEndpoint }
										onChange={ ( val ) => updateState( 'syncUsersEndpoint', val ) }
									/>
									
									<div style={ { background: '#f6f7f7', padding: '15px', borderRadius: '4px', marginBottom: '15px' } }>
										<p><strong>{ __( 'Status:', 'quelora' ) }</strong> { syncUsers.status }</p>
										<ProgressBar value={ syncUsers.total > 0 ? ( syncUsers.synced / syncUsers.total ) * 100 : 0 } />
										<p style={ { fontSize: '12px', color: '#666', marginTop: '5px' } }>
											{ syncUsers.synced } / { syncUsers.total } { __( 'Processed', 'quelora' ) }
										</p>
									</div>

									{ syncUsers.status === 'running' ? (
										<Button isDestructive onClick={ () => abortSync( 'users' ) }>
											{ __( 'Abort User Sync', 'quelora' ) }
										</Button>
									) : (
										<Button isPrimary onClick={ () => startSync( 'users' ) }>
											{ __( 'Start User Sync', 'quelora' ) }
										</Button>
									) }
								</PanelBody>
							</>
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const rootElement = document.getElementById( 'quelora-admin-root' );
	if ( rootElement ) {
		render( <AdminApp />, rootElement );
	}
} );