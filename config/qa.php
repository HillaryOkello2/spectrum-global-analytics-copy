<?php

return [

    /*
    |--------------------------------------------------------------------------
    | QA verdict enforcement
    |--------------------------------------------------------------------------
    |
    | The QA pass (FR-26) audits the generated document against the client's
    | SGA-QCP protocol and ends with a GREEN LIGHT / RED LIGHT verdict. That
    | verdict is now acted on: a RED LIGHT document is sent back to the same
    | model with the audit report attached, and if it still cannot pass it is
    | parked in `qa_failed` instead of reaching the vault.
    |
    | `enforce` turns the gate off again without a deploy — the report is still
    | parsed, stored and shown, but the document goes to the proofreader
    | whatever it says. Worth knowing before changing it: LLM auditors are not
    | deterministic, and the same document has been GREEN on one run and RED on
    | the next with nothing about it changed. The revision rounds exist partly
    | for that reason, and `tasks/{task}/qa-override` exists so a human can
    | overrule a verdict they disagree with rather than lose the document.
    |
    */

    'enforce' => (bool) env('QA_ENFORCE_VERDICT', true),

    /*
    | How many times a RED LIGHT document is rewritten and re-audited before it
    | is given up on. Each round is two more LLM calls (a full rewrite and a
    | full re-audit) on a document that is already long, so this is the most
    | expensive setting in the system. 2 is a compromise: enough to clear the
    | one-off misses that a second attempt fixes, not enough to burn a day's
    | credit on a document the model cannot write.
    */

    'max_revisions' => (int) env('QA_MAX_REVISIONS', 2),

    /*
    | What to do when the auditor answered off-template and no verdict could be
    | read: `pass` (treat as GREEN and let a human judge), `revise` (ask again)
    | or `fail`. Defaults to `pass` — an unreadable audit is a fault in the
    | audit, and a proofreader reads the document anyway.
    */

    'unknown_verdict' => env('QA_UNKNOWN_VERDICT', 'pass'),

];
