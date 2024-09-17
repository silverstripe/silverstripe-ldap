<?php

namespace SilverStripe\LDAP\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Deprecation;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\Security\Member;

/**
 * Class LDAPMigrateExistingMembersTask
 *
 * Migrate existing Member records in SilverStripe into "LDAP Members" by matching existing emails
 * with ones that exist in a LDAP database for a given DN.
 */
class LDAPMigrateExistingMembersTask extends BuildTask
{
    /**
     * {@inheritDoc}
     * @var string
     */
    private static $segment = 'LDAPMigrateExistingMembersTask';

    /**
     * @var array
     */
    private static $dependencies = [
        'ldapService' => '%$' . LDAPService::class,
    ];

    /**
     * @var LDAPService
     */
    public $ldapService;

    /**
     * @return string
     */
    public function getTitle()
    {
        return _t(__CLASS__ . '.TITLE', 'Migrate existing members in SilverStripe into LDAP members');
    }

    /**
     * {@inheritDoc}
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        $users = $this->ldapService->getUsers(['objectguid', 'mail']);
        $start = time();
        $count = 0;

        foreach ($users as $user) {
            // Empty mail attribute for the user, nothing we can do. Skip!
            if (empty($user['mail'])) {
                continue;
            }

            $member = Member::get()->where(
                sprintf('"Email" = \'%s\' AND "GUID" IS NULL', Convert::raw2sql($user['mail']))
            )->first();

            if (!($member && $member->exists())) {
                continue;
            }

            // Member was found, migrate them by setting the GUID field
            $member->GUID = $user['objectguid'];
            $member->write();

            $count++;

            Deprecation::withSuppressedNotice(function () use ($member) {
                $this->log(sprintf(
                    'Migrated Member %s (ID: %s, Email: %s)',
                    $member->getName(),
                    $member->ID,
                    $member->Email
                ));
            });
        }

        $end = time() - $start;

        Deprecation::withSuppressedNotice(function () use ($count, $end) {
            $this->log(sprintf('Done. Migrated %s Member records. Duration: %s seconds', $count, round($end ?? 0.0, 0)));
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
}
