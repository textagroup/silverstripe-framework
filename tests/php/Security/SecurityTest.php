<?php

namespace SilverStripe\Security\Tests;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBClassName;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Authenticator;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator;
use SilverStripe\Security\Security;
use SilverStripe\Security\Permission;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\i18n\i18n;

/**
 * Test the security class, including log-in form, change password form, etc
 */
class SecurityTest extends FunctionalTest
{
    protected static $fixture_file = 'MemberTest.yml';

    protected $autoFollowRedirection = false;

    protected $priorAuthenticators = array();

    protected $priorDefaultAuthenticator = null;

    protected $priorUniqueIdentifierField = null;

    protected $priorRememberUsername = null;

    protected $extraControllers = [
        SecurityTest\NullController::class,
        SecurityTest\SecuredController::class,
    ];

    public function setUp()
    {
        // This test assumes that MemberAuthenticator is present and the default
        $this->priorAuthenticators = Authenticator::get_authenticators();
        $this->priorDefaultAuthenticator = Authenticator::get_default_authenticator();
        foreach ($this->priorAuthenticators as $authenticator) {
            Authenticator::unregister($authenticator);
        }

        Authenticator::register(MemberAuthenticator::class);
        Authenticator::set_default_authenticator(MemberAuthenticator::class);

        // And that the unique identified field is 'Email'
        $this->priorUniqueIdentifierField = Member::config()->unique_identifier_field;
        $this->priorRememberUsername = Security::config()->remember_username;
        /**
 * @skipUpgrade
*/
        Member::config()->unique_identifier_field = 'Email';

        parent::setUp();

        Config::inst()->update('SilverStripe\\Control\\Director', 'alternate_base_url', '/');
    }

    public function tearDown()
    {
        // Restore selected authenticator

        // MemberAuthenticator might not actually be present
        if (!in_array(MemberAuthenticator::class, $this->priorAuthenticators)) {
            Authenticator::unregister(MemberAuthenticator::class);
        }
        foreach ($this->priorAuthenticators as $authenticator) {
            Authenticator::register($authenticator);
        }
        Authenticator::set_default_authenticator($this->priorDefaultAuthenticator);

        // Restore unique identifier field
        Member::config()->unique_identifier_field = $this->priorUniqueIdentifierField;
        Security::config()->remember_username = $this->priorRememberUsername;

        parent::tearDown();
    }

    public function testAccessingAuthenticatedPageRedirectsToLoginForm()
    {
        $this->autoFollowRedirection = false;

        $response = $this->get('SecurityTest_SecuredController');
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertContains(
            Config::inst()->get(Security::class, 'login_url'),
            $response->getHeader('Location')
        );

        $this->logInWithPermission('ADMIN');
        $response = $this->get('SecurityTest_SecuredController');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('Success', $response->getBody());

        $this->autoFollowRedirection = true;
    }

    public function testPermissionFailureSetsCorrectFormMessages()
    {
        Config::nest();

        // Controller that doesn't attempt redirections
        $controller = new SecurityTest\NullController();
        $controller->setResponse(new HTTPResponse());

        Security::permissionFailure($controller, array('default' => 'Oops, not allowed'));
        $this->assertEquals('Oops, not allowed', Session::get('Security.Message.message'));

        // Test that config values are used correctly
        Config::inst()->update(Security::class, 'default_message_set', 'stringvalue');
        Security::permissionFailure($controller);
        $this->assertEquals(
            'stringvalue',
            Session::get('Security.Message.message'),
            'Default permission failure message value was not present'
        );

        Config::inst()->remove(Security::class, 'default_message_set');
        Config::inst()->update(Security::class, 'default_message_set', array('default' => 'arrayvalue'));
        Security::permissionFailure($controller);
        $this->assertEquals(
            'arrayvalue',
            Session::get('Security.Message.message'),
            'Default permission failure message value was not present'
        );

        // Test that non-default messages work.
        // NOTE: we inspect the response body here as the session message has already
        // been fetched and output as part of it, so has been removed from the session
        $this->logInWithPermission('EDITOR');

        Config::inst()->update(
            Security::class,
            'default_message_set',
            array('default' => 'default', 'alreadyLoggedIn' => 'You are already logged in!')
        );
        Security::permissionFailure($controller);
        $this->assertContains(
            'You are already logged in!',
            $controller->getResponse()->getBody(),
            'Custom permission failure message was ignored'
        );

        Security::permissionFailure(
            $controller,
            array('default' => 'default', 'alreadyLoggedIn' => 'One-off failure message')
        );
        $this->assertContains(
            'One-off failure message',
            $controller->getResponse()->getBody(),
            "Message set passed to Security::permissionFailure() didn't override Config values"
        );

        Config::unnest();
    }

