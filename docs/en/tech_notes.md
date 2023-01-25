# Technical notes

## LDAP only sequence

LDAP-only sequence:

1. User requests a secured resource, and is redirected to `LDAPLoginForm`
1. User fills in the credentials
1. `LDAPAuthenticator::authenticate` is called
1. Authentication against LDAP is performed by SilverStripe's backend.
1. If `Member` record is not found, stub is created with some basic fields (i.e. GUID), but no group mapping.
1. A login hook is triggered at `LDAPMemberExtension::afterMemberLoggedIn`
1. LDAP synchronisation is performed by looking up the GUID. All `Member` fields are overwritten with the data obtained
from LDAP, and LDAP group mappings are added.
1. User is logged into SilverStripe as that member, considered authenticated and authorised (since the group mappings
are in place)

## Member record manipulation

`Member` records are manipulated from multiple locations in this module. Members are identified by GUIDs by both LDAP
and SAML components.

* `LDAPAuthenticator::authenticate`: creates stub `Member` after authorisation (if non-existent).
* `LDAPMemberExtension::afterMemberLoggedIn`: triggers LDAP synchronisation, rewriting all `Member` fields.
* `LDAPMemberSyncTask::run`: pulls all LDAP records and creates relevant `Members`.

## Password change synchronisation

Password changes in SilverStripe are tracked by the `LDAPMemberExtension::onBeforeUpdatePassword`, which will ask the `LDAPService` to set the password in LDAP when it changes in SilverStripe.
