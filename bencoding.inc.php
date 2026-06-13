<?php
/*
 * Neuropentracker
 * Summary: Encodes and decodes BitTorrent bencoded values.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Compatible with PHP 7.4 through PHP 8.3.
 */

function bdecode(string $str)
{
    $pos = 0;
    return bdecode_r($str, $pos);
}

function bdecode_r(string $str, int &$pos)
{
    $strlen = strlen($str);
    if ($pos < 0 || $pos >= $strlen) {
        return null;
    }

    if ($str[$pos] === 'i') {
        $pos++;
        $numlen = strspn($str, '-0123456789', $pos);
        $spos = $pos;
        $pos += $numlen;

        if ($numlen === 0 || $pos >= $strlen || $str[$pos] !== 'e') {
            return null;
        }

        $pos++;
        return (int) substr($str, $spos, $numlen);
    }

    if ($str[$pos] === 'd') {
        $pos++;
        $ret = array();

        while ($pos < $strlen) {
            if ($str[$pos] === 'e') {
                $pos++;
                return $ret;
            }

            $key = bdecode_r($str, $pos);
            if ($key === null) {
                return null;
            }

            $val = bdecode_r($str, $pos);
            if ($val === null) {
                return null;
            }

            if (!is_array($key)) {
                $ret[$key] = $val;
            }
        }

        return null;
    }

    if ($str[$pos] === 'l') {
        $pos++;
        $ret = array();

        while ($pos < $strlen) {
            if ($str[$pos] === 'e') {
                $pos++;
                return $ret;
            }

            $val = bdecode_r($str, $pos);
            if ($val === null) {
                return null;
            }

            $ret[] = $val;
        }

        return null;
    }

    $numlen = strspn($str, '0123456789', $pos);
    $spos = $pos;
    $pos += $numlen;

    if ($numlen === 0 || $pos >= $strlen || $str[$pos] !== ':') {
        return null;
    }

    $vallen = (int) substr($str, $spos, $numlen);
    $pos++;
    $val = substr($str, $pos, $vallen);

    if (strlen($val) !== $vallen) {
        return null;
    }

    $pos += $vallen;
    return $val;
}

function bencode($var): string
{
    if (is_int($var)) {
        return 'i' . $var . 'e';
    }

    if (is_float($var)) {
        return 'i' . (int) $var . 'e';
    }

    if (is_array($var)) {
        if (count($var) === 0) {
            return 'de';
        }

        $assoc = false;
        foreach ($var as $key => $val) {
            if (!is_int($key)) {
                $assoc = true;
                break;
            }
        }

        if ($assoc) {
            ksort($var, SORT_STRING);
            $ret = 'd';
            foreach ($var as $key => $val) {
                $ret .= bencode((string) $key) . bencode($val);
            }
            return $ret . 'e';
        }

        $ret = 'l';
        foreach ($var as $val) {
            $ret .= bencode($val);
        }
        return $ret . 'e';
    }

    $var = (string) $var;
    return strlen($var) . ':' . $var;
}
