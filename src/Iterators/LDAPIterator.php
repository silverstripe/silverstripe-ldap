<?php

namespace SilverStripe\LDAP\Iterators;

use Iterator;
use Laminas\Ldap\ErrorHandler;
use Laminas\Ldap\Exception\LdapException;
use Laminas\Ldap\Ldap;

/**
 * Class LDAPIterator
 * @package SilverStripe\LDAP\Iterators
 *
 * Original implementation provided by ThaDafinser: https://gist.github.com/ThaDafinser/1d081bed8e5e6505e97bedf5863a187c
 * See also: https://github.com/zendframework/zend-ldap/issues/41
 *
 * This uses the ldap_control_paged_result() and ldap_control_paged_result_response() functions to request multiple
 * pages of data from LDAP as needed, to ensure that the end result is everything that matches the requested filter,
 * regardless of the maximum page size set by the LDAP server.
 *
 * Note: The page size attribute provided to this class must be less than the LDAP server's page size, or objects may
 * still be missing from results.
 */
final class LDAPIterator implements Iterator
{
    private $ldap;
    private $filter;
    private $baseDn;
    private $returnAttributes;
    private $pageSize;
    private $resolveRangedAttributes;
    private $entries;
    private $current;
    /**
     * Required for paging
     *
     * @var unknown
     */
    private $currentResult;
    /**
     * Required for paging
     *
     * @var unknown
     */
    private $cookie = true;

    /**
     * @param Ldap $ldap The Laminas\Ldap\Ldap object that this iterator will use to retrieve results from
     * @param string $filter An LDAP search filter (e.g. "(&(objectClass=user)(!(objectClass=computer)))")
     * @param string|null $baseDn The Base DN to search from (or null to search from the connection root)
     * @param array|null $returnAttributes The attributes to request from the LDAP server, or null to request all
     * @param int $pageSize Number of results per page. This *must* be less than the LDAP server MaxPageSize setting
     * @param bool $resolveRangedAttributes Whether or not to return ranged attributes
     */
    public function __construct(Ldap $ldap, $filter = "", $baseDn = null, array $returnAttributes = null, $pageSize = 250, $resolveRangedAttributes = false)
    {
        $this->ldap = $ldap;
        $this->filter = $filter;
        $this->baseDn = $baseDn;
        $this->returnAttributes = $returnAttributes;
        $this->pageSize = $pageSize;
        $this->resolveRangedAttributes = $resolveRangedAttributes;
    }

    private function getLdap()
    {
        return $this->ldap;
    }

    private function getFilter()
    {
        return $this->filter;
    }

    private function getBaseDn()
    {
        return $this->baseDn;
    }

    private function getReturnAttributes()
    {
        return $this->returnAttributes;
    }

    private function getPageSize()
    {
        return $this->pageSize;
    }

    /**
     * @return bool
     */
    private function getResolveRangedAttributes()
    {
        return $this->resolveRangedAttributes;
    }

