# AGENTS.md

## Workflow Rules

### Version Bumping
- The version lives in the `VERSION` file at the repository root.
- Bump `VERSION` before every commit that changes behavior. Do NOT ask the user
  about version bumps — just do it (patch for fixes, minor for features).
- The version bump must happen BEFORE staging and committing, so it is included
  in the commit. This keeps the GitHub release (`v<VERSION>`) and the PHAR
  `--update` self-update mechanism in sync.

### Git
- NEVER run any git command that changes state: add, rm, mv, restore,
  reset, checkout, stash, commit, push, tag — none of them. This includes
  index/staging operations.
- Staging, committing and pushing are done exclusively by the user.
- NEVER ask the user about committing and pushing (no "Ready to commit?"
  prompts, no reminders, no offers).
- The ONLY way committing and pushing happens is if the user explicitly asks
  for it (e.g. "commit and push").
- Read-only commands (status, diff, log, ls-files) are allowed when needed
  for inspection.
- Move or rename files with plain filesystem commands (`mv`), never with
  `git mv`.
- NEVER forget to bump `VERSION` when the user asks for a commit. If you
  forget, the update process breaks.

### Release Flow
- Pushing to `main` triggers `.github/workflows/build-phar.yml`, which runs the
  tests, builds the PHAR and creates/updates the GitHub Release named
  `v<VERSION>` (see README "Releasing a new version").

### Making Changes
- Ask before making assumptions about scope. When in doubt, ask.
- Don't revert changes without asking first.
- If multiple features are being worked on, confirm with the user before
  bundling them into a single commit or splitting them.