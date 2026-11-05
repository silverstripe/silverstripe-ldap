<?php

namespace SilverStripe\LDAP\Model;

use Laminas\Authentication\Result;
use Exception;
use Iterator;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\LDAP\Iterators\LDAPIterator;
use Laminas\Authentication\Adapter\Ldap as LdapAdapter;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Storage\NonPersistent as NonPersistentStorage;
use Laminas\Ldap\Exception\LdapException;
use Laminas\Ldap\Filter\AbstractFilter;
use Laminas\Ldap\Ldap;
use Laminas\Stdlib\ErrorHandler;
use function ldap_control_paged_result;

/**
 * Class LDAPGateway
 *
 * Works within the LDAP domain model to provide basic operations.
 * These are exclusively used in LDAPService for constructing more complex operations.
 */
class LDAPGateway
{
    use Injectable;
    use Extensible;
    use Configurable;

    /**
     * @var array
     * @config
     */
    private static $options = [];

    /**
     * Refuse to send password material over an unencrypted connection.
     *
     * Binding a user, changing a password and resetting a password all put secrets on the wire.
     * With plain ldap:// those travel in cleartext, so by default this module fails closed and
     * requires either useSsl (ldaps://) or useStartTls in the connection options.
     *
     * Turning this off is only sensible where the transport is already protected by other means,
     * such as an ldapi:// socket or a tunnel that terminates at the directory server.
     *
     * Note that useSsl on its own does not guarantee the certificate is verified - that is decided
     * by the LDAP client library, via TLS_REQCERT in ldap.conf or LDAP_OPT_X_TLS_REQUIRE_CERT.
     *
     * @var bool
     * @config
     */
    private static $require_encrypted_connection = true;

    /**
     * @var Laminas\Ldap\Ldap
     */
    private $ldap;

    public function __construct()
    {
        // due to dependency injection this class can be created without any LDAP options set
        // and \Laminas\Ldap\Ldap will throw a warning with an empty array
        if (count($this->config()->get('options') ?? [])) {
            $this->ldap = new Ldap($this->config()->get('options'));
        }
    }

    /**
     * @return Ldap|null The underlying Laminas\Ldap\Ldap class, so that methods can be called directly.
     *                   Null if no connection options have been configured - see requireLdap().
     */
    public function getLdap()
    {
        return $this->ldap;
    }

    /**
     * Like getLdap(), but fails loudly when no connection options have been configured, rather than
     * handing a null connection to Laminas and failing with an opaque TypeError further down the stack.
     *
     * @return Ldap
     * @throws Exception
     */
    protected function requireLdap()
    {
        if (!$this->ldap) {
            throw new Exception(sprintf(
                '%s::options must be configured before LDAP can be queried',
                static::class
            ));
        }

        return $this->ldap;
    }

    /**
     * Guard for operations that put password material on the wire. Throws unless the connection is
     * encrypted, so an unencrypted directory can never be handed a credential by accident.
     *
     * @param string $operation Named in the exception, so the caller is identifiable in logs.
     * @return void
     * @throws Exception
     * @see self::$require_encrypted_connection
     */
    protected function requireEncryptedConnection($operation)
    {
        if (!$this->config()->get('require_encrypted_connection')) {
            return;
        }

        $options = $this->config()->get('options') ?? [];
        if (!empty($options['useSsl']) || !empty($options['useStartTls'])) {
            return;
        }

        throw new Exception(sprintf(
            '%s refuses to %s over an unencrypted connection. Set useSsl or useStartTls in '
                . '%s::options, or disable %s::require_encrypted_connection if the transport is '
                . 'already protected by other means.',
            static::class,
            $operation,
            static::class,
            static::class
        ));
    }

