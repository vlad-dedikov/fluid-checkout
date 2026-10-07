/**
 * Keep pages shown in the admin preview frame inside the preview.
 *
 * DEPENDS ON:
 * - jQuery // Add the preview token to AJAX requests
 * - FCUtils // Utility functions
 */
(function (root, factory) {
	if ( typeof define === 'function' && define.amd ) {
		define([], factory(root));
	} else if ( typeof exports === 'object' ) {
		module.exports = factory(root);
	} else {
		root.FCAdminPreviewFrame = factory(root);
	}
})(typeof global !== 'undefined' ? global : this.window || this.global, function (root) {

	'use strict';

	var $ = jQuery;
	var _hasJQuery = ( $ != null );

	var _hasInitialized = false;
	var _publicMethods = {};
	var _settings = {
		adminOrigin:                  '',
		tokenQueryArg:                'fc_preview',
		previewArgs:                  {},
		messageSource:                'fc-admin-preview',

		hideHeaderFooterClass:        'fc-admin-preview--hide-header-footer',

		inertActionSelector:          '#place_order, .fc-place-order-button, .fc-place-order__custom-buttons, .fc-express-checkout, .add_to_cart_button, .single_add_to_cart_button, .wc-block-components-product-button__button',
		linkSelector:                 'a[href], area[href]',
		scriptLinkSelector:           '.woocommerce-terms-and-conditions-link',
		readOnlyFieldSelector:        '.form-row input:not([type="checkbox"]):not([type="radio"]), .form-row textarea, .quantity input',
		readOnlyControlSelector:      '.form-row select, .form-row .ts-wrapper, .form-row .select2-container, .quantity button, .quantity input[type="button"]',
	};
	var _isReadOnlyFields = true;



	/**
	 * METHODS
	 */



	/**
	 * Stop an event from reaching other scripts and from running its default action.
	 *
	 * @param {Event}  e  Event to stop.
	 */
	var preventAction = function( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();
	};

	/**
	 * Stop scripts from opening other windows, such as social login popups.
	 *
	 * @return {null}  Always `null`, like browsers return for blocked popups.
	 */
	var preventWindowOpen = function() {
		return null;
	};



	/**
	 * Maybe add the preview token and mode to jQuery AJAX requests sent to the frontend, such as admin AJAX requests from other plugins.
	 *
	 * @param {Object}  options  jQuery AJAX request options.
	 */
	var maybeAddPreviewArgsToAjaxRequest = function( options ) {
		var url;

		// Get request URL, resolving relative URLs from the preview page
		try {
			url = new URL( options.url, root.location.href );
		}
		// Bail if the URL is not valid
		catch ( error ) {
			return;
		}

		// Bail if the request is sent to another origin, which must not receive the preview token
		if ( root.location.origin !== url.origin ) { return; }

		// Bail if the request already has the preview token, such as WooCommerce AJAX requests
		if ( url.searchParams.has( _settings.tokenQueryArg ) ) { return; }

		// Add the preview arguments to the request URL, keeping its other arguments as they are
		url.search = ( url.search ? url.search + '&' : '?' ) + $.param( _settings.previewArgs );
		options.url = url.href;
	};



	/**
	 * Maybe send the ready message to the admin page, when the preview is shown in its frame.
	 */
	var maybeSendReadyMessage = function() {
		// Bail if not shown in a frame, or the admin origin is not available
		if ( root.parent === root || ! _settings.adminOrigin ) { return; }

		// Send ready message to the admin page
		root.parent.postMessage( { source: _settings.messageSource, type: 'ready' }, _settings.adminOrigin );
	};



	/**
	 * Maybe stop a link from navigating away from the preview page.
	 *
	 * @param {Event}              e     Click or auxiliary click event.
	 * @param {HTMLAnchorElement}  link  Link element.
	 */
	var maybePreventLinkNavigation = function( e, link ) {
		// Bail if the link points to the current page, which only changes the URL fragment
		if ( root.location.origin === link.origin && root.location.pathname === link.pathname && root.location.search === link.search ) { return; }

		// Maybe only stop the default action of script links and links that scripts handle within the page, so their scripts still run
		if ( 'javascript:' === link.protocol || link.matches( _settings.scriptLinkSelector ) ) {
			e.preventDefault();
		}
		// Otherwise, stop the link scripts too, such as social login scripts that open other windows
		else {
			preventAction( e );
		}
	};



	/**
	 * Handle document clicks, including middle clicks, and route to the appropriate handler.
	 *
	 * @param {Event}  e  Click or auxiliary click event.
	 */
	var handleClick = function( e ) {
		var matchedElement;

		// INERT ACTIONS
		if ( e.target.closest( _settings.inertActionSelector ) ) {
			preventAction( e );
		}
		// READ-ONLY FIELD CONTROLS
		else if ( _isReadOnlyFields && e.target.closest( _settings.readOnlyControlSelector ) ) {
			preventAction( e );
		}
		// READ-ONLY FIELDS
		else if ( _isReadOnlyFields && e.target.closest( _settings.readOnlyFieldSelector ) ) {
			e.stopImmediatePropagation();
		}
		// LINKS
		else if ( matchedElement = e.target.closest( _settings.linkSelector ) ) {
			maybePreventLinkNavigation( e, matchedElement );
		}
	};

	/**
	 * Handle document mouse down events and route to the appropriate handler.
	 *
	 * @param {Event}  e  Mouse down event.
	 */
	var handleMouseDown = function( e ) {
		// READ-ONLY FIELD CONTROLS
		if ( _isReadOnlyFields && e.target.closest( _settings.readOnlyControlSelector ) ) {
			preventAction( e );
		}
		// READ-ONLY FIELDS
		else if ( _isReadOnlyFields && e.target.closest( _settings.readOnlyFieldSelector ) ) {
			e.stopImmediatePropagation();
		}
	};

	/**
	 * Handle document key down events and route to the appropriate handler.
	 *
	 * @param {Event}  e  Key down event.
	 */
	var handleKeyDown = function( e ) {
		// READ-ONLY FIELD CONTROLS
		if ( _isReadOnlyFields && FCUtils.keyboardKeys.TAB !== e.key && e.target.closest( _settings.readOnlyControlSelector ) ) {
			preventAction( e );
		}
		// READ-ONLY FIELDS
		else if ( _isReadOnlyFields && e.target.closest( _settings.readOnlyFieldSelector ) ) {
			e.stopImmediatePropagation();
		}
	};

	/**
	 * Handle document before input, key up and focus events and route to the appropriate handler.
	 * Events on read-only fields are also hidden from other scripts, as address autocomplete and postcode lookup scripts change values on them.
	 *
	 * @param {Event}  e  Before input, key up, focus in or focus out event.
	 */
	var handleFieldEvent = function( e ) {
		// READ-ONLY FIELDS
		if ( _isReadOnlyFields && e.target.closest( _settings.readOnlyFieldSelector ) ) {
			preventAction( e );
		}
	};

	/**
	 * Handle messages and route to the appropriate handler.
	 *
	 * @param {MessageEvent}  e  Message event.
	 */
	var handleMessage = function( e ) {
		// Bail if the message does not come from the admin page holding the frame
		if ( root.parent !== e.source || _settings.adminOrigin !== e.origin || ! e.data || _settings.messageSource !== e.data.source ) { return; }

		// SET HEADER AND FOOTER VISIBILITY
		if ( 'setHeaderFooterVisibility' === e.data.type ) {
			document.body.classList.toggle( _settings.hideHeaderFooterClass, true !== e.data.visible );
		}
		// SET READ-ONLY FIELDS
		else if ( 'setReadOnlyFields' === e.data.type ) {
			_isReadOnlyFields = false !== e.data.readOnly;
		}
	};





	/**
	 * Initialize the script.
	 *
	 * @param {Object}  options  Optional settings passed from PHP. Will be merged with the default settings when provided.
	 */
	_publicMethods.init = function( options ) {
		// Bail if already initialized
		if ( _hasInitialized ) { return; }

		// Merge settings
		_settings = FCUtils.extendObject( _settings, options );

		// Stop scripts from opening other windows
		root.open = preventWindowOpen;

		// Add event listeners
		window.addEventListener( 'click', handleClick, true );
		window.addEventListener( 'auxclick', handleClick, true ); // Middle clicks open links in new tabs
		window.addEventListener( 'mousedown', handleMouseDown, true );
		window.addEventListener( 'keydown', handleKeyDown, true );
		window.addEventListener( 'beforeinput', handleFieldEvent, true );
		window.addEventListener( 'keyup', handleFieldEvent, true );
		window.addEventListener( 'focusin', handleFieldEvent, true );
		window.addEventListener( 'focusout', handleFieldEvent, true );
		window.addEventListener( 'submit', preventAction, true ); // Stops all form submissions, as forms must not submit from the preview
		window.addEventListener( 'message', handleMessage, true );

		// Add jQuery AJAX prefilter
		if ( _hasJQuery ) {
			$.ajaxPrefilter( maybeAddPreviewArgsToAjaxRequest );
		}

		// Let the admin page know the preview is ready
		maybeSendReadyMessage();

		_hasInitialized = true;
	};

	//
	// Public APIs
	//
	return _publicMethods;

} );
