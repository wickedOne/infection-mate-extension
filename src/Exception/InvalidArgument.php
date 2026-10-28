<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Exception;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class InvalidArgument extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly string $hint)
    {
        parent::__construct($message);
    }
}
