<?php

namespace SilverStripe\LDAP\Authenticators;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\TextField;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\LostPasswordForm;
use SilverStripe\Security\MemberAuthenticator\LostPasswordHandler;
use SilverStripe\Security\Security;

class LDAPLostPasswordHandler extends LostPasswordHandler
{
    protected $authenticatorClass = LDAPAuthenticator::class;

    /**
     * Since the logout and dologin actions may be conditionally removed, it's necessary to ensure these
     * remain valid actions regardless of the member login state.
     *
     * @var array
     * @config
     */
    private static $allowed_actions = [
        'lostpassword',
        'LostPasswordForm',
        'passwordsent',
    ];

    private static $dependencies = [
        'service' => '%$' . LDAPService::class,
    ];

    /**
     * @var LDAPService
     */
    protected $service;

    /**
     * LDAP data for the provided member - is loaded by validateForgotPasswordData
     *
     * @var array
     */
    protected $ldapUserData = [];


    /**
     * @param string $link The URL to recreate this request handler
     * @param LDAPAuthenticator $authenticator
     */
    public function __construct($link, LDAPAuthenticator $authenticator)
    {
        $this->link = $link;
        $this->authenticatorClass = get_class($authenticator);
        parent::__construct($link);
    }

    protected function validateForgotPasswordData(array $data, LostPasswordForm $form)
    {
        Config::modify()->set(Member::class, 'unique_identifier_field', 'Login');

        // No need to protect against injections, LDAPService will ensure that this is safe
        $login = isset($data['Login']) ? trim($data['Login']) : '';

        // Ensure something was provided
        if (empty($login)) {
            if (Config::inst()->get(LDAPAuthenticator::class, 'allow_email_login') === 'yes') {
                $form->sessionMessage(
                    _t(
                        'SilverStripe\\LDAP\\Forms\\LDAPLoginForm.ENTERUSERNAMEOREMAIL',
                        'Please enter your username or your email address to get a password reset link.'
                    ),
                    'bad'
                );
            } else {
                $form->sessionMessage(
                    _t(
                        'SilverStripe\\LDAP\\Forms\\LDAPLoginForm.ENTERUSERNAME',
                        'Please enter your username to get a password reset link.'
                    ),
                    'bad'
                );
            }
            return $this->redirectBack();
        }

        // Look up the user and store it
        if (Email::is_valid_address($login)) {
            if (Config::inst()->get(LDAPAuthenticator::class, 'allow_email_login') != 'yes') {
                $form->sessionMessage(
                    _t(
                        'SilverStripe\\LDAP\\Forms\\LDAPLoginForm.USERNAMEINSTEADOFEMAIL',
                        'Please enter your username instead of your email to get a password reset link.'
                    ),
                    'bad'
                );
                return $this->redirect($this->Link('lostpassword'));
            }
            $this->ldapUserData = $this->getService()->getUserByEmail($login);
        } else {
            $this->ldapUserData = $this->getService()->getUserByUsername($login);
        }
    }

    protected function getMemberFromData(array $data)
    {
        $member = Member::get()->filter('GUID', $this->ldapUserData['objectguid'])->limit(1)->first();

        // User haven't been imported yet so do that now
        if (!$member || !$member->exists()) {
            $member = Member::create();
            $member->GUID = $this->ldapUserData['objectguid'];
        }

        // Update the users from LDAP so we are sure that the email is correct.
        // This will also write the Member record.
        $this->getService()->updateMemberFromLDAP($member, $this->ldapUserData, false);

        return $member;
    }

    protected function redirectToSuccess(array $data)
    {
        $link = Controller::join_links(
            $this->link('passwordsent'),
            rawurlencode($data['Login'] ?? ''),
            '/'
        );

        return $this->redirect($this->addBackURLParam($link));
    }

    /**
     * Factory method for the lost password form
     *
     * @return Form Returns the lost password form
     */
    public function lostPasswordForm()
    {
        $loginFieldLabel = (Config::inst()->get(LDAPAuthenticator::class, 'allow_email_login') === 'yes') ?
            _t('SilverStripe\\LDAP\\Forms\\LDAPLoginForm.USERNAMEOREMAIL', 'Username or email') :
            _t('SilverStripe\\LDAP\\Forms\\LDAPLoginForm.USERNAME', 'Username');
        $loginField = TextField::create('Login', $loginFieldLabel);

        $action = FormAction::create(
            'forgotPassword',
            _t('SilverStripe\\Security\\Security.BUTTONSEND', 'Send me the password reset link')
        );
        return LostPasswordForm::create(
            $this,
            $this->authenticatorClass,
            'LostPasswordForm',
            FieldList::create([$loginField]),
            FieldList::create([$action]),
            false
        );
    }

    public function lostpassword()
    {
        if (Config::inst()->get(LDAPAuthenticator::class, 'allow_email_login') === 'yes') {
            $message = _t(
                __CLASS__ . '.NOTERESETPASSWORDUSERNAMEOREMAIL',
                'Enter your username or your email address and we will send you a link with which '
                . 'you can reset your password'
            );
        } else {
            $message = _t(
                __CLASS__ . '.NOTERESETPASSWORDUSERNAME',
                'Enter your username and we will send you a link with which you can reset your password'
            );
        }

        return [
            'Content' => DBField::create_field('HTMLFragment', "<p>$message</p>"),
            'Form' => $this->lostPasswordForm(),
        ];
    }

    public function passwordsent()
    {
        $username = Convert::raw2xml(
            rawurldecode($this->getRequest()->param('OtherID') ?? '')
        );
        $username .= ($extension = $this->getRequest()->getExtension()) ? '.' . $extension : '';

        return [
            'Title' => _t(
                __CLASS__ . '.PASSWORDSENTHEADER',
                "Password reset link sent to '{username}'",
                ['username' => $username]
            ),
            'Content' =>
                _t(
                    __CLASS__ . '.PASSWORDSENTTEXT',
                    "Thank you! A reset link has been sent to '{username}', provided an account exists.",
                    ['username' => $username]
                ),
            'Username' => $username
        ];
    }

    /**
     * Get the LDAP service
     *
     * @return LDAPService
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * Set the LDAP service
     *
     * @param LDAPService $service
     * @return $this
     */
    public function setService(LDAPService $service)
    {
        $this->service = $service;
        return $this;
    }
}
