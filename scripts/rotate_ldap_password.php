#!/usr/bin/env php
<?php
error_reporting(E_ALL | E_STRICT);

/**
 * Rotate a configured LDAP account's password in an _ss_environment.php file.
 * This changes the password of that account to a randomly generated one, then sets the
 * password back into the same file so it's effective immediately.
 *
 * Requirements:
 * Microsoft Active Directory or Samba directory.
 * PHP CLI with ldap, and iconv extensions.
 *
 * Installation:
 * Copy this script into /usr/local/bin/rotate_ldap_password
 *
 * Command synax:
 * rotate_ldap_password <ss_env_file> <c_hostname|hostname> <c_privileged_dn> <c_privileged_password> [<c_target_dn> <c_target_password>]
 *
 * The endpoint must be encrypted. This script binds with a privileged account and then writes a
 * new password, so it refuses to continue unless the connection is ldaps://, ldapi://, or a plain
 * ldap:// URL on which StartTLS could be negotiated. There is deliberately no override.
 *
 * Example:
 * Assuming you have this configuration in your /sites/.env file:
 * <code>
 * LDAP_HOSTNAME="ldaps://myldap.hostname:636"
 * PRIVILEGED_DN="CN=PrivilegedUser,DC=platform,DC=silverstripe,DC=com"
 * PRIVILEGED_PASSWORD="somesupersecretpassword"
 * TARGET_DN="CN=MyUser,DC=platform,DC=silverstripe,DC=com"
 * TARGET_PASSWORD="somesupersecretpassword"
 * </code>
 * The password can be rotated by running:
 * rotate_ldap_password /sites/_ss_environment.php LDAP_HOSTNAME PRIVILEGED_DN PRIVILEGED_PASSWORD TARGET_DN TARGET_PASSWORD
 *
 * LDAP endpoint hostname can also be overriden:
 * rotate_ldap_password /sites/_ss_environment.php ldaps://otherldap.org PRIVILEGED_DN PRIVILEGED_PASSWORD TARGET_DN TARGET_PASSWORD
 */

if (count($_SERVER['argv'])!=5 && count($_SERVER['argv'])!=7) {
    echo "Not enough arguments.\n\n";
    echo "Usage (`c_' suffix denotes constants, not actual value):\n";
    echo "	{$_SERVER['argv'][0]} <ss_env_file> <c_hostname|hostname> <c_privileged_dn> <c_privileged_password> [<c_target_dn> <c_target_password>]\n";
    exit(1);
}

$envFilePath = $_SERVER['argv'][1];
$hostname = $_SERVER['argv'][2];
$privilegedDNConst = $_SERVER['argv'][3];
$privilegedPasswordConst = $_SERVER['argv'][4];

if (empty($_SERVER['argv'][5])) {
    // No target user specified, assume the privileged user's password is being changed.
    $DNConst = $privilegedDNConst;
    $passwordConst = $privilegedPasswordConst;
} else {
    // Target specified for change.
    $DNConst = $_SERVER['argv'][5];
    $passwordConst = $_SERVER['argv'][6];
}

if (!file_exists($envFilePath)) {
    echo sprintf("%s does not exist\n", $envFilePath);
    exit(1);
}
if (!is_writable($envFilePath)) {
    echo sprintf("%s is not writable\n", $envFilePath);
    exit(1);
}

require_once($envFilePath);

$hostnameValue = defined($hostname) ? constant($hostname) : $hostname;
$conn = ldap_connect($hostnameValue);
if (!$conn) {
    echo sprintf("Could not create an LDAP connection for %s\n", $hostnameValue);
    exit(1);
}
ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