    protected function searchWithIterator($filter, $baseDn = null, $attributes = [])
    {
        $ldap = $this->requireLdap();
        $pageSize = 500; // This must be less than the maximum size for a single page in LDAP (default 1000)
        $records = new LDAPIterator($ldap, $filter, $baseDn, $attributes, $pageSize);
        $results = $this->processSearchResults($records);

        if (version_compare(PHP_VERSION, '8.0.0') < 0) {
            // Reset the LDAP pagination control back to the original, otherwise all further LDAP read queries fail
            ldap_control_paged_result($ldap->getResource(), 1000);
        }

        return $results;
    }

    /**
     * Query the LDAP directory with the given filter.
     *
     * @param string $filter The string to filter by, e.g. (objectClass=user)
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *                   Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @param string $sort Sort results by this attribute if given
     * @return array
     */
    protected function search($filter, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [], $sort = '')
    {
        $records = $this->requireLdap()->search($filter, $baseDn, $scope, $attributes, $sort);
        return $this->processSearchResults($records);
    }

    /**
     * Processes results from either LDAPGateway::search() or LDAPGateway::searchAll(), expecting eitheran array of records
     *
     * @param Iterator $records
     * @return array
     */
    protected function processSearchResults($records)
    {
        $results = [];
        foreach ($records as $record) {
            foreach ($record as $attribute => $value) {
                // if the value is an array with a single value, e.g. 'samaccountname' => array(0 => 'myusername')
                // then make sure it's just set in the results as 'samaccountname' => 'myusername' so that it
                // can be used directly by ArrayData
                if (is_array($value) && count($value ?? []) === 1) {
                    $value = $value[0];
                }

                // ObjectGUID and ObjectSID attributes are in binary, we need to convert those to strings
                if ($attribute == 'objectguid') {
                    $value = LDAPUtil::bin_to_str_guid($value);
                }

                if ($attribute == 'objectsid') {
                    $value = LDAPUtil::bin_to_str_sid($value);
                }

                $record[$attribute] = $value;
            }

            $results[] = $record;
        }

        return $results;
    }

    /**
     * Authenticate the given username and password with LDAP.
     *
     * @param string $username
     * @param string $password
     * @return Result
     * @throws Exception
     */
    public function authenticate($username, $password)
    {
        // Doesn't go through requireLdap(), because the adapter builds its own connection from the
        // same options - but fail with the same message rather than one from deep inside Laminas.
        $this->requireLdap();
        $this->requireEncryptedConnection('bind a user');

        // NonPersistent storage, because AuthenticationService otherwise defaults to
        // Storage\Session, which needs laminas-session - and that cannot be installed
        // alongside Silverstripe 6 (it pins laminas-servicemanager 3.x, i.e. psr/container ^1.0,
        // while the framework requires psr/container ^2.0). Nothing here reads Laminas' stored
        // identity anyway: only the returned Result is used, and Silverstripe owns the session.
        $auth = new AuthenticationService(new NonPersistentStorage());
        $adapter = new LdapAdapter([$this->config()->get('options')], $username, $password);
        return $auth->authenticate($adapter);
    }

    /**
     * Query for LDAP nodes (organizational units, containers, and domains).
     *
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *          Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @param string $sort Sort results by this attribute if given
     * @return array
     */
    public function getNodes($baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [], $sort = '')
    {
        $filter = '(|(objectClass=organizationalUnit)(objectClass=container)(objectClass=domain))';

        $this->extend('updateNodesFilter', $filter);

        return $this->search(
            $filter,
            $baseDn,
            $scope,
            $attributes,
            $sort
        );
    }

    /**
     * Query for LDAP groups.
     *
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *          Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @param string $sort Sort results by this attribute if given
     * @return array
     */
    public function getGroups($baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [], $sort = '')
    {
        $filter = '(objectClass=group)';

        $this->extend('updateGroupsFilter', $filter);

        return $this->search($filter, $baseDn, $scope, $attributes, $sort);
    }