    private function fetchPagedResult()
    {
        if ($this->cookie === null || $this->cookie === '') {
            return false;
        }

        if ($this->cookie === true) {
            // First fetch!
            $this->cookie = '';
        }

        $ldap = $this->getLdap();
        $resource = $ldap->getResource();

        $baseDn = $this->getBaseDn();
        if (!$baseDn) {
            $baseDn = $ldap->getBaseDn();
        }

        ldap_control_paged_result($resource, $this->getPageSize(), true, $this->cookie);
        if ($this->getReturnAttributes() !== null) {
            $resultResource = ldap_search($resource, $baseDn ?? '', $this->getFilter() ?? '', $this->getReturnAttributes() ?? []);
        } else {
            $resultResource = ldap_search($resource, $baseDn ?? '', $this->getFilter() ?? '');
        }
        if (! is_resource($resultResource)) {
            /*
             * @TODO better exception msg
             */
            throw new \Exception('ldap_search returned something wrong...' . ldap_error($resource));
        }

        $entries = ldap_get_entries($resource, $resultResource);
        if ($entries === false) {
            throw new LdapException($ldap, 'Entries could not get fetched');
        }
        $entries = $this->getConvertedEntries($entries);

        ErrorHandler::start();
        $response = ldap_control_paged_result_response($resource, $resultResource, $this->cookie);
        ErrorHandler::stop();

        if ($response !== true) {
            throw new LdapException($ldap, 'Paged result was empty');
        }

        if ($this->entries === null) {
            $this->entries = [];
        }

        $this->entries = array_merge($this->entries, $entries);

        return true;
    }
    private function getConvertedEntries(array $entries)
    {
        $result = [];

        foreach ($entries as $key => $entry) {
            if ($key === 'count') {
                continue;
            }

            $result[$key] = $this->getConvertedEntry($entry);
        }

        return $result;
    }
    private function getConvertedEntry(array $entry)
    {
        $result = [];

        foreach ($entry as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            if ($key === 'count') {
                continue;
            }

            if (isset($value['count'])) {
                unset($value['count']);
            }

            $result[$key] = $value;
        }

        if ($this->getResolveRangedAttributes() === true) {
            $result = $this->resolveRangedAttributes($result);
        }

        return $result;
    }
    private function resolveRangedAttributes(array $row)
    {
        $result = [];
        foreach ($row as $key => $value) {
            $keyExploded = explode(';range=', $key ?? '');

            if (count($keyExploded ?? []) === 2) {
                $range = explode('-', $keyExploded[1] ?? '');
                $offsetAndLimit = (int) $range[1] + 1;

                $result[$keyExploded[0]] = array_merge($value, $this->getAttributeRecursive($row['dn'], $keyExploded[0], $offsetAndLimit, $offsetAndLimit));
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
    private function getAttributeRecursive($dn, $attrName, $offset, $maxPerRequest)
    {
        $attributeValue = [];

        $limit = $offset + $maxPerRequest - 1;
        $searchedAttribute = $attrName . ';range=' . $offset . '-' . $limit;

        $ldap = $this->getLdap();
        $entry = $ldap->getEntry($dn, [
            $searchedAttribute
        ], true);
        foreach ($entry as $key => $value) {
            // skip DN and other fields (if returned)
            if (stripos($key ?? '', $attrName ?? '') === false) {
                continue;
            }

            $attributeValue = $value;

            // range result (pagination)
            $keyExploded = explode(';range=', $key ?? '');

            $range = explode('-', $keyExploded[1] ?? '');
            $rangeEnd = (int) $range[1];

            if ($range[0] == $offset && $range[1] == $limit) {
                // more pages, there are more pages to fetch
                $attributeValue = array_merge($attributeValue, $this->getAttributeRecursive($dn, $attrName, $rangeEnd + 1, $maxPerRequest));
            }
        }

        return $attributeValue;
    }
    #[\ReturnTypeWillChange]
    public function current()
    {
        if (! is_array($this->current)) {
            $this->rewind();
        }
        if (! is_array($this->current)) {
            return;
        }

        return $this->current;
    }
    #[\ReturnTypeWillChange]
    public function key()
    {
        if (! is_array($this->current)) {
            $this->rewind();
        }
        if (! is_array($this->current)) {
            return;
        }

        return $this->current['dn'];
    }
    #[\ReturnTypeWillChange]
    public function next()
    {
        // initial
        if ($this->entries === null) {
            $this->fetchPagedResult();
        }

        next($this->entries);

        $this->current = current($this->entries ?? []);
    }
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        // initial
        if ($this->entries === null) {
            $this->fetchPagedResult();
        }

        reset($this->entries);
        $this->current = current($this->entries ?? []);
    }
    #[\ReturnTypeWillChange]
    public function valid()
    {
        if (is_array($this->current)) {
            return true;
        }

        return $this->fetchPagedResult();
    }
}
