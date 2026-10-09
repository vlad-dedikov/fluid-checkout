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
		darkModeCssVariables:         {},

		hideHeaderFooterClass:        'fc-admin-preview--hide-header-footer',
		darkModeClass:                'has-fc-dark-mode',

		stylesId:                     'fc-admin-preview-styles',
		probeId:                      'fc-admin-preview-probe',
		customStylesId:               'fc-custom-styles',

		inertActionSelector:          '#place_order, .fc-place-order-button, .fc-place-order__custom-buttons, .fc-express-checkout, .add_to_cart_button, .single_add_to_cart_button, .wc-block-components-product-button__button',
		linkSelector:                 'a[href], area[href]',
		scriptLinkSelector:           '.woocommerce-terms-and-conditions-link',
		readOnlyFieldSelector:        '.form-row input:not([type="checkbox"]):not([type="radio"]), .form-row textarea, .quantity input',
		readOnlyControlSelector:      '.form-row select, .form-row .ts-wrapper, .form-row .select2-container, .quantity button, .quantity input[type="button"]',
		colorSchemeSelector:          ':root',
	};
	var _isReadOnlyFields = true;
	var _cssVariables = {};
	var _colorScheme = '';
	var _hasDarkModeClass = false;



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
	 * Add CSS variables to a style sheet, as a map of selectors to CSS variable names and values.
	 * Rules and values are added through the CSS object model, so values cannot add other rules.
	 *
	 * @param {CSSStyleSheet}  sheet      Style sheet.
	 * @param {Object}         variables  CSS variables by selector.
	 */
	var addCssVariablesRules = function( sheet, variables ) {
		var selector;
		var name;
		var rule;

		// Iterate selectors
		for ( selector in variables ) {
			// Add an empty rule for the selector
			try {
				rule = sheet.cssRules[ sheet.insertRule( selector + '{}', sheet.cssRules.length ) ];
			}
			// Skip selectors that are not valid, such as selectors the browser does not support
			catch ( error ) {
				continue;
			}

			// Iterate CSS variables of the selector
			for ( name in variables[ selector ] ) {
				// Skip properties other than CSS variables
				if ( 0 !== name.indexOf( '--' ) ) { continue; }

				// Set the CSS variable, which the CSS object model ignores when the value is not valid
				rule.style.setProperty( name, variables[ selector ][ name ] );
			}
		}
	};

	/**
	 * Get the CSS variables that force the color scheme: the dark mode values for the dark scheme, or no value for the light scheme so the default colors apply.
	 *
	 * @return {Object}  CSS variables by selector.
	 */
	var getColorSchemeCssVariables = function() {
		var variables = {};
		var name;

		// Bail if the color scheme is not forced
		if ( 'light' !== _colorScheme && 'dark' !== _colorScheme ) { return variables; }

		variables[ _settings.colorSchemeSelector ] = {};

		// Iterate dark mode CSS variables
		for ( name in _settings.darkModeCssVariables ) {
			variables[ _settings.colorSchemeSelector ][ name ] = 'dark' === _colorScheme ? _settings.darkModeCssVariables[ name ] : 'initial';
		}

		return variables;
	};

	/**
	 * Update the preview styles with the CSS variables of the color scheme, then the CSS variables set by the admin page, which override them.
	 */
	var updatePreviewStyles = function() {
		var styleElement = document.getElementById( _settings.stylesId );

		// Maybe add the style element at the end of the body, so its rules come after the page styles
		if ( ! styleElement ) {
			styleElement = document.createElement( 'style' );
			styleElement.id = _settings.stylesId;
			document.body.appendChild( styleElement );
		}

		// Remove the previous rules
		while ( styleElement.sheet.cssRules.length ) {
			styleElement.sheet.deleteRule( 0 );
		}

		// Add the CSS variables
		addCssVariablesRules( styleElement.sheet, getColorSchemeCssVariables() );
		addCssVariablesRules( styleElement.sheet, _cssVariables );
	};

	/**
	 * Force the color scheme of the page preview, or keep the color scheme of the page.
	 *
	 * @param {string}  scheme  `light` or `dark`, or any other value to keep the color scheme of the page.
	 */
	var setColorScheme = function( scheme ) {
		_colorScheme = scheme;

		// Add or remove the dark mode class, as some styles also depend on it
		document.body.classList.toggle( _settings.darkModeClass, 'dark' === scheme || ( 'light' !== scheme && _hasDarkModeClass ) );

		// Update the CSS variables of the color scheme
		updatePreviewStyles();
	};

	/**
	 * Add or remove body classes of the page preview.
	 *
	 * @param {Object}  classes  Whether each class is added, by class name.
	 */
	var setBodyClasses = function( classes ) {
		var className;

		// Iterate classes
		for ( className in classes ) {
			document.body.classList.toggle( className, true === classes[ className ] );
		}
	};

	/**
	 * Send the computed value of a CSS property value in the page preview to the admin page, such as a font size in pixels.
	 * Values are computed on a hidden text field, which gets the theme styles for text fields.
	 *
	 * @param {number|string}  requestId  Request ID from the admin page, sent back with the value.
	 * @param {string}         property   CSS property name.
	 * @param {string}         value      CSS property value.
	 */
	var sendComputedCssValue = function( requestId, property, value ) {
		var probe = document.getElementById( _settings.probeId );
		var computedValue = '';

		// Maybe add the hidden text field
		if ( ! probe ) {
			probe = document.createElement( 'input' );
			probe.type = 'text';
			probe.id = _settings.probeId;
			probe.hidden = true;
			document.body.appendChild( probe );
		}

		// Set the value on the hidden text field, then remove it once computed
		probe.style.setProperty( property, value );

		// Maybe get the computed value, unless the value is not valid for the property
		if ( probe.style.getPropertyValue( property ) ) {
			computedValue = root.getComputedStyle( probe ).getPropertyValue( property );
		}
		probe.style.removeProperty( property );

		// Send the computed value to the admin page
		root.parent.postMessage( { source: _settings.messageSource, type: 'computedCssValue', requestId: requestId, value: computedValue }, _settings.adminOrigin );
	};



	/**
	 * Refresh the custom styles of the page from a fresh render, such as after the admin page changes style settings, without reloading the page.
	 */
	var refreshCustomStyles = function() {
		// Get a fresh render of the page, use its custom styles unless the request fails, then let the admin page know
		fetch( root.location.href, { credentials: 'same-origin' } )
			.then( function( response ) { return response.ok ? response.text() : Promise.reject( response ); } )
			.then( replaceCustomStyles )
			.then( sendStylesRefreshedMessage, sendStylesRefreshedMessage );
	};

	/**
	 * Replace the custom styles of the page with the custom styles of a fresh render.
	 *
	 * @param {string}  html  HTML of the fresh render.
	 */
	var replaceCustomStyles = function( html ) {
		var newStyleElement = new DOMParser().parseFromString( html, 'text/html' ).getElementById( _settings.customStylesId );
		var styleElement = document.getElementById( _settings.customStylesId );

		// Maybe add the custom styles element, as pages without custom styles have none
		if ( ! styleElement ) {
			styleElement = document.createElement( 'style' );
			styleElement.id = _settings.customStylesId;
			document.head.appendChild( styleElement );
		}

		// Replace the custom styles, or clear them when the fresh render has none
		styleElement.textContent = newStyleElement ? newStyleElement.textContent : '';
	};

	/**
	 * Send the styles refreshed message to the admin page, which shows the page preview as busy until then.
	 */
	var sendStylesRefreshedMessage = function() {
		// Send styles refreshed message to the admin page
		root.parent.postMessage( { source: _settings.messageSource, type: 'stylesRefreshed' }, _settings.adminOrigin );
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
		// SET CSS VARIABLES
		else if ( 'setCssVariables' === e.data.type ) {
			_cssVariables = e.data.variables;
			updatePreviewStyles();
		}
		// SET COLOR SCHEME
		else if ( 'setColorScheme' === e.data.type ) {
			setColorScheme( e.data.scheme );
		}
		// SET BODY CLASSES
		else if ( 'setBodyClasses' === e.data.type ) {
			setBodyClasses( e.data.classes );
		}
		// GET COMPUTED CSS VALUE
		else if ( 'getComputedCssValue' === e.data.type ) {
			sendComputedCssValue( e.data.requestId, e.data.property, e.data.value );
		}
		// REFRESH STYLES
		else if ( 'refreshStyles' === e.data.type ) {
			refreshCustomStyles();
		}
		// RELOAD
		else if ( 'reload' === e.data.type ) {
			root.location.reload();
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

		// Keep the dark mode class of the page, to restore it when the color scheme is no longer forced
		_hasDarkModeClass = document.body.classList.contains( _settings.darkModeClass );

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
