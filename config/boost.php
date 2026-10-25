<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Guideline Targets
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md and AGENTS.md are thin pointers to PROJECT.md, the single
    | project guidance file both Claude and Codex read. Boost writes its
    | composed guidelines into the file named here instead, so boost:install
    | and boost:update can never append to, or replace a block inside, those
    | pointers by mistake.
    |
    | Both agents receive identical content — neither overrides Boost's
    | guideline transform and neither asks for frontmatter — so they share one
    | file: whichever runs last replaces the <laravel-boost-guidelines> block
    | in place. Every other Boost setting falls back to the package default.
    |
    */

    'agents' => [
        'claude_code' => [
            'guidelines_path' => '.ai/boost-guidelines.md',
        ],
        'codex' => [
            'guidelines_path' => '.ai/boost-guidelines.md',
        ],
    ],

];
