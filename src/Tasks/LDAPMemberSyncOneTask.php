<?php

namespace SilverStripe\LDAP\Tasks;

use Exception;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\LDAP\Services\LDAPService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class LDAPMemberSyncOneTask
 * @package SilverStripe\LDAP\Tasks
 *
 * Debug build task that can be used to sync a single member by providing their email address registered in LDAP.
 *
 * Usage: sake tasks:LDAPMemberSyncOneTask --email=john.smith@example.com
 */
class LDAPMemberSyncOneTask extends LDAPMemberSyncTask
{
    protected static string $commandName = 'LDAPMemberSyncOneTask';

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

    public function getTitle(): string
    {
        return _t(__CLASS__ . '.SYNCONETITLE', 'Sync single user from LDAP');
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $email = $input->getOption('email');

        if (!$email) {
            $output->writeln('<error>You must supply an email address.</>');
            return Command::INVALID;
        }

        $user = $this->ldapService->getUserByEmail($email);

        if (!$user) {
            $output->writeln(sprintf('<error>No user found in LDAP for email %s</>', $email));
            return Command::FAILURE;
        }

        $member = $this->findOrCreateMember($user);

        // If member exists already, we're updating - otherwise we're creating
        if ($member->exists()) {
            $output->writeln(sprintf(
                'Updating existing Member %s: "%s" (ID: %s, SAM Account Name: %s)',
                $user['objectguid'],
                $member->getName(),
                $member->ID,
                $user['samaccountname']
            ));
        } else {
            $output->writeln(sprintf(
                'Creating new Member %s: "%s" (SAM Account Name: %s)',
                $user['objectguid'],
                $user['cn'],
                $user['samaccountname']
            ));
        }

        $output->writeln('User data returned from LDAP follows:');
        $output->writeln(var_export($user, true));

        try {
            $this->ldapService->updateMemberFromLDAP($member, $user);
        } catch (Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</>');
            return Command::FAILURE;
        }

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

    public function getOptions(): array
    {
        return [
            new InputOption('email', null, InputOption::VALUE_REQUIRED, 'Email address of the member to sync (required)'),
        ];
    }
}