    /**
     * Return all nested AD groups underneath a specific DN
     *
     * @param string $dn
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *                  Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @return array
     */
    public function getNestedGroups($dn, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        $filter = sprintf(
            '(&(objectClass=group)(memberOf:1.2.840.113556.1.4.1941:=%s))',
            AbstractFilter::escapeValue($dn)
        );

        $this->extend('updateNestedGroupsFilter', $filter);

        return $this->search(
            $filter,
            $baseDn,
            $scope,
            $attributes
        );
    }

    /**
     * Return a particular LDAP group by objectGUID value.
     *
     * @param string $guid
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *                  Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @return array
     */
    public function getGroupByGUID($guid, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        return $this->search(
            sprintf('(&(objectClass=group)(objectGUID=%s))', LDAPUtil::str_to_hex_guid($guid, true)),
            $baseDn,
            $scope,
            $attributes
        );
    }

    /**
     * Return a particular LDAP group by DN value.
     *
     * @param string $dn
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *              Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @return array
     */
    public function getGroupByDN($dn, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        return $this->search(
            sprintf('(&(objectClass=group)(distinguishedname=%s))', AbstractFilter::escapeValue($dn)),
            $baseDn,
            $scope,
            $attributes
        );
    }

    /**
     * Query for LDAP users, but don't include built-in user accounts.
     *
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *                  Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @param string $sort Sort results by this attribute if given
     * @return array
     */
    public function getUsers($baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [], $sort = '')
    {
        $filter = '(&(objectClass=user)(!(objectClass=computer))(!(samaccountname=Guest))(!(samaccountname=Administrator))(!(samaccountname=krbtgt)))';

        $this->extend('updateUsersFilter', $filter);

        return $this->search(
            $filter,
            $baseDn,
            $scope,
            $attributes,
            $sort
        );
    }

    /**
     * Query for LDAP users, but don't include built-in user accounts. Iterate over all users, regardless of the paging
     * size control built into the LDAP server.
     *
     * @param string|null $baseDn The DN to search within. Defaults to the base DN applied to the connection.
     * @param array $attributes Specify user attributes to be returned. Defaults to returning all attributes.
     * @return array
     */
    public function getUsersWithIterator($baseDn = null, $attributes = [])
    {
        $filter = '(&(objectClass=user)(!(objectClass=computer))(!(samaccountname=Guest))(!(samaccountname=Administrator))(!(samaccountname=krbtgt)))';

        $this->extend('updateUsersWithIteratorFilter', $filter);

        return $this->searchWithIterator(
            $filter,
            $baseDn,
            $attributes
        );
    }

    /**
     * Return a particular LDAP user by objectGUID value.
     *
     * @param string $guid
     * @return array
     */
    public function getUserByGUID($guid, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        return $this->search(
            sprintf('(&(objectClass=user)(objectGUID=%s))', LDAPUtil::str_to_hex_guid($guid, true)),
            $baseDn,
            $scope,
            $attributes
        );
    }

    /**
     * Return a particular LDAP user by DN value.
     *
     * @param string $dn
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *                  Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @return array
     */
    public function getUserByDN($dn, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        return $this->search(
            sprintf('(&(objectClass=user)(distinguishedname=%s))', AbstractFilter::escapeValue($dn)),
            $baseDn,
            $scope,
            $attributes
        );
    }

    /**
     * Return a particular LDAP user by mail value.
     *
     * @param string $email
     * @return array
     */
    public function getUserByEmail($email, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        return $this->search(
            sprintf('(&(objectClass=user)(mail=%s))', AbstractFilter::escapeValue($email)),
            $baseDn,
            $scope,
            $attributes
        );
    }

