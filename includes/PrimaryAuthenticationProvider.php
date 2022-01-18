<?php
/*
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

namespace MediaWiki\Extension\PluggableAuth;

use Config;
use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserFactory;
use Message;
use MWException;
use RawMessage;
use Sanitizer;
use SpecialPage;
use StatusValue;
use User;

class PrimaryAuthenticationProvider extends AbstractPrimaryAuthenticationProvider {

	public const CONSTRUCTOR_OPTIONS = [
		'PluggableAuth_EnableLocalProperties'
	];

	/**
	 * @var bool
	 */
	private $enableLocalProperties;

	/**
	 * @var UserFactory
	 */
	private $userFactory;

	/**
	 * @var PluggableAuthFactory
	 */
	private $pluggableAuthFactory;

	/**
	 * @param Config $mainConfig
	 * @param UserFactory $userFactory
	 * @param PluggableAuthFactory $pluggableAuthFactory
	 */
	public function __construct(
		Config $mainConfig,
		UserFactory $userFactory,
		PluggableAuthFactory $pluggableAuthFactory
	) {
		$options = new ServiceOptions( self::CONSTRUCTOR_OPTIONS, $mainConfig );
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->enableLocalProperties = $options->get( 'PluggableAuth_EnableLocalProperties' );
		$this->userFactory = $userFactory;
		$this->pluggableAuthFactory = $pluggableAuthFactory;
	}

	/**
	 * @param string $action
	 * @param array $options
	 * @return array|BeginAuthenticationRequest[]
	 */
	public function getAuthenticationRequests( $action, array $options ): array {
		switch ( $action ) {
			case AuthManager::ACTION_LOGIN:
				$requests = [];
				foreach ( $this->pluggableAuthFactory->getConfig() as $name => $entry ) {
					$requests[$name] = new BeginAuthenticationRequest(
						$name,
						$entry['extraLoginFields'] ?? [],
						$entry['buttonLabelMessage'] ?? null,
						$entry['buttonLabel'] ?? null
					);
				}
				return $requests;
			default:
				return [];
		}
	}

	/**
	 * Start an authentication flow
	 * @param array $reqs
	 * @return AuthenticationResponse
	 * @throws MWException
	 */
	public function beginPrimaryAuthentication( array $reqs ): AuthenticationResponse {
		$matches = array_filter( $reqs, static function ( $req ) {
			return $req instanceof BeginAuthenticationRequest;
		} );
		$request = $matches[0] ?? null;
		if ( !$request ) {
			return AuthenticationResponse::newAbstain();
		}
		$extraLoginFields = [];
		foreach ( $request->getExtraLoginFields() as $key => $value ) {
			if ( isset( $request->$key ) ) {
				$extraLoginFields[$key] = $request->$key;
			}
		}
		$url = SpecialPage::getTitleFor( 'PluggableAuthLogin' )->getFullURL();
		$this->manager->setAuthenticationSessionData(
			PluggableAuthLogin::RETURNTOURL_SESSION_KEY, $request->returnToUrl );
		$this->manager->setAuthenticationSessionData(
			PluggableAuthLogin::EXTRALOGINFIELDS_SESSION_KEY, $extraLoginFields );
		$queryValues = $this->manager->getRequest()->getQueryValues();
		$this->manager->setAuthenticationSessionData(
			PluggableAuthLogin::RETURNTOPAGE_SESSION_KEY,
			$queryValues['returnto'] ?? ''
		);
		$this->manager->setAuthenticationSessionData(
			PluggableAuthLogin::RETURNTOQUERY_SESSION_KEY,
			$queryValues['returntoquery'] ?? ''
		);
		$this->manager->getRequest()->setSessionData(
			PluggableAuthLogin::AUTHENTICATIONPLUGINNAME_SESSION_KEY,
			$request->getAuthenticationPluginName()
		);
		return AuthenticationResponse::newRedirect(
			[ new ContinueAuthenticationRequest() ],
			$url
		);
	}

	/**
	 * Continue an authentication flow
	 * @param array $reqs
	 * @return AuthenticationResponse
	 */
	public function continuePrimaryAuthentication( array $reqs ): AuthenticationResponse {
		$request = AuthenticationRequest::getRequestByClass( $reqs, ContinueAuthenticationRequest::class );
		if ( !$request ) {
			return AuthenticationResponse::newFail(
				new Message( 'pluggableauth-authentication-workflow-failure' ) );
		}
		$error = $this->manager->getAuthenticationSessionData( PluggableAuthLogin::ERROR_SESSION_KEY );
		if ( $error !== null ) {
			$this->manager->removeAuthenticationSessionData( PluggableAuthLogin::ERROR_SESSION_KEY );
			return AuthenticationResponse::newFail( new RawMessage( $error ) );
		}
		$username = $request->username;
		$user = $this->userFactory->newFromName( $username );
		if ( $user && $user->getId() !== 0 ) {
			$this->updateUserRealnameAndEmail( $user );
		}
		return AuthenticationResponse::newPass( $username );
	}

	/**
	 * Determine whether a property can change
	 * @param string $property
	 * @return bool
	 */
	public function providerAllowsPropertyChange( $property ): bool {
		return $this->enableLocalProperties;
	}

	private function updateUserRealNameAndEmail( User $user, bool $force = false ): void {
		$realname = $this->manager->getAuthenticationSessionData( PluggableAuthLogin::REALNAME_SESSION_KEY );
		$this->manager->removeAuthenticationSessionData( PluggableAuthLogin::REALNAME_SESSION_KEY );
		$email = $this->manager->getAuthenticationSessionData( PluggableAuthLogin::EMAIL_SESSION_KEY );
		$this->manager->removeAuthenticationSessionData( PluggableAuthLogin::EMAIL_SESSION_KEY );
		if ( $user->mRealName != $realname || $user->mEmail != $email ) {
			if ( $this->enableLocalProperties && !$force ) {
				$this->logger->debug( 'PluggableAuth: Local properties enabled.' );
				$this->logger->debug( 'PluggableAuth: Did not save updated real name and email address.' );
			} else {
				$this->logger->debug( 'PluggableAuth: Local properties disabled or has just been created.' );
				$user->mRealName = $realname;
				if ( $email && Sanitizer::validateEmail( $email ) ) {
					$user->mEmail = $email;
					$user->confirmEmail();
				}
				$user->saveSettings();
				$this->logger->debug( 'PluggableAuth: Saved updated real name and email address.' );
			}
		} else {
			$this->logger->debug( 'PluggableAuth: Real name and email address did not change.' );
		}
	}

	/**
	 * @param User $user
	 * @param string $source
	 */
	public function autoCreatedAccount( $user, $source ): void {
		$this->updateUserRealNameAndEmail( $user, true );
		$pluggableauth = $this->pluggableAuthFactory->getInstance();
		if ( $pluggableauth ) {
			$pluggableauth->saveExtraAttributes( $user->mId );
		}
	}

	/**
	 * Test whether the named user exists
	 * @param string $username MediaWiki username
	 * @param int $flags Bitfield of User:READ_* constants
	 * @return bool
	 */
	public function testUserExists(
		$username,
		$flags = Authority::READ_NORMAL
	): bool {
		$user = $this->userFactory->newFromName( $username );
		return $user && $user->isRegistered();
	}

	/**
	 * Validate a change of authentication data (e.g. passwords)
	 * @param AuthenticationRequest $req
	 * @param bool $checkData
	 * @return StatusValue
	 */
	public function providerAllowsAuthenticationDataChange(
		AuthenticationRequest $req,
		$checkData = true
	): StatusValue {
		return StatusValue::newGood( 'ignored' );
	}

	/**
	 * Fetch the account-creation type
	 * @return string
	 */
	public function accountCreationType(): string {
		return self::TYPE_LINK;
	}

	/**
	 * Start an account creation flow
	 * @param User $user User being created (not added to the database yet).
	 * @param User $creator User doing the creation.
	 * @param AuthenticationRequest[] $reqs
	 * @return AuthenticationResponse
	 */
	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ): AuthenticationResponse {
		return AuthenticationResponse::newAbstain();
	}

	/**
	 * Change or remove authentication data (e.g. passwords)
	 * @param AuthenticationRequest $req
	 */
	public function providerChangeAuthenticationData( AuthenticationRequest $req ): void {
	}
}
