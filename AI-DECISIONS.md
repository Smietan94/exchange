# AI-assisted development decisions

## What was prepared and why

This repository was developed with substantial assistance from AI coding tools. To make continued AI-assisted work safer, I added a root-level [`AGENTS.md`](./AGENTS.md) with project-wide instructions that should apply to routine coding tasks.

The instructions focus on areas where an incorrect change could affect financial correctness or data integrity:

- exact money and exchange-rate arithmetic, including the documented rounding order;
- atomic and idempotent transfer processing, fraud review, and wallet archival;
- tests for behavior changes, including database-backed cases;
- adding new migrations instead of rewriting migration history;
- protecting local configuration and credentials;
- precise API error mapping, especially mapping only the relevant unique-constraint violation to `409`;
- reporting skipped tests and actual validation results.

I chose one concise, always-applicable instruction file rather than adding multiple overlapping instruction files. It captures project invariants and working practices without trying to replace the README or code review.

## What I deliberately did not add

- No custom agent, skill, hook, or automated enforcement was added. The project currently benefits more from a small set of always-on rules than from additional workflows or abstractions.
- The instructions do not replace tests, review, or human decisions. They guide the agent but cannot guarantee that every generated change is correct.
- I did not add instructions for every class or duplicate all project documentation.
- I considered adding on-demand skills for a structured development cycle (analyze, implement, then review/analyze again), writing tests, and maintaining documentation. I did not add them because the current repository can express its essential always-on requirements in `AGENTS.md`, and these extra workflows were not necessary to complete the assignment.
- I also considered external skills such as `ihaveadhd` and `ponytail`. They were not added or evaluated as part of this work; external skills should be reviewed for relevance and trust before adoption.
- If the application gains a frontend, a focused skill for keeping UI messages consistent could be useful. There is no frontend-specific skill now because the current application is an API.

## How I checked whether the instructions affect agent behavior

I compared Codex runs with and without `AGENTS.md` on two small maintenance tasks. For each task, I used the same user-oriented prompt in both variants and reviewed the resulting changes.

| Task | Observation with `AGENTS.md` | Observation without `AGENTS.md` |
|---|---|---|
| Malformed JSON and unexpected API field types | The agent added a shared request-body decoder, returned `400` for malformed or non-object JSON, checked field types, and added broader controller tests covering all three wallet endpoints. It also ran tests. | The agent implemented similar client-error handling and added tests, but coverage was narrower. It did not run tests. |
| Rejecting a EUR transfer amount with more than two decimal places | The agent recognized that the existing validation already rejects the amount and added regression tests rather than changing the production behavior. It also ran tests. One pre-existing generic transfer-error test was replaced by a precision-specific test. | The agent added a more descriptive precision error and tests at controller and service level, preserving the existing generic error test. It did not run tests. |

Across these runs, the clearest practical difference was that the agent with `AGENTS.md` ran tests in both tasks, while the control agent did not. The comparison also showed different implementation choices: one variant favored broader shared request parsing, while the other made a narrower precision-message change.

This is a small practical experiment, not a controlled or statistically conclusive evaluation. Agent output is variable, and the outcomes do not show that the instruction file improves every implementation. In particular, changes still require human review: tests should add coverage without unnecessarily replacing useful existing tests, and an implementation should be judged on correctness and scope as well as whether tests were run.

## Limitations and follow-up

AI-generated code must still be reviewed and tested. Passing tests do not establish that all concurrency, database, or financial edge cases are covered. The instructions are a safety aid, not a security boundary or substitute for engineering judgment.

For future changes, continue to use focused tests followed by the full PHPUnit suite when feasible, confirm database-backed tests were not skipped, and report the exact commands and results.
