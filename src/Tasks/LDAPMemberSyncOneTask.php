<?php

namespace SilverStripe\LDAP\Tasks;

use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\Deprecation;
use SilverStripe\LDAP\Services\LDAPService;

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
        'LDAPService' => '%$' . LDAPService::class,
    ];

    /**
     * @var LDAPService
     */
    protected $ldapService;

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
            echo 'You must supply an email parameter to this method.', PHP_EOL;
            exit;
        }

        $user = $this->ldapService->getUserByEmail($email);

        if (!$user) {
            echo sprintf('No user found in LDAP for email %s', $email), PHP_EOL;
            exit;
        }

        $member = $this->findOrCreateMember($user);

        // If member exists already, we're updating - otherwise we're creating
        if ($member->exists()) {
            Deprecation::withSuppressedNotice(function () use ($user, $member) {
                $this->log(sprintf(
                    'Updating existing Member %s: "%s" (ID: %s, SAM Account Name: %s)',
                    $user['objectguid'],
                    $member->getName(),
                    $member->ID,
                    $user['samaccountname']
                ));
            });
        } else {
            $this->log(sprintf(
                'Creating new Member %s: "%s" (SAM Account Name: %s)',
                $user['objectguid'],
                $user['cn'],
                $user['samaccountname']
            ));
        }

        Deprecation::withSuppressedNotice(function () use ($user) {
            $this->log('User data returned from LDAP follows:');
            $this->log(var_export($user));
        });

        try {
            $this->ldapService->updateMemberFromLDAP($member, $user);
            Deprecation::withSuppressedNotice(fn() => $this->log('Done!'));
        } catch (Exception $e) {
            Deprecation::withSuppressedNotice(fn() => $this->log($e->getMessage()));
        }
    }

    /**
     * @param LDAPService $service
     * @return $this
     */
    public function setLDAPService(LDAPService $service)
    {
        $this->ldapService = $service;
        return $this;
    }
}
