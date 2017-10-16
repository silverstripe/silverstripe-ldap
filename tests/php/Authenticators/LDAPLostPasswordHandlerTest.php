<?php

namespace SilverStripe\LDAP\Tests\Authenticators;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\Form;
use SilverStripe\LDAP\Authenticators\LDAPAuthenticator;
use SilverStripe\LDAP\Authenticators\LDAPLostPasswordHandler;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\ORM\FieldType\DBField;

class LDAPLostPasswordHandlerTest extends SapphireTest
{
    /**
     * @var LDAPLostPasswordHandler
     */
    protected $handler;

    protected function setUp()
    {
        parent::setUp();

        $authenticator = new LDAPAuthenticator;
        $this->handler = LDAPLostPasswordHandler::create('foo', $authenticator);
    }

    public function testGetAndSetLdapService()
    {
        $service = new LDAPService;

        $this->handler->setService($service);
        $this->assertSame($service, $this->handler->getService());
    }

    public function testLostPasswordFormLabels()
    {
        Config::modify()->set(LDAPAuthenticator::class, 'allow_email_login', 'no');
        $result = $this->handler->lostpassword();

        $this->assertInternalType('array', $result);
        $this->assertInstanceOf(DBField::class, $result['Content']);
        $this->assertInstanceOf(Form::class, $result['Form']);
        $this->assertContains('Enter your username and we will send you a link', $result['Content']->getValue());

        Config::modify()->set(LDAPAuthenticator::class, 'allow_email_login', 'yes');
        $result = $this->handler->lostpassword();
        $this->assertInternalType('array', $result);
        $this->assertContains('Enter your username or your email address', $result['Content']->getValue());
    }

    public function testPasswordSentMessage()
    {
        $request = (new HTTPRequest('GET', '/'))->setRouteParams(['OtherID' => 'banana']);

        $this->handler->setRequest($request);

        $result = $this->handler->passwordsent();

        $this->assertSame([
            'Title' => "Password reset link sent to 'banana'",
            'Content' => "Thank you! A reset link has been sent to 'banana', provided an account exists.",
            'Username' => 'banana'
        ], $this->handler->passwordsent());
    }
}
