<?php

namespace SilverStripe\LDAP\Authenticators;

use SilverStripe\LDAP\Forms\LDAPChangePasswordForm;
use SilverStripe\Security\MemberAuthenticator\ChangePasswordHandler;

class LDAPChangePasswordHandler extends ChangePasswordHandler
{
    private static $allowed_actions = [
        'changepassword',
        'changePasswordForm',
    ];

    /**
     * Factory method for the lost password form
     *
     * @return LDAPChangePasswordForm
     */
    public function changePasswordForm()
    {
        return LDAPChangePasswordForm::create($this, 'ChangePasswordForm');
    }
}
