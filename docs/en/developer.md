# Developer guide

This guide will step you through configuring your SilverStripe project to use an LDAP authentication backend. It will also show you a typical way to synchronise user details and group memberships from LDAP.

This guide assumes that you already have a running Active Directory server which can be communicated with using the LDAP protocol.

## Table of contents

- [Install the module](#install-the-module)
- [Configure SilverStripe Authenticators](#configure-silverstripe-authenticators)
  - [Bypass LDAP login form](#bypass-ldap-login-form)
- [Configure LDAP synchronisation](#configure-ldap-synchronisation)
  - [Connect with LDAP](#connect-with-ldap)
  - [Configure LDAP search query](#configure-ldap-search-query)
  - [Verify LDAP connectivity](#verify-ldap-connectivity)
  - [Put imported Member into a default group](#put-imported-member-into-a-default-group)
  - [Map AD attributes to Member fields](#map-ad-attributes-to-member-fields)
    - [Example](#example)
  - [Syncing AD users on a schedule](#syncing-ad-users-on-a-schedule)
  - [Syncing AD groups and users on a schedule](#syncing-ad-groups-and-users-on-a-schedule)
  - [Migrating existing users](#migrating-existing-users)
- [Debugging](#debugging)
  - [Debugging LDAP from SilverStripe](#debugging-ldap-from-silverstripe)
  - [Debugging LDAP directly](#debugging-ldap-directly)
- [Advanced features](#advanced-features)
  - [Allowing users to update their AD password](#allowing-users-to-update-their-ad-password)
  - [Writing LDAP data from SilverStripe](#writing-ldap-data-from-silverstripe)
- [Resources](#resources)

## Install the module

First step is to add this module into your SilverStripe project. You can use Composer for this:

```
composer require silverstripe/ldap
```

Commit the changes to composer.json and composer.lock.

## YAML configuration

Now we need to make the *silverstripe-ldap* module aware of where the certificates can be found.

The following is an example of the kinds of things you can configure in `mysite/_config/ldap.yml`:

```yaml
---
Name: myldapsettings
---
# LDAP Connection settings (see more further down)
SilverStripe\LDAP\Model\LDAPGateway:
  options:
    # The account's domain name
    accountDomainName: your.adserver.domain.com
    # The base DN to run AD queries against in your database
    baseDn: OU=Your Company,DC=your,DC=adserver,DC=domain,DC=com
    # Other connection options
    networkTimeout: 10
    useSsl: 'TRUE'
    accountCanonicalForm: 4

# Search locations for members and groups (if relevant)
SilverStripe\LDAP\Services\LDAPService:
  # Specify an array of DNs to look for users in
  users_search_locations:
    - OU=My Users,OU=Your Company,DC=your,DC=adserver,DC=domain,DC=com
    - OU=My Private User Group,OU=Your Company,DC=your,DC=adserver,DC=domain,DC=com

  # Specify an array of DNs to look for groups in
  groups_search_locations:
    - OU=Clients,OU=Your Company,DC=your,DC=adserver,DC=domain,DC=com
    - OU=Staff,OU=Your Company,DC=your,DC=adserver,DC=domain,DC=com

# Map for attributes in LDAP to SilverStripe (see further in guide)
SilverStripe\Security\Member:
  ldap_field_mappings:
    samaccountname: Username
    my_other_ldap_property: MyLdapProperty
```

Ensure you flush after changing YAML configuration.

## Configure SilverStripe Authenticators

To be able to use the LDAP authenticator you will need to configure it in, e.g. in `mysite/_config/ldap.yml`.

### Show the LDAP Login button on login form

```yaml
SilverStripe\Core\Injector\Injector:
    SilverStripe\Security\Security:
      properties:
          Authenticators:
            default: %$SilverStripe\LDAP\Authenticators\LDAPAuthenticator
```

To prevent locking yourself out, before you remove the "MemberAuthenticator" make sure you map at least one LDAP group to the SilverStripe `Administrator` Security Group. Consult [CMS usage docs](usage.md) for how to do it.

You may also wish to add this configuration to \_config.php. This can be useful for including environment variables, for example when configuring your connection credentials. More on this further in this document.

```php
<?php

use SilverStripe\Core\Config\Config;
use SilverStripe\LDAP\Model\LDAPGateway;

if (getenv('LDAP_HOSTNAME') && getenv('LDAP_USERNAME') && getenv('LDAP_PASSWORD')) {
    Config::modify()->merge(
        LDAPGateway::class,
        'options',
        [
            'host'     => getenv('LDAP_HOSTNAME'),
            'username' => getenv('LDAP_USERNAME'),
            'password' => getenv('LDAP_PASSWORD'),
        ]
    );
}
```

### Bypass auto login

If you register the LDAP authenticator as the default authenticator, it will no longer allow you to login with unsynchronised users (e.g. the default "admin" user for local development).

Should you need to access the login form without the LDAP authenticator, go to:

```
/Security/login?showloginform=1
```

Note that if you have unregistered the `MemberAuthenticator`, and you wish to use that method during `showloginform=1`, you
will need to set a cookie so it can be used temporarily.

To allow this to work, you will likely need to add something like the following to your mysite/\_config.php:

```php
use SilverStripe\LDAP\Authenticators\LDAPAuthenticator;
use SilverStripe\Control\Cookie;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Authenticator;
use SilverStripe\Security\Security;

if (isset($_GET['showloginform'])) {
    Cookie::set('showloginform', (bool)$_GET['showloginform'], 1);
}

if (!Cookie::get('showloginform')) {
    Config::modify()->merge(Authenticator::class, 'authenticators', [LDAPAuthenticator::class]);

    Config::modify()->merge(Injector::class, Security::class, [
        'properties' => [
            'Authenticators' => [
                'default' => '%$' . LDAPAuthenticator::class,
            ]
        ]
    ]);
}
```

If you do this, either clear your cookie or set the query string param back to 0 to return to using the LDAP login form.

## Configure LDAP synchronisation

These are the reasons for configuring LDAP synchronisation:

* It allows you to authorise users based on their AD groups. *silverstripe-ldap* is able to automatically maintain Group memberships for its managed users based on the AD "memberOf" attribute.
* You can pull in additional personal details about your users.
* The data is only synchronised upon modification.

### Connect with LDAP

Example configuration for `mysite/_config/ldap.yml`:

```yaml
SilverStripe\LDAP\Model\LDAPGateway:
  options:
    'host': 'ad.mydomain.local'
    'username': 'myusername'
    'password': 'mypassword'
    'accountDomainName': 'mydomain.local'
    'baseDn': 'DC=mydomain,DC=local'
    'networkTimeout': 10
    'useSsl': 'TRUE'
```

The `baseDn` option defines the initial scope of the directory where the connector can perform queries. This should be set to the root base DN, e.g. DC=mydomain,DC=local

The `useSsl` option enables encrypted transport for LDAP communication. This should be mandatory for production systems to prevent eavesdropping. A certificate trusted by the webserver must be installed on the AD server. StartTLS can alternatively be used (`useStartTls` option).

For more information about available LDAP options, please [see the Laminas\Ldap documentation](https://docs.laminas.dev/laminas-ldap/) and [API overview documentation](https://docs.laminas.dev/laminas-ldap/api/).

### Configure LDAP search query

You can then set specific locations to search your directory. Note that these locations must be within the `baseDn` you have specified above:

```yaml
SilverStripe\LDAP\Services\LDAPService:
  users_search_locations:
    - 'CN=Users,DC=mydomain,DC=local'
    - 'CN=Others,DC=mydomain,DC=local'
  groups_search_locations:
    - 'CN=Somewhere,DC=mydomain,DC=local'
```

Note that these search locations should only be tree nodes (e.g. containers, organisational units, domains) within your Active Directory.
Specifying groups will not work. [More information](http://stackoverflow.com/questions/9945518/can-ldap-matching-rule-in-chain-return-subtree-search-results-with-attributes) is available on the distinction between a node and a group.

If you are experiencing problems with getting the right nodes, run the search query directly via LDAP and see what is returned. For that you can either use Windows' `ldp.exe` tool, or Unix/Linux equivalent `ldapsearch`.

See "LDAP debugging" section below for more information.

### Verify LDAP connectivity

You can visit a controller called `/LDAPDebug` to check that the connection is working. This will output a page listing the connection options used, as well as all AD groups that can be found.

### Put imported Member into a default group

You can configure the module so everyone imported goes into a default group. The group must already exist before
you can use this setting. The value of this setting should be the "Code" field from the Group.

```yaml
SilverStripe\LDAP\Services\LDAPService:
  default_group: "content-authors"
```

### Map AD attributes to Member fields

`SilverStripe\Security\Member.ldap_field_mappings` defines the AD attributes that should be mapped to `Member` fields.
By default, it will map the AD first name, surname and email to the built-in FirstName, Surname,
and Email Member fields.

You can map AD attributes to custom fields by specifying configuration in your `mysite/_config/ldap.yml`. The three
different AD fields types that can be mapped are: textual, array and thumbnail photo.

```yaml
SilverStripe\Security\Member:
  ldap_field_mappings:
    'description': 'Description'
    'othertelephone': 'OtherTelephone'
    'thumbnailphoto': 'Photo'
```

A couple of things to note:

 * The AD attributes names must be in lowercase
 * You must provide a receiver on the `Member` on your own (a field, or a setter - see example below).

There is a special case for the `thumbnailphoto` attribute which can contain a photo of a user in AD. This comes
through from AD in binary format. If you have a `has_one` relation to an `Image` on the Member, you can map that field
to this attribute, and it will put the file into place and assign the image to that field.

To use other fields from AD containing an image, you can add them like this:

```yaml
SilverStripe\LDAP\Services\LDAPService:
  photo_attributes:
    - jpegphoto
```

Afterwards they will be automatically processed like `thumbnailphoto`.

By default, thumbnails/images are saved into `assets/Uploads`, but you can specify the location
(relative to /assets) by setting the following configuration:

```yaml
SilverStripe\Security\Member:
  ldap_thumbnail_path: 'some-path'
```

The image files will be saved in this format: `thumbnailphoto-{sAMAccountName}.jpg`.

#### Example

Here is an extension that will handle different types of field mappings defined in the `mysite/_config/ldap.yml`
mentioned above. You will still need to apply that extension to `SilverStripe\Security\Member` to get it to work.

```php
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;

class MyMemberExtension extends Extension
{
    private static $db = [
        // 'description' is a regular textual field and is written automatically.
        'Description' => 'Varchar(50)',
        // ...
    ];

    private static $has_one = [
        // 'thumbnailphoto' writes to has_one Image automatically.
        'Photo' => Image::class,
    ];

    /**
     * 'othertelephone' is an array, needs manual processing.
     */
    public function setOtherTelephone($array)
    {
        $serialised = implode(',', $array);
        // ...
    }
}
```

### Allowing sync failures during login

By default, every time a user logs in that is linked to an LDAP account, their account is synced with LDAP and any changed information is pulled into SilverStripe. In some situations, your web servers may not have access to the LDAP server due to security restrictions, VPN configuration or similar. If this happens, the default behaviour is for the login to fail and the user will see a Server Error style message.

To prevent this, you can set a configuration flag to prevent an LDAP sync failure during login from breaking the login flow, however this is not enabled by default as it has a security impact. Please review the notes on `LDAPMemberExtension::$allow_update_failure_during_login` to understand the ramifications before enabling this.

You can enable it as follows:

```yaml
SilverStripe\Security\Member:
  allow_update_failure_during_login: true
```

### Syncing AD users on a schedule

You can schedule a job to run, then have it re-schedule itself so it runs again in the future, but some configuration needs to be set to have it work.

If you want, you can set the behaviour of the sync to be destructive, which means any previously imported users who no
longer exist in the directory get deleted:

```yaml
SilverStripe\LDAP\Tasks\LDAPMemberSyncTask:
  destructive: true
```

To configure when the job should re-run itself, set the `SilverStripe\LDAP\Jobs\LDAPMemberSyncJob.regenerate_time` configuration.
In this example, this configures the job to run every 8 hours:

```yaml
SilverStripe\LDAP\Jobs\LDAPMemberSyncJob:
  regenerate_time: 28800
```

Once the job runs, it will enqueue itself again, so it's effectively run on a schedule. Keep in mind that you'll need to have `queuedjobs` setup on a cron so that it can automatically run those queued jobs.
See the [module docs](https://github.com/symbiote/silverstripe-queuedjobs) on how to configure that.

If you don't want to run a queued job, you can set a cronjob yourself by calling:

```sh
vendor/bin/sake tasks:LDAPMemberSyncTask
```

### Syncing AD groups and users on a schedule

Similarly to syncing AD users, you can also schedule a full group and user sync. Group mappings will be added automatically, resulting in Members being added to relevant Groups.

As with the user sync, you can separately set the group sync to be destructive:

```yaml
SilverStripe\LDAP\Tasks\LDAPGroupSyncTask:
  destructive: true
```

And here is how you make the job reschedule itself after completion:

```yaml
SilverStripe\LDAP\Jobs\LDAPAllSyncJob:
  regenerate_time: 28800
```

If you don't want to run a queued job, you can set a cronjob yourself by calling the two sync tasks (order is important, otherwise your group memberships might not get updated):

```sh
vendor/bin/sake tasks:LDAPGroupSyncTask
vendor/bin/sake tasks:LDAPMemberSyncTask
```

### Migrating existing users

If you have existing Member records on your site that have matching email addresses to users in the directory,
you can migrate those by running the task `LDAPMigrateExistingMembersTask`. For example, visting
`http://mysite.com/dev/tasks/LDAPMigrateExistingMembersTask` will run the migration.

This essentially just updates those existing records with the matching directory user's `GUID` so they will be synced from now on.

## Debugging

There are certain parts of his module that have debugging messages logged. You can configure logging to receive these via email, for example. For more information on this topic see [Logging and Error Handling](https://docs.silverstripe.org/en/4/developer_guides/debugging/error_handling/) in the developer documentation.

### Debugging LDAP from SilverStripe

For debugging what information SilverStripe is getting from LDAP, you can visit the `<site-root>/LDAPDebug` from your browser. Assuming you are an ADMIN, this will give you a breakdown of visible information.

To see debug information on the sync tasks, run them directly from your browser. The tasks are at `<site-root>/dev/tasks/LDAPGroupSyncTask` and `dev/tasks/LDAPMemberSyncTask`.

If you've configured a system logger, you should also receive various system log entries as well.

### Debugging LDAP directly

LDAP is a plain-text protocol for interacting with user directories. You can debug LDAP responses by querying directly. For that you can use Windows' `ldp.exe` tool, or Unix/Linux equivalent `ldapsearch`.

Here is an example of `ldapsearch` usage. You will need to bind to the directory using an administrator account (specified via `-D`). The base of your query is specified via `-b`, and the search query follows.

```bash
ldapsearch \
    -W \
    -H ldaps://<ldap-url>:<ldap-port> \
    -D "CN=<administrative-user>,DC=yourldap,DC=co,DC=nz" \
    -b "DC=yourldap,DC=co,DC=nz" \
    "(name=*)"
```
## Advanced features

### Allowing email login on LDAP login form instead of username

`LDAPAuthenticator` expects a username to log in, due to authentication with LDAP traditionally
using username instead of email. You can additionally allow people to authenticate with their email.

Example configuration in `mysite/_config/ldap.yml`:

```yaml
SilverStripe\LDAP\Authenticators\LDAPAuthenticator:
  allow_email_login: 'yes'
```

Note that your LDAP users logging in must have the `mail` attribute set, otherwise this will not work.

### Falling back authentication on LDAP login form

You can allow users who have not been migrated to LDAP to authenticate via the default `SilverStripe\Security\MemberAuthenticator`.
This is different to registering multiple authenticators, in that the fallback works on the one login form.

Example configuration in `mysite/_config/ldap.yml`:

```yaml
SilverStripe\LDAP\Authenticators\LDAPAuthenticator:
  fallback_authenticator: 'yes'
```

The fallback authenticator will be used in the following conditions:

 * User logs in using their email address, but does not have a username
 * The user logs in with a password that does not match what is set in LDAP

### Extending the member and group sync tasks with custom functionality

Both `LDAPMemberSyncTask` and `LDAPGroupSyncTask` provide extension points (`onAfterLDAPMemberSyncTask` and
`onAfterLDAPGroupSyncTask` respectively) after all members/groups have been synced and before the task exits. This is a
perfect time to set values that are dependent on a full sync - for example linking a user to their manager based on DNs.
For example:

```yaml
SilverStripe\LDAP\Tasks\LDAPMemberSyncTask:
  extensions:
    - App\Extensions\LDAPMemberSyncExtension
SilverStripe\Security\Member:
  ldap_field_mappings:
    manager: ManagerDN
    dn: DN
```

```php
<?php
namespace App\Extensions;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Member;

class LDAPMemberSyncExtension extends Extension
{
    /**
     * Assuming the `DN` and `ManagerDN` values are set by LDAP, this code will link a member with their manager and
     * store the link in the `Manager` has_one.
     */
    protected function onAfterLDAPMemberSyncTask()
    {
        $members = Member::get()->where('"GUID" IS NOT NULL');

        foreach ($members as $member) {
            if ($member->ManagerDN) {
                $manager = Member::get()->filter('DN', $member->ManagerDN)->first();
                if ($manager) {
                    $member->ManagerID = $manager->ID;
                    $member->write();
                }
            }
        }
    }
}
```

### Allowing users to update their AD password

If the LDAP bind user that is configured under 'Connect with LDAP' section has permission to write attributes to the AD, it's possible to allow users to update their password via the internet site.

Word of caution, you will potentially open a security hole by exposing an AD user that can write passwords. Normally you would only bind to LDAP via a read-only user. Windows AD stores passwords in a hashed format that is very hard to brute-force. A user with write access can take over an account, create objects, delete and have access to all systems that authenticate with AD.

If you still need this feature, we recommend that you use a combination of encryption, scheduled password rotation and limit permission for the bind user to minimum required permissions.

This feature only works if you have the `LDAPAuthenticator` enabled (see "Configure SilverStripe Authenticators" section).

This feature has only been tested on Samba 4, but has previously been tested on Microsoft AD compatible servers as well.

Example configuration in `mysite/_config/ldap.yml`:

```yaml
SilverStripe\LDAP\Services\LDAPService:
  allow_password_change: true
```

This will allow users to change their AD password via the regular CMS "forgot password" forms, etc.

### Allow SilverStripe attributes to be reset (removed) by AD

By default if attributes are present, and then missing in subsequent requests, they are ignored (non-destructive) by
this module. This can cause attributes to persist when they've been deliberately removed (attribute is no longer present)
in the LDAP source data.

If you wish a full two way sync to occur, then set the attribute on `LDAPService` for `reset_missing_attributes` to
enable a full sync.

*Note*: This will mean syncs are destructive, and data or attributes will be reset if missing from the master LDAP source
data.

```yaml
SilverStripe\LDAP\Services\LDAPService:
  reset_missing_attributes: true
```

### Writing LDAP data from SilverStripe

A feature is available that allows data to be written back to LDAP based on the state of `SilverStripe\Security\Member` object fields.
Additionally, you can also create new users in LDAP from your local records.

Before this can be used, the credentials connecting to LDAP need to have write permissions so LDAP attributes can
be written to.

To turn on the feature, here is some example configuration in `mysite/_config/ldap.yml`:

```yaml
SilverStripe\Security\Member:
  update_ldap_from_local: true
  create_users_in_ldap: true

SilverStripe\LDAP\Services\LDAPService:
  new_users_dn: CN=Users,DC=mydomain,DC=com
```

The `new_users_dn` is the DN (Distinguished Name) of the location in the LDAP structure where new users will be created.

Now when you create a new user using the Security section in `/admin`, the user will be created in LDAP. Take note
that the "Username" field must be filled in, otherwise it will not be created, due to LDAP users requiring a username.

You can also programmatically create a user. For example:

```php
$member = new \SilverStripe\Security\Member();
$member->FirstName = 'Joe';
$member->Username = 'jbloggs';
$member->write();
```

If you enable `update_ldap_from_local` saving a user in the Security section of the CMS or calling `write()` on
a Member object will push up the mapped fields to LDAP, assuming that Member record has a `GUID` field.

See "Map AD attributes to Member fields" section above for more information on mapping fields.
