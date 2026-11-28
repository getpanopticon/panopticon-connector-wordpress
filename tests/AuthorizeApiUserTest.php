<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Tests which user a request carrying the API token is logged in as. See GitHub issue #15.
 *
 * Each test runs in its own process, because the tests declare WordPress functions and load panopticon.php. On the code
 * with the bug, the Multisite tests get no user: there is no "Super Admin" role for get_users() to find.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AuthorizeApiUserTest extends TestCase
{
	protected function setUp(): void
	{
		require_once __DIR__ . '/Stubs/auth.php';
		require_once __DIR__ . '/../panopticon.php';

		$GLOBALS['panopticon_test_multisite']    = false;
		$GLOBALS['panopticon_test_users']        = [];
		$GLOBALS['panopticon_test_site_members'] = null;
		$GLOBALS['panopticon_test_super_admins'] = [];

		unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_PANOPTICON_TOKEN'], $_SERVER['HTTP_X_JOOMLA_TOKEN']);
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . hash('sha256', PANOPTICON_TEST_TOKEN . ':' . PANOPTICON_TEST_SALT);
	}

	public function testSingleSiteLogsInTheFirstAdministrator()
	{
		$GLOBALS['panopticon_test_users'] = [
			new WP_User(1, 'editor', ['editor']),
			new WP_User(3, 'admin2', ['administrator']),
			new WP_User(2, 'admin1', ['administrator']),
		];

		$user = PanopticonPlugin::getInstance()->authorizeAPIUser(false);

		$this->assertInstanceOf(WP_User::class, $user);
		$this->assertSame(2, $user->ID);
	}

	public function testSingleSiteWithoutAdministratorsLogsInNobody()
	{
		$GLOBALS['panopticon_test_users'] = [new WP_User(1, 'editor', ['editor'])];

		$this->assertFalse(PanopticonPlugin::getInstance()->authorizeAPIUser(false));
	}

	public function testMultisiteLogsInTheFirstSuperAdmin()
	{
		$GLOBALS['panopticon_test_multisite']    = true;
		$GLOBALS['panopticon_test_users']        = [
			new WP_User(1, 'siteadmin', ['administrator']),
			new WP_User(5, 'network2', []),
			new WP_User(4, 'network1', []),
		];
		// Super admins need not be members of the site the request is for.
		$GLOBALS['panopticon_test_site_members'] = [1];
		$GLOBALS['panopticon_test_super_admins'] = ['network2', 'network1'];

		$user = PanopticonPlugin::getInstance()->authorizeAPIUser(false);

		$this->assertInstanceOf(WP_User::class, $user);
		$this->assertSame(4, $user->ID, 'The super admin with the lowest ID must be logged in.');
	}

	public function testMultisiteSkipsSuperAdminLoginsWithoutAUser()
	{
		$GLOBALS['panopticon_test_multisite']    = true;
		$GLOBALS['panopticon_test_users']        = [new WP_User(7, 'network', [])];
		$GLOBALS['panopticon_test_super_admins'] = ['deleted', 'network'];

		$user = PanopticonPlugin::getInstance()->authorizeAPIUser(false);

		$this->assertInstanceOf(WP_User::class, $user);
		$this->assertSame(7, $user->ID);
	}

	public function testMultisiteWithoutSuperAdminsLogsInNobody()
	{
		$GLOBALS['panopticon_test_multisite']    = true;
		$GLOBALS['panopticon_test_users']        = [new WP_User(1, 'siteadmin', ['administrator'])];
		$GLOBALS['panopticon_test_super_admins'] = ['deleted'];

		$this->assertFalse(PanopticonPlugin::getInstance()->authorizeAPIUser(false));
	}

	public function testAWrongTokenLogsInNobody()
	{
		$GLOBALS['panopticon_test_users'] = [new WP_User(1, 'admin', ['administrator'])];
		$_SERVER['HTTP_AUTHORIZATION']    = 'Bearer wrong';

		$this->assertFalse(PanopticonPlugin::getInstance()->authorizeAPIUser(false));
	}

	public function testAnAlreadyLoggedInUserIsKept()
	{
		$GLOBALS['panopticon_test_users'] = [new WP_User(1, 'admin', ['administrator'])];

		$this->assertSame(42, PanopticonPlugin::getInstance()->authorizeAPIUser(42));
	}
}
