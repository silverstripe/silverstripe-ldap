<?php

namespace SilverStripe\LDAP\Tests\Authenticators;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SIlverStripe\LDAP\Authenticators\LDAPAuthenticator;
use SIlverStripe\LDAP\Authenticators\LDAPChangePasswordHandler;
use SIlverStripe\LDAP\Forms\LDAPChangePasswordForm;

class LDAPChangePasswordHandlerTest extends SapphireTest
{
    public function testReturnsLdapChangePasswordForm()
    {
        $this->logOut();

        $request = (new HTTPRequest('GET', '/'))
            ->setSession(new Session([]));

        $handler = LDAPChangePasswordHandler::create('foo', new LDAPAuthenticator)
            ->setRequest($request);

        $this->assertInstanceOf(LDAPChangePasswordForm::class, $handler->changePasswordForm());
    }
}