    /**
     * Follow all redirects recursively
     *
     * @param  string $url
     * @param  int    $limit Max number of requests
     * @return HTTPResponse
     */
    protected function getRecursive($url, $limit = 10)
    {
        $this->cssParser = null;
        $response = $this->mainSession->get($url);
        while (--$limit > 0 && $response instanceof HTTPResponse && $response->getHeader('Location')) {
            $response = $this->mainSession->followRedirection();
        }
        return $response;
    }

    public function testAutomaticRedirectionOnLogin()
    {
        // BackURL with permission error (not authenticated) should not redirect
        if ($member = Member::currentUser()) {
            $member->logOut();
        }
        $response = $this->getRecursive('SecurityTest_SecuredController');
        $this->assertContains(Convert::raw2xml("That page is secured."), $response->getBody());
        $this->assertContains('<input type="submit" name="action_dologin"', $response->getBody());

        // Non-logged in user should not be redirected, but instead shown the login form
        // No message/context is available as the user has not attempted to view the secured controller
        $response = $this->getRecursive('Security/login?BackURL=SecurityTest_SecuredController/');
        $this->assertNotContains(Convert::raw2xml("That page is secured."), $response->getBody());
        $this->assertNotContains(Convert::raw2xml("You don't have access to this page"), $response->getBody());
        $this->assertContains('<input type="submit" name="action_dologin"', $response->getBody());

        // BackURL with permission error (wrong permissions) should not redirect
        $this->logInAs('grouplessmember');
        $response = $this->getRecursive('SecurityTest_SecuredController');
        $this->assertContains(Convert::raw2xml("You don't have access to this page"), $response->getBody());
        $this->assertContains(
            '<input type="submit" name="action_logout" value="Log in as someone else"',
            $response->getBody()
        );

        // Directly accessing this page should attempt to follow the BackURL, but stop when it encounters the error
        $response = $this->getRecursive('Security/login?BackURL=SecurityTest_SecuredController/');
        $this->assertContains(Convert::raw2xml("You don't have access to this page"), $response->getBody());
        $this->assertContains(
            '<input type="submit" name="action_logout" value="Log in as someone else"',
            $response->getBody()
        );

        // Check correctly logged in admin doesn't generate the same errors
        $this->logInAs('admin');
        $response = $this->getRecursive('SecurityTest_SecuredController');
        $this->assertContains(Convert::raw2xml("Success"), $response->getBody());

        // Directly accessing this page should attempt to follow the BackURL and succeed
        $response = $this->getRecursive('Security/login?BackURL=SecurityTest_SecuredController/');
        $this->assertContains(Convert::raw2xml("Success"), $response->getBody());
    }

    public function testLogInAsSomeoneElse()
    {
        $member = DataObject::get_one(Member::class);

        /* Log in with any user that we can find */
        $this->session()->inst_set('loggedInAs', $member->ID);

        /* View the Security/login page */
        $response = $this->get(Config::inst()->get(Security::class, 'login_url'));

        $items = $this->cssParser()->getBySelector('#MemberLoginForm_LoginForm input.action');

        /* We have only 1 input, one to allow the user to log in as someone else */
        $this->assertEquals(count($items), 1, 'There is 1 input, allowing the user to log in as someone else.');

        $this->autoFollowRedirection = true;

        /* Submit the form, using only the logout action and a hidden field for the authenticator */
        $response = $this->submitForm(
            'MemberLoginForm_LoginForm',
            null,
            array(
                'AuthenticationMethod' => MemberAuthenticator::class,
                'action_dologout' => 1,
            )
        );

        /* We get a good response */
        $this->assertEquals($response->getStatusCode(), 200, 'We have a 200 OK response');
        $this->assertNotNull($response->getBody(), 'There is body content on the page');

        /* Log the user out */
        $this->session()->inst_set('loggedInAs', null);
    }

