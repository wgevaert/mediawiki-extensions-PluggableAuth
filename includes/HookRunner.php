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

use MediaWiki\Extension\PluggableAuth\Hook\PluggableAuthPopulateGroups;
use MediaWiki\Extension\PluggableAuth\Hook\PluggableAuthUserAuthorization;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\User\UserIdentity;

class HookRunner implements PluggableAuthPopulateGroups, PluggableAuthUserAuthorization {

	/**
	 *
	 * @var HookContainer
	 */
	private $container = null;

	/**
	 * @param HookContainer $container
	 */
	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/**
	 * @param UserIdentity $user
	 * @return void
	 */
	public function onPluggableAuthPopulateGroups( UserIdentity $user ): void {
		$this->container->run( 'PluggableAuthPopulateGroups', [ $user ] );
	}

	/**
	 * @param UserIdentity $user
	 * @param bool &$authorized
	 * @return bool
	 */
	public function onPluggableAuthUserAuthorization( UserIdentity $user, bool &$authorized ): bool {
		return $this->container->run( 'PluggableAuthUserAuthorization', [ $user, &$authorized ] );
	}
}
