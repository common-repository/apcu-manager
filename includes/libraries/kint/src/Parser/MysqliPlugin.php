<?php

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2013 Jonathan Vollebregt (jnvsor@gmail.com), Rokas Šleinius (raveren@gmail.com)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 * FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 * IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace APCMKint\Parser;

use APCMKint\Zval\Value;
use Mysqli;
use ReflectionClass;
use Throwable;

/**
 * Adds support for Mysqli object parsing.
 *
 * Due to the way mysqli is implemented in PHP, this will cause
 * warnings on certain Mysqli objects if screaming is enabled.
 */
class MysqliPlugin extends Plugin
{
    // These 'properties' are actually globals
    protected $always_readable = [
        'client_version' => true,
        'connect_errno' => true,
        'connect_error' => true,
    ];

    // These are readable on empty mysqli objects, but not on failed connections
    protected $empty_readable = [
        'client_info' => true,
        'errno' => true,
        'error' => true,
    ];

    // These are only readable on connected mysqli objects
    protected $connected_readable = [
        'affected_rows' => true,
        'error_list' => true,
        'field_count' => true,
        'host_info' => true,
        'info' => true,
        'insert_id' => true,
        'server_info' => true,
        'server_version' => true,
        'sqlstate' => true,
        'protocol_version' => true,
        'thread_id' => true,
        'warning_count' => true,
    ];

    public function getTypes()
    {
        return ['object'];
    }

    public function getTriggers()
    {
        return Parser::TRIGGER_COMPLETE;
    }

    public function parse(&$var, Value &$o, $trigger)
    {
        if (!$var instanceof Mysqli) {
            return;
        }

        try {
            $connected = \is_string(@$var->sqlstate);
        } catch (Throwable $t) {
            $connected = false;
        }

        try {
            $empty = !$connected && \is_string(@$var->client_info);
        } catch (Throwable $t) { // @codeCoverageIgnore
            // Only possible in PHP 8.0. Before 8.0 there's no exception,
            // after 8.1 there are no failed connection objects
            $empty = false; // @codeCoverageIgnore
        }

        foreach ($o->value->contents as $key => $obj) {
            if (isset($this->connected_readable[$obj->name])) {
                if (!$connected) {
                    continue;
                }
            } elseif (isset($this->empty_readable[$obj->name])) {
                // No failed connections after PHP 8.1
                if (!$connected && !$empty) { // @codeCoverageIgnore
                    continue; // @codeCoverageIgnore
                }
            } elseif (!isset($this->always_readable[$obj->name])) {
                continue;
            }

            if ('null' !== $obj->type) {
                continue;
            }

            // @codeCoverageIgnoreStart
            // All of this is irellevant after 8.1,
            // we have separate logic for that below

            $param = $var->{$obj->name};

            if (null === $param) {
                continue;
            }

            $base = Value::blank($obj->name, $obj->access_path);

            $base->depth = $obj->depth;
            $base->owner_class = $obj->owner_class;
            $base->operator = $obj->operator;
            $base->access = $obj->access;
            $base->reference = $obj->reference;

            $o->value->contents[$key] = $this->parser->parse($param, $base);

            // @codeCoverageIgnoreEnd
        }

        // PHP81 returns an empty array when casting a Mysqli instance
        if (APCMKINT_PHP81) {
            $r = new ReflectionClass(Mysqli::class);

            $basepropvalues = [];

            foreach ($r->getProperties() as $prop) {
                if ($prop->isStatic()) {
                    continue; // @codeCoverageIgnore
                }

                $pname = $prop->getName();
                $param = null;

                if (isset($this->connected_readable[$pname])) {
                    if ($connected) {
                        $param = $var->{$pname};
                    }
                } else {
                    $param = $var->{$pname};
                }

                $child = new Value();
                $child->depth = $o->depth + 1;
                $child->owner_class = Mysqli::class;
                $child->operator = Value::OPERATOR_OBJECT;
                $child->name = $pname;

                if ($prop->isPublic()) {
                    $child->access = Value::ACCESS_PUBLIC;
                } elseif ($prop->isProtected()) { // @codeCoverageIgnore
                    $child->access = Value::ACCESS_PROTECTED; // @codeCoverageIgnore
                } elseif ($prop->isPrivate()) { // @codeCoverageIgnore
                    $child->access = Value::ACCESS_PRIVATE; // @codeCoverageIgnore
                }

                // We only do base Mysqli properties so we don't need to worry about complex names
                if ($this->parser->childHasPath($o, $child)) {
                    $child->access_path .= $o->access_path.'->'.$child->name;
                }

                $basepropvalues[] = $this->parser->parse($param, $child);
            }

            $o->value->contents = \array_merge($basepropvalues, $o->value->contents);
        }
    }
}