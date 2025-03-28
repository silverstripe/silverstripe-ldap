<?php

namespace SilverStripe\LDAP\Tasks;

use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Deprecation;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;

/**
 * Class LDAPMemberSyncTask
 *
 * A task to sync all users from a specific DN in LDAP to the SilverStripe site, stored in Member objects
 */
class LDAPMemberSyncTask extends BuildTask
{
    /**
     * {@inheritDoc}
     * @var string
     */
    private static $segment = 'LDAPMemberSyncTask';

    /**
     * @var array
     */
    private static $dependencies = [
        'LDAPService' => '%$' . LDAPService::class,
    ];

    /**
     * Setting this to true causes the sync to delete any local Member
     * records that were previously imported, but no longer existing in LDAP.
     *
     * @config
     * @var bool
     */
    private static $destructive = false;

    /**
     * @var LDAPService
     */
    protected $ldapService;

    /**
     * @return string
     */
    public function getTitle()
    {
        return _t(__CLASS__ . '.SYNCTITLE', 'Sync all users from Active Directory');
    }

    /**
     * {@inheritDoc}
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        ini_set('max_execution_time', 3600); // 3600s = 1hr
        ini_set('memory_limit', '1024M'); // 1GB memory limit

        // get all users from LDAP, but only get the attributes we need.
        // this is useful to avoid holding onto too much data in memory
        // especially in the case where getUser() would return a lot of users
        $users = $this->ldapService->getUsers(array_merge(
            ['objectguid', 'cn', 'samaccountname', 'useraccountcontrol', 'memberof'],
            array_keys(Config::inst()->get(Member::class, 'ldap_field_mappings') ?? [])
        ));

        $start = time();

        $created = 0;
        $updated = 0;
        $deleted = 0;

        foreach ($users as $data) {
            $member = $this->findOrCreateMember($data);

            // If member exists already, we're updating - otherwise we're creating
            if ($member->exists()) {
                $updated++;
                Deprecation::withSuppressedNotice(function () use ($data, $member) {
                    $this->log(sprintf(
                        'Updating existing Member %s: "%s" (ID: %s, SAM Account Name: %s)',
                        $data['objectguid'],
                        $member->getName(),
                        $member->ID,
                        $data['samaccountname']
                    ));
                });
            } else {
                $created++;
                Deprecation::withSuppressedNotice(function () use ($data) {
                    $this->log(sprintf(
                        'Creating new Member %s: "%s" (SAM Account Name: %s)',
                        $data['objectguid'],
                        $data['cn'],
                        $data['samaccountname']
                    ));
                });
            }

            // Sync attributes from LDAP to the Member record. This will also write the Member record.
            // this is also responsible for putting the user into mapped groups
            try {
                $this->ldapService->updateMemberFromLDAP($member, $data);
            } catch (Exception $e) {
                Deprecation::withSuppressedNotice(fn() => $this->log($e->getMessage()));
                continue;
            }
        }

        // remove Member records that were previously imported, but no longer exist in the directory
        // NOTE: DB::query() here is used for performance and so we don't run out of memory
        if ($this->config()->destructive) {
            foreach (DB::query('SELECT "ID", "GUID" FROM "Member" WHERE "GUID" IS NOT NULL') as $record) {
                $member = Member::get()->byId($record['ID']);

                if (!isset($users[$record['GUID']])) {
                    Deprecation::withSuppressedNotice(function () use ($member) {
                        $this->log(sprintf(
                            'Removing Member "%s" (GUID: %s) that no longer exists in LDAP.',
                            $member->getName(),
                            $member->GUID
                        ));
                    });

                    try {
                        $member->delete();
                    } catch (Exception $e) {
                        Deprecation::withSuppressedNotice(fn() => $this->log($e->getMessage()));
                        continue;
                    }

                    $deleted++;
                }
            }
        }

        $this->invokeWithExtensions('onAfterLDAPMemberSyncTask');

        $end = time() - $start;

        Deprecation::withSuppressedNotice(function () use ($created, $updated, $deleted, $end) {
            $this->log(sprintf(
                'Done. Created %s records. Updated %s records. Deleted %s records. Duration: %s seconds',
                $created,
                $updated,
                $deleted,
                round($end ?? 0.0, 0)
            ));
        });
    }

    /**
     * Sends a message, formatted either for the CLI or browser
     *
     * @param string $message
     * @deprecated 2.3.0 Will be replaced with new $output parameter in the run() method
     */
    protected function log($message)
    {
        Deprecation::notice('2.3.0', 'Will be replaced with new $output parameter in the run() method');
        $message = sprintf('[%s] ', date('Y-m-d H:i:s')) . $message;
        echo Director::is_cli() ? ($message . PHP_EOL) : ($message . '<br>');
    }

    /**
     * Finds or creates a new {@link Member} object if the GUID provided by LDAP doesn't exist in the DB
     *
     * @param array $data The data from LDAP (specifically containing the objectguid value)
     * @return Member Either the existing member in the DB, or a new member object
     */
    protected function findOrCreateMember($data = [])
    {
        $member = Member::get()->filter('GUID', $data['objectguid'])->first();

        if (!($member && $member->exists())) {
            // create the initial Member with some internal fields
            $member = Member::create();
            $member->GUID = $data['objectguid'];
        }

        return $member;
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
