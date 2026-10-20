<?php

namespace App\Enums;

/**
 * What the pipeline did with a QA audit — returned to the job that ran it so
 * it knows whether to queue a rewrite. The job dispatches, not the pipeline:
 * the decision is made inside a transaction and a job must not be queued
 * against a row that has not committed yet.
 */
enum QaOutcome: string
{
    /** Passed (or the gate is off): on to the proofreader. */
    case Accepted = 'accepted';

    /** Failed, with a revision round left: the model rewrites it. */
    case Revising = 'revising';

    /** Failed, out of rounds: parked, and kept out of the vault. */
    case Failed = 'failed';
}
