Please read PROJECT.md

## Test & Static Analysis Policy (MANDATORY)

- While developing a feature or fix, run the tests in the scope of that work: the tests you created or changed and the existing tests that cover the code you touched. That is the default stopping point. Do not finish every change with the full test suite; a button colour change never needs it.
- You may widen the run whenever you need more confidence (callers, shared code, related pages, diagnosing a failure), up to the full test suite when the change is broad enough to need it. No go-ahead is required.
- Laravel Pint, PHPStan/Larastan and any other static analysis, linter or formatter run only when Bruno asks for them.
- `npm run build` is not covered by this policy (frontend build rule still applies).
- Exception: `*-release-*` dynamic commands. Those always run the full test suite and Laravel Pint.
