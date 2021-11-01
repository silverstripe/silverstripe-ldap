<?php

namespace SilverStripe\LDAP\Tests\Services;

use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\LDAP\Extensions\LDAPGroupExtension;
use SilverStripe\LDAP\Extensions\LDAPMemberExtension;
use SilverStripe\LDAP\Model\LDAPGateway;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\LDAP\Tests\Model\LDAPFakeGateway;
use SilverStripe\LDAP\Tests\Model\LDAPFakeMember;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\ORM\DataQuery;

class LDAPServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = 'LDAPServiceTest.yml';


    /**
     * @var LDAPService
     */
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = new LDAPFakeGateway();
        Injector::inst()->registerService($gateway, LDAPGateway::class);

        $service = Injector::inst()->get(LDAPService::class);
        $service->setGateway($gateway);

        $this->service = $service;

        Config::modify()->set(LDAPGateway::class, 'options', ['host' => '1.2.3.4']);
        Config::modify()->set(LDAPService::class, 'groups_search_locations', [
            'CN=Users,DC=playpen,DC=local',
            'CN=Others,DC=playpen,DC=local'
        ]);
        // Prevent other module extension hooks from executing during write() etc.
        Config::modify()->remove(Member::class, 'extensions');
        Config::modify()->remove(Group::class, 'extensions');
        Config::modify()->set(Member::class, 'update_ldap_from_local', false);
        Config::modify()->set(Member::class, 'create_users_in_ldap', false);
        Config::modify()->set(
            Group::class,
            'extensions',
            [LDAPGroupExtension::class]
        );
        Config::modify()->set(
            Member::class,
            'extensions',
            [LDAPMemberExtension::class]
        );

        // Disable Monolog logging to stderr by default if you don't give it a handler
        $this->service->getLogger()->pushHandler(new \Monolog\Handler\NullHandler());
    }

    public function testGroups()
    {
        $expected = [
            'CN=Group1,CN=Users,DC=playpen,DC=local' => ['dn' => 'CN=Group1,CN=Users,DC=playpen,DC=local'],
            'CN=Group2,CN=Users,DC=playpen,DC=local' => ['dn' => 'CN=Group2,CN=Users,DC=playpen,DC=local'],
            'CN=Group3,CN=Users,DC=playpen,DC=local' => ['dn' => 'CN=Group3,CN=Users,DC=playpen,DC=local'],
            'CN=Group4,CN=Users,DC=playpen,DC=local' => ['dn' => 'CN=Group4,CN=Users,DC=playpen,DC=local'],
            'CN=Group5,CN=Users,DC=playpen,DC=local' => ['dn' => 'CN=Group5,CN=Users,DC=playpen,DC=local'],
            'CN=Group6,CN=Others,DC=playpen,DC=local' => ['dn' => 'CN=Group6,CN=Others,DC=playpen,DC=local'],
            'CN=Group7,CN=Others,DC=playpen,DC=local' => ['dn' => 'CN=Group7,CN=Others,DC=playpen,DC=local'],
            'CN=Group8,CN=Others,DC=playpen,DC=local' => ['dn' => 'CN=Group8,CN=Others,DC=playpen,DC=local']
        ];

        $results = $this->service->getGroups();

        $this->assertEquals($expected, $results);
    }

    public function testUpdateMemberFromLDAP()
    {
        Config::modify()->set(
            Member::class,
            'ldap_field_mappings',
            [
                'givenname' => 'FirstName',
                'sn' => 'Surname',
                'mail' => 'Email',
            ]
        );

        $member = new Member();
        $member->GUID = '123';

        $this->service->updateMemberFromLDAP($member);

        $this->assertTrue($member->ID > 0, 'updateMemberFromLDAP writes the member');
        $this->assertEquals('123', $member->GUID, 'GUID remains the same');
        $this->assertEquals('Joe', $member->FirstName, 'FirstName updated from LDAP');
        $this->assertEquals('Bloggs', $member->Surname, 'Surname updated from LDAP');
        $this->assertEquals('joe@bloggs.com', $member->Email, 'Email updated from LDAP');
    }

    /**
     * LDAP should correctly assign a member to the groups, if there's a mapping between LDAPGroupMapping and Group
     * and LDAP returns the mapping via 'memberof'
     */
    public function testAssignGroupMember()
    {
        Config::modify()->set(
            Member::class,
            'ldap_field_mappings',
            [
                'givenname' => 'FirstName',
                'sn' => 'Surname',
                'mail' => 'Email',
            ]
        );

        $member = new Member();
        $member->GUID = '789';

        $this->service->updateMemberFromLDAP($member);
        $this->assertCount(4, $member->Groups());
    }

    /**
     * LDAP should correctly assign a member to the groups, if there's a mapping between LDAPGroupMapping and Group
     * and LDAP returns the mapping via 'memberof'
     */
    public function testAssignRemovedGroupMember()
    {
        Config::modify()->set(
            Member::class,
            'ldap_field_mappings',
            [
                'givenname' => 'FirstName',
                'sn' => 'Surname',
                'mail' => 'Email',
            ]
        );

        $member = new Member();
        $member->GUID = '789';
        $member->write();

        // Pretend we're the module, and generate some mappings.
        Group::get()->each(function ($group) use ($member) {
            $group->Members()->add($member, [
                'IsImportedFromLDAP' => '1'
            ]);
        });

        $this->assertCount(Group::get()->count(), $member->Groups());

        // There should only be 4 groups assigned from LDAP for this user, two should be removed.
        $this->service->updateMemberFromLDAP($member);
        $this->assertCount(4, $member->Groups());
    }

    /**
     * If the LDAPService setting reset_missing_attributes is true, reset fields if the attribute isn't present
     * in the response information.
     */
    public function testUpdateMemberResetAttributesFromLDAP()
    {
        Config::modify()->set(
            Member::class,
            'ldap_field_mappings',
            [
                'givenname' => 'FirstName',
                'sn' => 'Surname',
                'mail' => 'Email',
                'specialattribute' => 'specialattribute'
            ]
        );

        Config::modify()->set(LDAPService::class, 'reset_missing_attributes', true);

        $member = new Member();
        $member->GUID = '123';
        $member->specialattribute = "I should be removed because LDAP said so";

        $this->service->updateMemberFromLDAP($member);

        $this->assertTrue($member->ID > 0, 'updateMemberFromLDAP writes the member');
        $this->assertEquals('123', $member->GUID, 'GUID remains the same');
        $this->assertEquals('Joe', $member->FirstName, 'FirstName updated from LDAP');
        $this->assertEquals('Bloggs', $member->Surname, 'Surname updated from LDAP');
        $this->assertEquals('joe@bloggs.com', $member->Email, 'Email updated from LDAP');
        $this->assertNull($member->specialattribute);
    }

    /**
     * If the LDAPService setting reset_missing_attributes is true, delete the thumbnail (special case)
     * if it's not present in the response information.
     */
    public function testUpdateMemberResetThumbnailFromLDAP()
    {
        Config::modify()->set(
            Member::class,
            'ldap_field_mappings',
            [
                'givenname' => 'FirstName',
                'sn' => 'Surname',
                'mail' => 'Email',
                'thumbnailphoto' => 'ProfileImage'
            ]
        );

        Config::modify()->set(LDAPService::class, 'reset_missing_attributes', true);

        // Create a test 'image' for this member.
        /** @var File $file */
        TestAssetStore::activate('FileTest');
        $file = new Image();
        $file->setFromString(str_repeat('x', 1000000), "test.jpg");

        $member = new LDAPFakeMember();
        $member->GUID = '123';
        $member->setComponent("ProfileImage", $file);

        // make sure our Profile image is there.
        $this->assertNotNull($member->ProfileImage);
        $this->assertTrue($member->ProfileImage->exists());

        $this->service->updateMemberFromLDAP($member);

        // ensure the profile image was deleted, as it wasn't present in the attribute response from TestLDAP service
        $this->assertFalse($member->ProfileImage->exists());
    }

    /**
     * If the LDAPService setting reset_missing_attributes is true, delete the thumbnail (special case)
     * if it's not present in the response information.
     */
    public function testUpdateMemberLeaveThumbnailIfSameFromLDAP()
    {
        Config::modify()->set(
            Member::class,
            'ldap_field_mappings',
            [
                'givenname' => 'FirstName',
                'sn' => 'Surname',
                'mail' => 'Email',
                'thumbnailphoto' => 'ProfileImage'
            ]
        );

        Config::modify()->set(LDAPService::class, 'reset_missing_attributes', true);

        // Create a test 'image' for this member.
        /** @var File $file */
        TestAssetStore::activate('FileTest');

        $member = new LDAPFakeMember();
        $member->GUID = '456';

        // make sure our Profile image is there.
        $this->assertNotNull($member->ProfileImage);

        $this->service->updateMemberFromLDAP($member);

        // We have an image from the service.
        $this->assertTrue($member->ProfileImage->exists());

        // now make sure it doesn't change.
        $obj = $member->ProfileImage;

        $this->service->updateMemberFromLDAP($member);

        $this->assertSame($member->ProfileImage, $obj);
    }
}