    /**
     * Get a specific user's data from LDAP
     *
     * @param string $username
     * @param null|string $baseDn The DN to search from. Default is the baseDn option in the connection if not given
     * @param int $scope The scope to perform the search. Laminas_Ldap::SEARCH_SCOPE_ONE, Laminas_LDAP::SEARCH_SCOPE_BASE.
     *                      Default is Laminas_Ldap::SEARCH_SCOPE_SUB
     * @param array $attributes Restrict to specific AD attributes. An empty array will return all attributes
     * @return array
     * @throws Exception
     */
    public function getUserByUsername($username, $baseDn = null, $scope = Ldap::SEARCH_SCOPE_SUB, $attributes = [])
    {
        $options = $this->config()->get('options');
        $option = $options['accountCanonicalForm'] ?? null;

        // will translate the username to username@foo.bar, username or foo\user depending on the
        // $options['accountCanonicalForm']
        $username = $this->requireLdap()->getCanonicalAccountName($username, $option);
        switch ($option) {
            case Ldap::ACCTNAME_FORM_USERNAME: // traditional style usernames, e.g. alice
                $filter = sprintf('(&(objectClass=user)(samaccountname=%s))', AbstractFilter::escapeValue($username));
                break;
            case Ldap::ACCTNAME_FORM_BACKSLASH: // backslash style usernames, e.g. FOO\alice
                throw new Exception('Backslash style not supported in LDAPGateway::getUserByUsername()!');
                break;
            case Ldap::ACCTNAME_FORM_PRINCIPAL: // principal style usernames, e.g. alice@foo.com
                $filter = sprintf(
                    '(&(objectClass=user)(userprincipalname=%s))',
                    AbstractFilter::escapeValue($username)
                );
                break;
            case Ldap::ACCTNAME_FORM_DN: // distinguished name, e.g. CN=someone,DC=example,DC=co,DC=nz
                throw new Exception('DN style not supported in LDAPGateway::getUserByUsername()!');
                break;
            default: // default to principal style
                $filter = sprintf(
                    '(&(objectClass=user)(userprincipalname=%s))',
                    AbstractFilter::escapeValue($username)
                );
                break;
        }

        return $this->search($filter, $baseDn, $scope, $attributes);
    }

    /**
     * Get a canonical username from the record based on the connection settings.
     *
     * @param  array $data
     * @return string
     * @throws Exception
     */
    public function getCanonicalUsername($data)
    {
        $options = $this->config()->get('options');
        $option = $options['accountCanonicalForm'] ?? null;
        switch ($option) {
            case Ldap::ACCTNAME_FORM_USERNAME: // traditional style usernames, e.g. alice
                if (empty($data['samaccountname'])) {
                    throw new Exception('Could not extract canonical username: samaccountname field missing');
                }

                return $data['samaccountname'];
            case Ldap::ACCTNAME_FORM_BACKSLASH: // backslash style usernames, e.g. FOO\alice
                throw new Exception('Backslash style not supported in LDAPGateway::getUsernameByEmail()!');
            case Ldap::ACCTNAME_FORM_PRINCIPAL: // principal style usernames, e.g. alice@foo.com
                if (empty($data['userprincipalname'])) {
                    throw new Exception('Could not extract canonical username: userprincipalname field missing');
                }

                return $data['userprincipalname'];
            default: // default to principal style
                if (empty($data['userprincipalname'])) {
                    throw new Exception('Could not extract canonical username: userprincipalname field missing');
                }

                return $data['userprincipalname'];
        }
    }

    /**
     * Changes user password via LDAP.
     *
     * Change password is different to administrative password reset in that it will respect the password
     * history policy. This is achieved by sending a remove followed by an add in one batch (which is different
     * to an ordinary attribute modification operation).
     *
     * @param string $dn Location to update the entry at.
     * @param string $password New password to set.
     * @param string $oldPassword Old password is needed to trigger a password change.
     * @throws LdapException
     * @throws Exception
     */
    public function changePassword($dn, $password, $oldPassword)
    {
        $this->requireEncryptedConnection('change a password');

        if (!function_exists('ldap_modify_batch')) {
            // Password change is unsupported - missing PHP API method.
            $this->resetPassword($dn, $password);
            return;
        }

        $modifications = [
            [
                'attrib'  => 'unicodePwd',
                'modtype' => LDAP_MODIFY_BATCH_REMOVE,
                'values'  => [iconv('UTF-8', 'UTF-16LE', sprintf('"%s"', $oldPassword))],
            ],
            [
                'attrib'  => 'unicodePwd',
                'modtype' => LDAP_MODIFY_BATCH_ADD,
                'values'  => [iconv('UTF-8', 'UTF-16LE', sprintf('"%s"', $password))],
            ],
        ];
        // Batch attribute operations are not supported by Laminas_Ldap, use raw resource.
        $ldapConn = $this->requireLdap()->getResource();
        ErrorHandler::start(E_WARNING);
        $succeeded = ldap_modify_batch($ldapConn, $dn ?? '', $modifications ?? []);
        ErrorHandler::stop();
        if (!$succeeded) {
            throw new Exception($this->getLastPasswordError());
        }
    }