    public function testMemberIDInSessionDoesntExistInDatabaseHasToLogin()
    {
        /* Log in with a Member ID that doesn't exist in the DB */
        $this->session()->inst_set('loggedInAs', 500);

        $this->autoFollowRedirection = true;

        /* Attempt to get into the admin section */
        $response = $this->get(Config::inst()->get(Security::class, 'login_url'));

        $items = $this->cssParser()->getBySelector('#MemberLoginForm_LoginForm input.text');

        /* We have 2 text inputs - one for email, and another for the password */
        $this->assertEquals(count($items), 2, 'There are 2 inputs - one for email, another for password');

        $this->autoFollowRedirection = false;

        /* Log the user out */
        $this->session()->inst_set('loggedInAs', null);
    }

    public function testLoginUsernamePersists()
    {
        // Test that username does not persist
        $this->session()->inst_set('SessionForms.MemberLoginForm.Email', 'myuser@silverstripe.com');
        Security::config()->remember_username = false;
        $this->get(Config::inst()->get(Security::class, 'login_url'));
        $items = $this
            ->cssParser()
            ->getBySelector('#MemberLoginForm_LoginForm #MemberLoginForm_LoginForm_Email');
        $this->assertEquals(1, count($items));
        $this->assertEmpty((string)$items[0]->attributes()->value);
        $this->assertEquals('off', (string)$items[0]->attributes()->autocomplete);
        $form = $this->cssParser()->getBySelector('#MemberLoginForm_LoginForm');
        $this->assertEquals(1, count($form));
        $this->assertEquals('off', (string)$form[0]->attributes()->autocomplete);

        // Test that username does persist when necessary
        $this->session()->inst_set('SessionForms.MemberLoginForm.Email', 'myuser@silverstripe.com');
        Security::config()->remember_username = true;
        $this->get(Config::inst()->get(Security::class, 'login_url'));
        $items = $this
            ->cssParser()
            ->getBySelector('#MemberLoginForm_LoginForm #MemberLoginForm_LoginForm_Email');
        $this->assertEquals(1, count($items));
        $this->assertEquals('myuser@silverstripe.com', (string)$items[0]->attributes()->value);
        $this->assertNotEquals('off', (string)$items[0]->attributes()->autocomplete);
        $form = $this->cssParser()->getBySelector('#MemberLoginForm_LoginForm');
        $this->assertEquals(1, count($form));
        $this->assertNotEquals('off', (string)$form[0]->attributes()->autocomplete);
    }

    public function testExternalBackUrlRedirectionDisallowed()
    {
        // Test internal relative redirect
        $response = $this->doTestLoginForm('noexpiry@silverstripe.com', '1nitialPassword', 'testpage');
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertRegExp(
            '/testpage/',
            $response->getHeader('Location'),
            "Internal relative BackURLs work when passed through to login form"
        );
        // Log the user out
        $this->session()->inst_set('loggedInAs', null);

        // Test internal absolute redirect
        $response = $this->doTestLoginForm(
            'noexpiry@silverstripe.com',
            '1nitialPassword',
            Director::absoluteBaseURL() . 'testpage'
        );
        // for some reason the redirect happens to a relative URL
        $this->assertRegExp(
            '/^' . preg_quote(Director::absoluteBaseURL(), '/') . 'testpage/',
            $response->getHeader('Location'),
            "Internal absolute BackURLs work when passed through to login form"
        );
        // Log the user out
        $this->session()->inst_set('loggedInAs', null);

        // Test external redirect
        $response = $this->doTestLoginForm('noexpiry@silverstripe.com', '1nitialPassword', 'http://myspoofedhost.com');
        $this->assertNotRegExp(
            '/^' . preg_quote('http://myspoofedhost.com', '/') . '/',
            (string)$response->getHeader('Location'),
            "Redirection to external links in login form BackURL gets prevented as a measure against spoofing attacks"
        );

        // Test external redirection on ChangePasswordForm
        $this->get('Security/changepassword?BackURL=http://myspoofedhost.com');
        $changedResponse = $this->doTestChangepasswordForm('1nitialPassword', 'changedPassword');
        $this->assertNotRegExp(
            '/^' . preg_quote('http://myspoofedhost.com', '/') . '/',
            (string)$changedResponse->getHeader('Location'),
            "Redirection to external links in change password form BackURL gets prevented to stop spoofing attacks"
        );

        // Log the user out
        $this->session()->inst_set('loggedInAs', null);
    }

