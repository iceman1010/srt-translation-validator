# AGENTS.md

## Workflow Rules

### Version Bumping — OWNED BY THE USER
- The version lives in the `VERSION` file at the repository root.
- **The agent NEVER edits `VERSION`.** No bumping, no preparing, no "you should
  bump" suggestions. The user owns versioning and the whole release flow; when
  the agent touches `VERSION` it fights the user's own workflow.
- The GitHub release (`v<VERSION>`) and the PHAR `--update` stay in sync only
  because the user controls that file — keep hands off.

### Git — ALL OF IT IS THE USER'S JOB
- NEVER run any git command that changes state: add, rm, mv, restore,
  reset, checkout, stash, commit, push, tag, filter-branch, rebase, merge —
  none of them. This includes index/staging operations.
- Staging, committing and pushing are done exclusively by the user.
- NEVER ask the user about committing and pushing (no "Ready to commit?"
  prompts, no reminders, no offers).
- The ONLY way committing and pushing happens is if the user explicitly asks
  for it (e.g. "commit and push").
- Read-only commands (status, diff, log, ls-files) are allowed only when needed
  for inspection — and even then, prefer not to touch git at all if the answer
  can be found by reading files instead.
- Move or rename files with plain filesystem commands (`mv`), never with
  `git mv`.

### Tests and Scripts — ALWAYS INSIDE THE PROJECT
- Tests live in the project's `tests/` directory. Never create tests or
  reusable scripts outside the project folder — nothing reusable goes into
  `/tmp` style scratch dirs (they get wiped, the user loses context, and the
  work has to be recreated every session).
- Reusable investigation tooling belongs in `docs/scripts/` (gitignored via
  the repo's `/docs/` rule), with a README documenting each step so it can be
  re-run.

### Release Flow
- Pushing to `main` runs the tests. The PHAR is built and the GitHub Release
  `v<VERSION>` created/updated **only when the `VERSION` file changed**
  compared to the latest released tag (see README "Releasing a new version").
- `workflow_dispatch` (Actions tab, "Run workflow") force-rebuilds the current
  version.

### Making Changes
- Ask before making assumptions about scope. When in doubt, ask.
- Don't revert changes without asking first.
- If multiple features are being worked on, confirm with the user before
  bundling them into a single commit or splitting them.