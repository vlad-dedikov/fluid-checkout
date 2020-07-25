/**
 * Handles Mailcheck runs on email fields
 */
(function (root, factory) {
	if ( typeof define === 'function' && define.amd ) {
		define([], factory(root));
	} else if ( typeof exports === 'object' ) {
		module.exports = factory(root);
	} else {
		root.MailcheckInit = factory(root);
	}
})(typeof global !== 'undefined' ? global : this.window || this.global, function (root) {

	'use strict';

	var $ = jQuery;

	var _hasInitialized = false;
	var _publicMethods = { }
	var _settings = {
		mailFieldSelector: '[data-mailcheck]',
		mailFieldSuggestedClass: 'has-email-suggestion',
		suggestionElementSelector: '[data-mailcheck-suggestion]',
		suggestedElementTemplate: '<div class="wfc-mailcheck-suggestion" data-mailcheck-suggestion>Did you mean <a class="mailcheck-suggestion" href="#apply-suggestion" data-mailcheck-apply>{suggestion}</a>?</div>',
		suggestionTemplate: '{address}@<span class="mailcheck-suggestion-domain">{domain}</span>',
	}
	var _tempTarget = null;



	/**
	 * METHODS
	 */



	var handleSuggested = function( suggestion ) {
		removeSuggestions();

		if ( _tempTarget === null ) return;

		var suggestionHtml = _settings.suggestionTemplate.replace( '{address}', suggestion.address ).replace( '{domain}', suggestion.domain );
		var suggestedElementHtml = _settings.suggestedElementTemplate.replace( '{suggestion}', suggestionHtml );

		// Create suggestion element and add it after the field
		var parent = _tempTarget.parentNode;
		var element = document.createElement( 'div' );
		element.innerHTML = suggestedElementHtml;
		parent.insertBefore( element.firstChild, _tempTarget.nextSibling );
		element = null;

		_tempTarget.classList.add( _settings.mailFieldSuggestedClass );
	}



	var removeSuggestions = function() {
		if ( _tempTarget === null ) return;
		
		var parent = _tempTarget.parentNode;
		var suggestions = parent.querySelectorAll( _settings.suggestionElementSelector );
		
		for ( var i = 0; i < suggestions.length; i++ ) {
			// console.log(suggestions[i]);
			suggestions[i].parentNode.removeChild( suggestions[i] );
		}

		_tempTarget.classList.remove( _settings.mailFieldSuggestedClass );
	}



	/**
	 * Handle captured keyup event and route to appropriate function.
	 * @param  {Event} e Event data.
	 */
	var handleTriggerEvents = function( e ) {
		if ( Mailcheck !== undefined && e.target.matches( _settings.mailFieldSelector ) ) {
			_tempTarget = e.target;

			Mailcheck.run( {
				email: _tempTarget.value,
				suggested: handleSuggested,
				empty: removeSuggestions
			} );

			_tempTarget = null;
		}
	};



	/**
	 * Initialize Mailcheck feature
	 */
	_publicMethods.init = function() {
		if ( _hasInitialized ) return;

		// Add event listeners
		window.addEventListener( 'change', handleTriggerEvents, true );

		_hasInitialized = true;
	};

	
	//
	// Public APIs
	//
	return _publicMethods;

});