    /**
     * Test that the login form redirects to the change password form after logging in with an expired password
     */
    public function testExpiredPassword()
    {
        /* BAD PASSWORDS ARE LOCKED OUT */
        $badResponse = $this->doTestLoginForm('testuser@example.com', 'badpassword');
        $this->assertEquals(302, $badResponse->getStatusCode());
        $this->assertRegExp('/Security\/login/', $badResponse->getHeader('Location'));
        $this->assertNull($this->session()->inst_get('loggedInAs'));

        /* UNEXPIRED PASSWORD GO THROUGH WITHOUT A HITCH */
        $goodResponse = $this->doTestLoginForm('testuser@example.com', '1nitialPassword');
        $this->assertEquals(302, $goodResponse->getStatusCode());
        $this->assertEquals(
            Controller::join_links(Director::absoluteBaseURL(), 'test/link'),
            $goodResponse->getHeader('Location')
        );
        $this->assertEquals($this->idFromFixture(Member::class, 'test'), $this->session()->inst_get('loggedInAs'));

        /* EXPIRED PASSWORDS ARE SENT TO THE CHANGE PASSWORD FORM */
        $expiredResponse = $this->doTestLoginForm('expired@silverstripe.com', '1nitialPassword');
        $this->assertEquals(302, $expiredResponse->getStatusCode());
        $this->assertEquals(
            Director::absoluteURL('Security/changepassword').'?BackURL=test%2Flink',
            Director::absoluteURL($expiredResponse->getHeader('Location'))
        );
        $this->assertEquals(
            $this->idFromFixture(Member::class, 'expiredpassword'),
            $this->session()->inst_get('loggedInAs')
        );

        // Make sure it redirects correctly after the password has been changed
        $this->mainSession->followRedirection();
        $changedResponse = $this->doTestChangepasswordForm('1nitialPassword', 'changedPassword');
        $this->assertEquals(302, $changedResponse->getStatusCode());
        $this->assertEquals(
            Controller::join_links(Director::absoluteBaseURL(), 'test/link'),
            $changedResponse->getHeader('Location')
        );
    }

    public function testChangePasswordForLoggedInUsers()
    {
        $goodResponse = $this->doTestLoginForm('testuser@example.com', '1nitialPassword');

        // Change the password
        $this->get('Security/changepassword?BackURL=test/back');
        $changedResponse = $this->doTestChangepasswordForm('1nitialPassword', 'changedPassword');
        $this->assertEquals(302, $changedResponse->getStatusCode());
        $this->assertEquals(
            Controller::join_links(Director::absoluteBaseURL(), 'test/back'),
            $changedResponse->getHeader('Location')
        );
        $this->assertEquals($this->idFromFixture(Member::class, 'test'), $this->session()->inst_get('loggedInAs'));

        // Check if we can login with the new password
        $goodResponse = $this->doTestLoginForm('testuser@example.com', 'changedPassword');
        $this->assertEquals(302, $goodResponse->getStatusCode());
        $this->assertEquals(
            Controller::join_links(Director::absoluteBaseURL(), 'test/link'),
            $goodResponse->getHeader('Location')
        );
        $this->assertEquals($this->idFromFixture(Member::class, 'test'), $this->session()->inst_get('loggedInAs'));
    }

