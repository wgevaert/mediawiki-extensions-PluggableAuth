<?php

/*
 * Copyright (c) 2016 The MITRE Corporation
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

class PluggableAuthHooks {

	/**
	 * Implements extension registration callback.
	 * See https://www.mediawiki.org/wiki/Manual:Extension_registration#Customizing_registration
	 *
	 * @since 2.0
	 *
	 */
	public static function onRegistration() {
		if ( !$GLOBALS['wgWhitelistRead'] ) {
			$GLOBALS['wgWhitelistRead'] = [];
		}
		$GLOBALS['wgWhitelistRead'][] = 'Special:PluggableAuthLogin';
		if ( $GLOBALS['wgPluggableAuth_EnableLocalLogin'] ) {
			return;
		}
		$passwordProviders = [
			'MediaWiki\Auth\LocalPasswordPrimaryAuthenticationProvider',
			'MediaWiki\Auth\TemporaryPasswordPrimaryAuthenticationProvider'
		];
		$providers = $GLOBALS['wgAuthManagerAutoConfig'];
		if ( isset( $providers['primaryauth'] ) ) {
			$primaries = $providers['primaryauth'];
			foreach ( $primaries as $key => $provider ) {
				if ( in_array( $provider['class'], $passwordProviders ) ) {
					unset( $GLOBALS['wgAuthManagerAutoConfig']['primaryauth'][$key] );
				}
			}
		}
	}

	/**
	 *
	 * Implements AuthChangeFormFields hook.
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/AuthChangeFormFields
	 *
	 * @since 2.0
	 *
	 * @param array $requests
	 * @param array $fieldInfo
	 * @param array $formDescriptor
	 * @param $action
	 */
	public static function onAuthChangeFormFields( array $requests,
		array $fieldInfo, array &$formDescriptor, $action) {
		if ( isset( $formDescriptor['pluggableauthlogin'] ) ) {
			$formDescriptor['pluggableauthlogin']['weight'] = 101;
		}
	}

	/**
	 * Implements UserLogoutComplete hook.
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/UserLogoutComplete
	 *
	 * @since 2.0
	 *
	 * @param User $user
	 * @param $inject_html
	 * @param $old_name
	 */
	public static function deauthenticate( User &$user, $inject_html,
		$old_name ) {
		$old_user = User::newFromName( $old_name );
		if ( $old_user === false ) {
			return true;
		}
		wfDebug( 'Deauthenticating ' . $old_name );
		$pluggableauth = PluggableAuth::singleton();
		if ( $pluggableauth ) {
			$pluggableauth->deauthenticate( $old_user );
		}
		wfDebug( 'Deauthenticated ' . $old_name );
		return true;
	}

	/**
	 * Implements BeforePageDisplay hook.
	 *
	 * @since 2.0
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function autoLoginInit( &$out, &$skin ) {
		if ( $GLOBALS['wgPluggableAuth_EnableAutoLogin'] ) {
			$out->addModules( 'ext.PluggableAuthAutoLogin' );
		}
		return true;
	}

	/**
	 * Implements PersonalUrls hook.
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/PersonalUrls
	 *
	 * @since 1.0
	 *
	 * @param array &$personal_urls
	 * @param Title $title
	 * @param SkinTemplate $skin
	 */
	public static function modifyLoginURLs( array &$personal_urls,
		Title $title = null, SkinTemplate $skin = null ) {
		if ( $GLOBALS['wgPluggableAuth_EnableAutoLogin'] ) {
			unset( $personal_urls['logout'] );
		}
		return true;
	}

	/**
	 * Implements ResourceLoaderGetConfigVars hook.
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderGetConfigVars
	 *
	 * @since 2.0
	 *
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		$vars['wgWhitelistRead'] = $GLOBALS['wgWhitelistRead'];
	}
}
