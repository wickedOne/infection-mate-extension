<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Runner\Argument;

/**
 * what the tool parameters came down to: the arguments to hand to Infection, and whether they limit the run to
 * specific mutants, so the caller does not have to read that back from the argument strings.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ResolvedArguments
{
    /**
     * @param list<string> $arguments    validated Infection arguments, without binary and fixed flags
     * @param bool         $limitedToIds whether the id option selects the mutants to run
     */
    public function __construct(
        public readonly array $arguments,
        public readonly bool $limitedToIds,
    ) {
    }
}
