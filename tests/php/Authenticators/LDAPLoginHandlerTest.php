<?php

namespace SilverStripe\LDAP\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\LDAP\Authenticators\LDAPAuthenticator;
use SilverStripe\LDAP\Authenticators\LDAPLoginHandler;
use SilverStripe\LDAP\Forms\LDAPLoginForm;

class LDAPLoginHandlerTest extends SapphireTest
{
    public function testReturnsLdapLoginForm()
    {
        $handler = LDAPLoginHandler::create('foo', new LDAPAuthenticator());

        $this->assertInstanceOf(LDAPLoginForm::class, $handler->loginForm());
    }
}
