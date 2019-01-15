<?php

namespace SilverStripe\LDAP\Tests\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;
use SilverStripe\Assets\Image;

class LDAPFakeMember extends Member implements TestOnly
{
    /**
     * @var array
     */
    private static $has_one = [
        'ProfileImage' => Image::class
    ];

    /**
     * We don't actually want/need to change anything
     *
     * @return int|void
     */
    public function write($showDebug = false, $forceInsert = false, $forceWrite = false, $writeComponents = false)
    {
        // Noop
    }
}