    /**
     * Administrative password reset.
     *
     * This is different to password change - it does not require old password, but also
     * it does not respect password history policy setting.
     *
     * @param string $dn Location to update the entry at.
     * @param string $password New password to set.
     * @throws LdapException
     * @throws Exception
     */
    public function resetPassword($dn, $password)
    {
        $this->requireEncryptedConnection('reset a password');

        try {
            $this->update(
                $dn,
                ['unicodePwd' => iconv('UTF-8', 'UTF-16LE', sprintf('"%s"', $password))]
            );
        } catch (LdapException) {
            throw new Exception($this->getLastPasswordError());
        }
    }

    /**
     * Updates an LDAP object.
     *
     * For this work you might need that LDAP connection is bind:ed with a user with
     * enough permissions to change attributes and that the LDAP connection is using
     * SSL/TLS. It depends on the server setup.
     *
     * If there are some errors, the underlying LDAP library should throw an Exception
     *
     * @param string $dn Location to update the entry at.
     * @param array $attributes An associative array of attributes.
     * @throws LdapException
     */
    public function update($dn, array $attributes)
    {
        $this->requireLdap()->update($dn, $attributes);
    }

    /**
     * Deletes an LDAP object.
     *
     * @param string $dn Location of object to delete
     * @param bool $recursively Recursively delete nested objects?
     * @throws LdapException
     */
    public function delete($dn, $recursively = false)
    {
        $this->requireLdap()->delete($dn, $recursively);
    }

    /**
     * Move an LDAP object from it's original DN location to another.
     * This effectively copies the object then deletes the original one.
     *
     * @param string $fromDn
     * @param string $toDn
     * @param bool $recursively Recursively move nested objects?
     */
    public function move($fromDn, $toDn, $recursively = false)
    {
        $this->requireLdap()->move($fromDn, $toDn, $recursively);
    }

    /**
     * Add an LDAP object.
     *
     * For this work you might need that LDAP connection is bind:ed with a user with
     * enough permissions to change attributes and that the LDAP connection is using
     * SSL/TLS. It depends on the server setup.
     *
     * @param string $dn Location to add the entry at.
     * @param array $attributes An associative array of attributes.
     * @throws LdapException
     */
    public function add($dn, array $attributes)
    {
        $this->requireLdap()->add($dn, $attributes);
    }

    /**
     * @return string
     */
    private function getLastPasswordError()
    {
        $defaultError = _t(
            'SilverStripe\\LDAP\\Authenticators\\LDAPAuthenticator.CANTCHANGEPASSWORD',
            "We couldn't change your password, please contact an administrator."
        );
        $error = '';
        ldap_get_option($this->ldap->getResource(), LDAP_OPT_ERROR_STRING, $error);

        if (!$error) {
            return $defaultError;
        }

        // Try to parse the exception to get the error message to display to user, eg:
        // 0000052D: Constraint violation - check_password_restrictions: the password does not meet the complexity criteria!)
        // 0000052D: Constraint violation - check_password_restrictions: the password was already used (in history)!)

        // We are only interested in the explanatory message after the last colon.
        $message = preg_replace('/.*:/', '', $error ?? '');
        if ($error) {
            return ucfirst(trim($message ?? ''));
        }

        return $defaultError;
    }
}
