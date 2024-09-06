<?php

namespace SilverStripe\LDAP\Tasks;

use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class LDAPGroupSyncTask
 *
 * A task to sync all groups from a specific DN in LDAP to the SilverStripe site in Group models
 */
class LDAPGroupSyncTask extends BuildTask
{
    protected static string $commandName = 'LDAPGroupSyncTask';

    /**
     * @var array
     */
    private static $dependencies = [
        'LDAPService' => '%$' . LDAPService::class,
    ];

    /**
     * Setting this to true causes the sync to delete any local Group
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

    public function getTitle(): string
    {
        return _t(__CLASS__ . '.SYNCTITLE', 'Sync all groups from LDAP');
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        ini_set('max_execution_time', 900);

        // get all groups from LDAP, but only get the attributes we need.
        // this is useful to avoid holding onto too much data in memory
        // especially in the case where getGroups() would return a lot of groups
        $ldapGroups = $this->ldapService->getGroups(
            false,
            ['objectguid', 'samaccountname', 'dn', 'name', 'description'],
            // Change the indexing attribute so we can look up by GUID during the deletion process below.
            'objectguid'
        );

        $created = 0;
        $updated = 0;
        $deleted = 0;

        foreach ($ldapGroups as $data) {
            $group = Group::get()->filter('GUID', $data['objectguid'])->limit(1)->first();

            if (!($group && $group->exists())) {
                // create the initial Group with some internal fields
                $group = new Group();
                $group->GUID = $data['objectguid'];

                $output->writeln(sprintf(
                    'Creating new Group (GUID: %s, sAMAccountName: %s)',
                    $data['objectguid'],
                    $data['samaccountname']
                ));
                $created++;
            } else {
                $output->writeln(sprintf(
                    'Updating existing Group "%s" (ID: %s, GUID: %s, sAMAccountName: %s)',
                    $group->getTitle(),
                    $group->ID,
                    $data['objectguid'],
                    $data['samaccountname']
                ));
                $updated++;
            }

            try {
                $this->ldapService->updateGroupFromLDAP($group, $data);
            } catch (Exception $e) {
                $output->writeln('<error>' . $e->getMessage() . '</>');
                continue;
            }
        }

        // remove Group records that were previously imported, but no longer exist in the directory
        // NOTE: DB::query() here is used for performance and so we don't run out of memory
        if ($this->config()->destructive) {
            foreach (DB::query('SELECT "ID", "GUID" FROM "Group" WHERE "GUID" IS NOT NULL') as $record) {
                if (!isset($ldapGroups[$record['GUID']])) {
                    $group = Group::get()->byId($record['ID']);

                    $output->writeln(sprintf(
                        'Removing Group "%s" (GUID: %s) that no longer exists in LDAP.',
                        $group->Title,
                        $group->GUID
                    ));

                    try {
                        // Cascade into mappings, just to clean up behind ourselves.
                        foreach ($group->LDAPGroupMappings() as $mapping) {
                            $mapping->delete();
                        }
                        $group->delete();
                    } catch (Exception $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</>');
                        continue;
                    }

                    $deleted++;
                }
            }
        }

        $this->invokeWithExtensions('onAfterLDAPGroupSyncTask', $output);

        $output->writeln(sprintf(
            'Done. Created %s records. Updated %s records. Deleted %s records.',
            $created,
            $updated,
            $deleted
        ));
        return Command::SUCCESS;
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
