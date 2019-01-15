# Troubleshooting

This guide contains a list of solutions to problems we have encountered in practice when integrating this module. This is not an exhaustive list, but it may provide assistance in case of some common issues.

## Table of contents

- [Unexpected users when synchronising LDAP](#unexpected-users-when-synchronising-ldap)
- [No users showing up when synchronising LDAP](#no-users-showing-up-when-synchronising-ldap)
- [AD fields are not synchronised into SilverStripe](#ad-fields-are-not-synchronised-into-silverstripe)
- [Problem finding names for field mappings](#problem-finding-names-for-field-mappings)
- [Stale AD groups in the CMS](#stale-ad-groups-in-the-cms)
- [1000 object limit in AD](#1000-object-limit-in-ad)

## Unexpected users when synchronising LDAP

This module will synchronise fields as specified by the LDAP search query. If unexpected users pop the reason might be an LDAP search base that either wrong or not specific enough.

Note that the comma separated terms in the query base are conjunctive - adding another term will narrow it down. Make sure you specify all "OU", "CN" and "DC" terms when searching.

See "Configure LDAP search query" and "LDAP debugging" in the [developer guide](developer.md) for more details.

## No users showing up when synchronising LDAP

First, check the advice in "Unexpected users when synchronising LDAP" above.

If your queries seem correct, one additional reason why no users might be returned is if you use a Security Group in your search base. Search locations should only be tree nodes (e.g. containers, organisational units, domains) within your Active Directory.

See "Configure LDAP search query" section in the [developer guide](developer.md) for more details.

## AD fields are not synchronised into SilverStripe

By default only a few fields are synchronised from AD into the default SilverStripe Member fields:

```php
// AD => SilverStripe
'givenname' => 'FirstName',
'sn' => 'Surname',
'mail' => 'Email'
```

As a developer, to synchronise further fields, you need to provide an explicit mapping configuration via `LDAPMemberExtension::$ldap_field_mappings`.

See "Map AD attributes to Member fields" in [developer guide](developer.md) for more details.

## Problem finding names for field mappings

The human-friendly names shown to the AD administrator don’t necessarily match what LDAP (and SilverStripe) use.

You can work out LDAP attribute names by enabling "Advanced features" in your AD browser, and looking at the "Attribute Editor" tab in user's properties.

Alternatively, consult a [cheatsheet](http://www.kouti.com/tables/userattributes.htm).

## Stale AD groups in the CMS

The list of groups here is cached, with a default lifetime of 8 hours. You can clear and regenerate the cache by adding `?flush=1` in the URL.

To change the cache lifetime, for example to make sure caches only last for an hour, a developer can set
this in your `mysite/_config.php`:

	\SilverStripe\Core\Cache::set_lifetime('ldap', 3600);

## 1000 object limit in AD

Active Directory has a default maximum LDAP page size limit of 1000. This means that if your search returns more than 1,000 results, only the first 1,000 will be returned.

This module gets around that when retrieving users and groups by implementing an LDAP paging iterator that requests information from LDAP in batches, continuing until all objects are returned.

However, this is only currently done within `LDAPService` for the `getUsers` and `getGroups` calls due to limited support for different LDAP scopes within the iterator. If you have other searches that require the use of the iterator, you can implement them as follows.

**Note:** The `searchWithIterator()` method does not return an `Iterator` object like you might expect, but instead it returns an array of every single entry returned by LDAP. This means that it is **not** suitable for large object sets where the amount of data returned may expand to be greater than the PHP max memory limit. 

### Step 1: Define your own LDAPGateway sub-class:
```php
<?php
use SilverStripe\LDAP\Model\LDAPGateway;

class MyBetterGateway extends LDAPGateway
{
    public function getAllEntries()
    {
        $baseDn = 'DC=corp,DC=myorg,DC=example,DC=com';
        $attributes = []; // Empty array means 'return all attributes' - note: this is very bad for performance
        return $this->searchWithIterator('(objectclass=*)', $baseDn, $attributes);
    }
}
```

### Step 2: Register your custom LDAPGateway to be used by LDAPService:
```yml
SilverStripe\Core\Injector\Injector:
    SilverStripe\LDAP\Model\LDAPGateway:
        class: App\LDAP\Gateways\MyBetterGateway
```

### Step 3: Call your new method:
```php
<?php
use SilverStripe\Core\Injector\Injector;
use SilverStripe\LDAP\Services\LDAPService;

$service = Injector::inst()->get(LDAPService::class);
$allEntries = $service->getGateway()->getAllEntries();

// Iterate over the returned results
foreach ($allEntries as $entry) {
    printf("%s\n", $entry['objectguid']);
}
```