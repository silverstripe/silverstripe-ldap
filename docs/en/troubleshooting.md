# Troubleshooting

This guide contains a list of solutions to problems we have encountered in practice when integrating this module. This is not an exhaustive list, but it may provide assistance in case of some common issues.

## Table of contents

- [Unexpected users when synchronising LDAP](#unexpected-users-when-synchronising-ldap)
- [No users showing up when synchronising LDAP](#no-users-showing-up-when-synchronising-ldap)
- [AD fields are not synchronised into SilverStripe](#ad-fields-are-not-synchronised-into-silverstripe)
- [Problem finding names for field mappings](#problem-finding-names-for-field-mappings)
- [Stale AD groups in the CMS](#stale-ad-groups-in-the-cms)
- [1000 users limit in AD](#1000-users-limit-in-ad)

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
this the your `mysite/_config.php`:

	\SilverStripe\Core\Cache::set_lifetime('ldap', 3600);

## 1000 users limit in AD

Active Directory has a default max LDAP page size limit of 1000. This means if you have over 1000 users some of them won't be imported.

Unfortunately due to a missing paging feature with the LDAP PHP extension, paging results is not currently possible. The workaround is to modify an LDAP policy `MaxPageSize` on your
Active Directory server using `ntdsutil.exe`:

	C:\Documents and Settings\username>ntdsutil.exe
	ntdsutil: ldap policies
	ldap policy: connections
	server connections: connect to server <yourservername>
	Binding to <yourservername> ...
	Connected to <yourservername> using credentials of locally logged on user.
	server connections: q
	ldap policy: show values

	Policy                          Current(New)

	MaxPoolThreads                  4
	MaxDatagramRecv                 1024
	MaxReceiveBuffer                10485760
	InitRecvTimeout                 120
	MaxConnections                  5000
	MaxConnIdleTime                 900
	MaxPageSize                     1000
	MaxQueryDuration                120
	MaxTempTableSize                10000
	MaxResultSetSize                262144
	MaxNotificationPerConn          5
	MaxValRange                     0

	ldap policy: set maxpagesize to 10000
	ldap policy: commit changes
	ldap policy: q
	ntdsutil: q
