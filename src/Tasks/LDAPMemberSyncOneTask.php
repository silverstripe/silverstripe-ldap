<?php

namespace SilverStripe\LDAP\Tasks;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\Security\Permission;

/**
 * Class LDAPMemberSyncOneTask
 * @package SilverStripe\LDAP\Tasks
 *
 * Debug build task that can be used to sync a single member by providing their email address registered in LDAP.
 *
 * Usage: /dev/tasks/LDAPMemberSyncOneTask?mail=john.smith@example.com
 */
class LDAPMemberSyncOneTask extends LDAPMemberSyncTask
{
    /**
     * {@inheritDoc}
     * @var string
     */
    private static $segment = 'LDAPMemberSyncOneTask';

    /**
     * @var array
     */
    private static $dependencies = [
        'ldapService' => '%$' . LDAPService::class,
    ];

    /**
     * @return string
     */
    public function getTitle()
    {
        return _t(__CLASS__ . '.SYNCONETITLE', 'Sync single user from LDAP');
    }

    /**
     * Syncs a single user based on the email address passed in the URL
     *
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        $email = $request->getVar('email');

        if (!$email) {
            die('You must supply an email parameter to this method.');
        }

        $user = $this->ldapService->getUserByEmail($email);

        if (!$user) {
            die(sprintf('No user found in LDAP for email %s', $email));
        }

        $member = $this->findOrCreateMember($user);

        // If member exists already, we're updating - otherwise we're creating
        if ($member->exists()) {
            $this->log(sprintf(
                'Updating existing Member %s: "%s" (ID: %s, SAM Account Name: %s)',
                $user['objectguid'],
                $member->getName(),
                $member->ID,
                $user['samaccountname']
            ));
        } else {
            $this->log(sprintf(
                'Creating new Member %s: "%s" (SAM Account Name: %s)',
                $user['objectguid'],
                $user['cn'],
                $user['samaccountname']
            ));
        }

        $this->log('User data returned from LDAP follows:');
        $this->log(var_export($user));

        try {
            $this->ldapService->updateMemberFromLDAP($member, $user);
            $this->log('Done!');
        } catch (Exception $e) {
            $this->log($e->getMessage());
        }
    }
}