    public function testChangePasswordFromLostPassword()
    {
        $admin = $this->objFromFixture(Member::class, 'test');
        $admin->FailedLoginCount = 99;
        $admin->LockedOutUntil = DBDatetime::now()->getValue();
        $admin->write();

        $this->assertNull($admin->AutoLoginHash, 'Hash is empty before lost password');

        // Request new password by email
        $response = $this->get('Security/lostpassword');
        $response = $this->post('Security/LostPasswordForm', array('Email' => 'testuser@example.com'));

        $this->assertEmailSent('testuser@example.com');

        // Load password link from email
        $admin = DataObject::get_by_id(Member::class, $admin->ID);
        $this->assertNotNull($admin->AutoLoginHash, 'Hash has been written after lost password');

        // We don't have access to the token - generate a new token and hash pair.
        $token = $admin->generateAutologinTokenAndStoreHash();

        // Check.
        $response = $this->get('Security/changepassword/?m='.$admin->ID.'&t=' . $token);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals(
            Director::absoluteURL('Security/changepassword'),
            Director::absoluteURL($response->getHeader('Location'))
        );

        // Follow redirection to form without hash in GET parameter
        $response = $this->get('Security/changepassword');
        $changedResponse = $this->doTestChangepasswordForm('1nitialPassword', 'changedPassword');
        $this->assertEquals($this->idFromFixture(Member::class, 'test'), $this->session()->inst_get('loggedInAs'));

        // Check if we can login with the new password
        $goodResponse = $this->doTestLoginForm('testuser@example.com', 'changedPassword');
        $this->assertEquals(302, $goodResponse->getStatusCode());
        $this->assertEquals($this->idFromFixture(Member::class, 'test'), $this->session()->inst_get('loggedInAs'));

        $admin = DataObject::get_by_id(Member::class, $admin->ID, false);
        $this->assertNull($admin->LockedOutUntil);
        $this->assertEquals(0, $admin->FailedLoginCount);
    }

    public function testRepeatedLoginAttemptsLockingPeopleOut()
    {
        $local = i18n::get_locale();
        i18n::set_locale('en_US');

        Member::config()->lock_out_after_incorrect_logins = 5;
        Member::config()->lock_out_delay_mins = 15;

        // Login with a wrong password for more than the defined threshold
        for ($i = 1; $i <= Member::config()->lock_out_after_incorrect_logins+1; $i++) {
            $this->doTestLoginForm('testuser@example.com', 'incorrectpassword');
            $member = DataObject::get_by_id(Member::class, $this->idFromFixture(Member::class, 'test'));

            if ($i < Member::config()->lock_out_after_incorrect_logins) {
                $this->assertNull(
                    $member->LockedOutUntil,
                    'User does not have a lockout time set if under threshold for failed attempts'
                );
                $this->assertHasMessage(
                    _t(
                        'Member.ERRORWRONGCRED',
                        'The provided details don\'t seem to be correct. Please try again.'
                    )
                );
            } else {
                // Fuzzy matching for time to avoid side effects from slow running tests
                $this->assertGreaterThan(
                    time() + 14*60,
                    strtotime($member->LockedOutUntil),
                    'User has a lockout time set after too many failed attempts'
                );
            }

            $msg = _t(
                'Member.ERRORLOCKEDOUT2',
                'Your account has been temporarily disabled because of too many failed attempts at ' .
                'logging in. Please try again in {count} minutes.',
                null,
                array('count' => Member::config()->lock_out_delay_mins)
            );
            if ($i > Member::config()->lock_out_after_incorrect_logins) {
                $this->assertHasMessage($msg);
            }
        }

        $this->doTestLoginForm('testuser@example.com', '1nitialPassword');
        $this->assertNull(
            $this->session()->inst_get('loggedInAs'),
            'The user can\'t log in after being locked out, even with the right password'
        );

        // (We fake this by re-setting LockedOutUntil)
        $member = DataObject::get_by_id(Member::class, $this->idFromFixture(Member::class, 'test'));
        $member->LockedOutUntil = date('Y-m-d H:i:s', time() - 30);
        $member->write();
        $this->doTestLoginForm('testuser@example.com', '1nitialPassword');
        $this->assertEquals(
            $this->session()->inst_get('loggedInAs'),
            $member->ID,
            'After lockout expires, the user can login again'
        );

        // Log the user out
        $this->session()->inst_set('loggedInAs', null);

        // Login again with wrong password, but less attempts than threshold
        for ($i = 1; $i < Member::config()->lock_out_after_incorrect_logins; $i++) {
            $this->doTestLoginForm('testuser@example.com', 'incorrectpassword');
        }
        $this->assertNull($this->session()->inst_get('loggedInAs'));
        $this->assertHasMessage(
            _t('Member.ERRORWRONGCRED', 'The provided details don\'t seem to be correct. Please try again.'),
            'The user can retry with a wrong password after the lockout expires'
        );

        $this->doTestLoginForm('testuser@example.com', '1nitialPassword');
        $this->assertEquals(
            $this->session()->inst_get('loggedInAs'),
            $member->ID,
            'The user can login successfully after lockout expires, if staying below the threshold'
        );

        i18n::set_locale($local);
    }

