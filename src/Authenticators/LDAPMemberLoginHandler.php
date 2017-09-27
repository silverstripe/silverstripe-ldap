<?php

namespace SilverStripe\LDAP\Authenticators;

use SilverStripe\Security\MemberAuthenticator\LoginHandler;

class LDAPMemberLoginHandler extends LoginHandler
{
    /**
     * @var string
     */
    protected $authenticator_class = LDAPAuthenticator::class;
}
