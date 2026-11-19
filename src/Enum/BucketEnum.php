<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Enum;

/**
 * the outcome groups of infection's JSON log, the value is the key the log lists the mutants under;
 * the order of the cases is the order the parser reads them in.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
enum BucketEnum: string
{
    case Escaped = 'escaped';
    case Timeouted = 'timeouted';
    case Killed = 'killed';
    case KilledByStaticAnalysis = 'killedByStaticAnalysis';
    case Errored = 'errored';
    case SyntaxErrors = 'syntaxErrors';
    case Uncovered = 'uncovered';
    case Ignored = 'ignored';
}