    public function testAlternatingRepeatedLoginAttempts()
    {
        Member::config()->lock_out_after_incorrect_logins = 3;

        // ATTEMPTING LOG-IN TWICE WITH ONE ACCOUNT AND TWICE WITH ANOTHER SHOULDN'T LOCK ANYBODY OUT

        $this->doTestLoginForm('testuser@example.com', 'incorrectpassword');
        $this->doTestLoginForm('testuser@example.com', 'incorrectpassword');

        $this->doTestLoginForm('noexpiry@silverstripe.com', 'incorrectpassword');
        $this->doTestLoginForm('noexpiry@silverstripe.com', 'incorrectpassword');

        $member1 = DataObject::get_by_id(Member::class, $this->idFromFixture(Member::class, 'test'));
        $member2 = DataObject::get_by_id(Member::class, $this->idFromFixture(Member::class, 'noexpiry'));

        $this->assertNull($member1->LockedOutUntil);
        $this->assertNull($member2->LockedOutUntil);

        // BUT, DOING AN ADDITIONAL LOG-IN WITH EITHER OF THEM WILL LOCK OUT, SINCE THAT IS THE 3RD FAILURE IN
        // THIS SESSION

        $this->doTestLoginForm('testuser@example.com', 'incorrectpassword');
        $member1 = DataObject::get_by_id(Member::class, $this->idFromFixture(Member::class, 'test'));
        $this->assertNotNull($member1->LockedOutUntil);

        $this->doTestLoginForm('noexpiry@silverstripe.com', 'incorrectpassword');
        $member2 = DataObject::get_by_id(Member::class, $this->idFromFixture(Member::class, 'noexpiry'));
        $this->assertNotNull($member2->LockedOutUntil);
    }

    public function testUnsuccessfulLoginAttempts()
    {
        Security::config()->login_recording = true;

        /* UNSUCCESSFUL ATTEMPTS WITH WRONG PASSWORD FOR EXISTING USER ARE LOGGED */
        $this->doTestLoginForm('testuser@example.com', 'wrongpassword');
        $attempt = DataObject::get_one(
            LoginAttempt::class,
            array(
            '"LoginAttempt"."Email"' => 'testuser@example.com'
            )
        );
        $this->assertTrue(is_object($attempt));
        $member = DataObject::get_one(
            Member::class,
            array(
            '"Member"."Email"' => 'testuser@example.com'
            )
        );
        $this->assertEquals($attempt->Status, 'Failure');
        $this->assertEquals($attempt->Email, 'testuser@example.com');
        $this->assertEquals($attempt->Member()->toMap(), $member->toMap());

        /* UNSUCCESSFUL ATTEMPTS WITH NONEXISTING USER ARE LOGGED */
        $this->doTestLoginForm('wronguser@silverstripe.com', 'wrongpassword');
        $attempt = DataObject::get_one(
            LoginAttempt::class,
            array(
            '"LoginAttempt"."Email"' => 'wronguser@silverstripe.com'
            )
        );
        $this->assertTrue(is_object($attempt));
        $this->assertEquals($attempt->Status, 'Failure');
        $this->assertEquals($attempt->Email, 'wronguser@silverstripe.com');
        $this->assertNotEmpty($this->getValidationResult()->getMessages(), 'An invalid email returns a message.');
    }