// Everything below this point puts secrets on the wire: the privileged bind credentials, and then
// the new password in unicodePwd. Refuse to do any of it over cleartext, and fail closed if the
// transport cannot be secured. Note ldaps:// on its own does not imply the server certificate was
// verified - that is governed by TLS_REQCERT in ldap.conf.
$scheme = strtolower((string) parse_url($hostnameValue, PHP_URL_SCHEME));
if ($scheme !== 'ldaps' && $scheme !== 'ldapi') {
    if (!@ldap_start_tls($conn)) {
        echo sprintf(
            "Refusing to continue: %s is not encrypted and StartTLS failed: %s (error code: %s).\n"
                . "Use an ldaps:// URL, or fix StartTLS on the directory. This script will not send\n"
                . "a privileged bind or a new password over an unencrypted connection.\n",
            $hostnameValue,
            ldap_error($conn),
            ldap_errno($conn)
        );
        exit(1);
    }
}

$bind = @ldap_bind($conn, constant($privilegedDNConst), constant($privilegedPasswordConst));
if (!$bind) {
    echo sprintf(
        "Could not bind to LDAP: %s (error code: %s)\n",
        ldap_error($conn),
        ldap_errno($conn)
    );
    exit(1);
}

// ldap_read searches the directory but uses LDAP_SCOPE_BASE, so we only get a single entry.
// We're just doing this to validate that the entry exists in the directory.
$query = ldap_read($conn, constant($DNConst), '(objectClass=user)', ['dn']);
if (!$query) {
    echo sprintf(
        "Could not read the LDAP entry %s: %s (error code: %s)\n",
        constant($DNConst),
        ldap_error($conn),
        ldap_errno($conn)
    );
    exit(1);
}

$results = ldap_get_entries($conn, $query);

if (empty($results[0]['dn'])) {
    echo sprintf(
        "There was a problem retrieving the details for %s\n",
        constant($DNConst)
    );
    exit(1);
}

// Generate a sufficiently complex password and set that as the password in LDAP.
// Note: This only supports Microsoft Active Directory and Samba based directories.
//
// With 24-character long password, this hardly ever loops.
for (;;) {
    $password = generatePassword(24);
    if (preg_match('/[A-Z]/', $password) &&
        preg_match('/[a-z]/', $password) &&
        preg_match('/[0-9]/', $password) &&
        preg_match('/[\!\@\#\$\%\&\*\?]/', $password)
    ) {
        break;
    }
}

$success = ldap_modify($conn, $results[0]['dn'], [
    'unicodePwd' => iconv('UTF-8', 'UTF-16LE', sprintf('"%s"', $password))
]);

if (!$success) {
    echo sprintf(
        "Could not modify the LDAP entry %s: %s (error code: %s)\n",
        constant($DNConst),
        ldap_error($conn),
        ldap_errno($conn)
    );
    exit(1);
}

ldap_close($conn);

// Replace the old password with the new one in the environment file.
$contents = file_get_contents($envFilePath);
$contents = str_replace(constant($passwordConst), $password, $contents);
file_put_contents($envFilePath, $contents);

echo sprintf(
    "LDAP password for %s has been reset. Old password has been replaced in %s\n",
    $results[0]['dn'],
    $envFilePath
);

exit(0);

function generatePassword($length = 24)
{
    // random_bytes() is the CSPRNG since PHP 7 and throws rather than degrading, so there is no
    // weaker fallback to guard against. The previous mcrypt_create_iv() branch was dead code -
    // mcrypt was removed in PHP 7.2 - and its error message advised installing it.
    try {
        $passwordData = random_bytes(64);
    } catch (Exception $e) {
        echo sprintf("Cannot generate a password - no secure randomness available: %s\n", $e->getMessage());
        exit(1);
    }

    // Convert the binary data into password-usable characters.
    $usable = str_replace('=', '', base64_encode($passwordData));

    // Re-map the base64 alphabet to include special password characters so we can satisfy all character classes.
    $withSpecial = strtr($usable, '/+abcABC', '!@#$%&*?');

    // Trim to requested size.
    $password = substr($withSpecial, 0, $length);

    return $password;
}
