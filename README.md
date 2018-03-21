# SilverStripe LDAP module

[![Build Status](https://travis-ci.org/silverstripe/silverstripe-ldap.svg)](https://travis-ci.org/silverstripe/silverstripe-ldap)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/silverstripe/silverstripe-ldap/badges/quality-score.png)](https://scrutinizer-ci.com/g/silverstripe/silverstripe-ldap/)
[![codecov](https://codecov.io/gh/silverstripe/silverstripe-ldap/branch/master/graph/badge.svg)](https://codecov.io/gh/silverstripe/silverstripe-ldap)

## Introduction

This SilverStripe module provides integration with LDAP (Lightweight Directory Access Protocol) servers. It comes with two major components:

* Synchronisation of Active Directory users and group memberships via LDAP(S)
* Active Directory authentication via LDAP binding

These components may be used in any combination, also alongside the default SilverStripe authentication scheme.

## Installation

Install using Composer:

```
composer require silverstripe/ldap ^1.0
```

## Requirements

 * PHP 5.6+ with extensions: ldap, openssl, dom, and mcrypt
 * SilverStripe 4.0+
 * An Active Directory server:
   * Active Directory on Windows Server 2008 R2 or greater (AD)
   * OpenLDAP
   * Samba
 * HTTPS endpoint on SilverStripe site
 * SSL/StartTLS encrypted LDAP endpoint on Active Directory

This module has been tested using Samba 4. It has also been tested in previous major releases against:

 * Windows Server 2008 R2 with ADFS 2.0
 * Windows Server 2012 R2 with ADFS 3.0

This module has not been tested on OpenLDAP.

**Note:** For SilverStripe 3, please see the [silverstripe-activedirectory module](https://github.com/silverstripe/silverstripe-activedirectory).

## Overview

This module will provide an LDAP authenticator for SilverStripe, which will authenticate via LDAPS against members in your AD server. The module comes with tasks to synchronise data between SilverStripe and AD, which can be run on a cron.

To synchronise further personal details, LDAP synchronisation feature can be used, also included in this module. This allows arbitrary fields to be synchronised - including binary fields such as photos. If relevant mappings have been configured in the CMS the module will also automatically maintain SilverStripe group memberships, which opens the way for an AD-centric authorisation.

**Note:** If you are looking for SSO with SAML, please see the [silverstripe-saml module](https://github.com/silverstripe/silverstripe-saml).

## Security

With appropriate configuration, this module provides a secure means of authentication and authorisation.

AD user synchronisation and authentication is hidden behind the backend (server to server communication), but must still use encrypted LDAP communication to prevent eavesdropping (either StartTLS or SSL - this is configurable). If the webserver and the AD server are hosted in different locations, a VPN could also be used to further encapsulate the traffic going over the public internet.

Note that the LDAP protocol does not communicate over HTTP. If this is what you're looking for, you may be interested in SAML instead.

## In-depth guides

* [Developer guide](docs/en/developer.md) - configure your SilverStripe site
* [CMS usage guide](docs/en/usage.md) - manage LDAP group mappings
* [Troubleshooting](docs/en/troubleshooting.md) - common problems

## Changelog

Please see the [GitHub releases](https://github.com/silverstripe/silverstripe-ldap/releases) for changes.