    public function testSuccessfulLoginAttempts()
    {
        Security::config()->login_recording = true;

        /* SUCCESSFUL ATTEMPTS ARE LOGGED */
        $this->doTestLoginForm('testuser@example.com', '1nitialPassword');
        $attempt = DataObject::get_one(
            LoginAttempt::class,
            array(
            '"LoginAttempt"."Email"' => 'testuser@example.com'
            )
        );
        $member = DataObject::get_one(
            Member::class,
            array(
            '"Member"."Email"' => 'testuser@example.com'
            )
        );
        $this->assertTrue(is_object($attempt));
        $this->assertEquals($attempt->Status, 'Success');
        $this->assertEquals($attempt->Email, 'testuser@example.com');
        $this->assertEquals($attempt->Member()->toMap(), $member->toMap());
    }

    public function testDatabaseIsReadyWithInsufficientMemberColumns()
    {
        $old = Security::$force_database_is_ready;
        Security::$force_database_is_ready = null;
        Security::$database_is_ready = false;
        DBClassName::clear_classname_cache();

        // Assumption: The database has been built correctly by the test runner,
        // and has all columns present in the ORM
        /**
 * @skipUpgrade
*/
        DB::get_schema()->renameField('Member', 'Email', 'Email_renamed');

        // Email column is now missing, which means we're not ready to do permission checks
        $this->assertFalse(Security::database_is_ready());

        // Rebuild the database (which re-adds the Email column), and try again
        $this->resetDBSchema(true);
        $this->assertTrue(Security::database_is_ready());

        Security::$force_database_is_ready = $old;
    }

    public function testSecurityControllerSendsRobotsTagHeader()
    {
        $response = $this->get(Config::inst()->get(Security::class, 'login_url'));
        $robotsHeader = $response->getHeader('X-Robots-Tag');
        $this->assertNotNull($robotsHeader);
        $this->assertContains('noindex', $robotsHeader);
    }

    public function testDoNotSendEmptyRobotsHeaderIfNotDefined()
    {
        Config::inst()->remove(Security::class, 'robots_tag');
        $response = $this->get(Config::inst()->get(Security::class, 'login_url'));
        $robotsHeader = $response->getHeader('X-Robots-Tag');
        $this->assertNull($robotsHeader);
    }

    /**
     * Execute a log-in form using Director::test().
     * Helper method for the tests above
     */
    public function doTestLoginForm($email, $password, $backURL = 'test/link')
    {
        $this->get(Config::inst()->get(Security::class, 'logout_url'));
        $this->session()->inst_set('BackURL', $backURL);
        $this->get(Config::inst()->get(Security::class, 'login_url'));

        return $this->submitForm(
            "MemberLoginForm_LoginForm",
            null,
            array(
                'Email' => $email,
                'Password' => $password,
                'AuthenticationMethod' => MemberAuthenticator::class,
                'action_dologin' => 1,
            )
        );
    }

    /**
     * Helper method to execute a change password form
     */
    public function doTestChangepasswordForm($oldPassword, $newPassword)
    {
        return $this->submitForm(
            "ChangePasswordForm_ChangePasswordForm",
            null,
            array(
                'OldPassword' => $oldPassword,
                'NewPassword1' => $newPassword,
                'NewPassword2' => $newPassword,
                'action_doChangePassword' => 1,
            )
        );
    }

    /**
     * Assert this message is in the current login form errors
     *
     * @param string $expected
     * @param string $errorMessage
     */
    protected function assertHasMessage($expected, $errorMessage = null)
    {
        $messages = [];
        $result = $this->getValidationResult();
        if ($result) {
            foreach ($result->getMessages() as $message) {
                $messages[] = $message['message'];
            }
        }

        $this->assertContains($expected, $messages, $errorMessage);
    }

    /**
     * Get validation result from last login form submission
     *
     * @return ValidationResult
     */
    protected function getValidationResult()
    {
        $result = $this->session()->inst_get('FormInfo.MemberLoginForm_LoginForm.result');
        if ($result) {
            return unserialize($result);
        }
        return null;
    }
}
