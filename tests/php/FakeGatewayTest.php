<?php

namespace SilverStripe\LDAP\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\LDAP\Model\LDAPGateway;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\LDAP\Tests\Model\LDAPFakeGateway;

abstract class FakeGatewayTest extends SapphireTest
{
    /**
     * @var LDAPService
     */
    protected $service;

    protected function setUp()
    {
        parent::setUp();

        $gateway = new LDAPFakeGateway();
        Injector::inst()->registerService($gateway, LDAPGateway::class);

        $service = Injector::inst()->get(LDAPService::class);
        $service->setGateway($gateway);

        $this->service = $service;
    }
}
