<?php

namespace SilverStripe\LDAP\Tasks;

use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class LDAPMigrateExistingMembersTask
 *
 * Migrate existing Member records in SilverStripe into "LDAP Members" by matching existing emails
 * with ones that exist in a LDAP database for a given DN.
 */
class LDAPMigrateExistingMembersTask extends BuildTask
{
    protected static string $commandName = 'LDAPMigrateExistingMembersTask';

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

    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Migrate existing members in SilverStripe into LDAP members');
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $users = $this->ldapService->getUsers(['objectguid', 'mail']);
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

            $output->writeln(sprintf(
                'Migrated Member %s (ID: %s, Email: %s)',
                $member->getName(),
                $member->ID,
                $member->Email
            ));
        }

        $output->writeln("Done. Migrated $count Member records.");
        return Command::SUCCESS;
    }
}
