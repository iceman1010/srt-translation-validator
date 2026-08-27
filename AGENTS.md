# AGENTS.md

## Workflow Rules

### Version Bumping
- The version lives in the `VERSION` file at the repository root.
- Bump `VERSION` before every commit that changes behavior. Do NOT ask the user
  about version bumps — just do it (patch for fixes, minor for features).
- The version bump must happen BEFORE staging and committing, so it is included
  in the commit. This keeps the GitHub release (`v<VERSION>`) and the PHAR
  `--update` self-update mechanism in sync.

### Commit and Push
- NEVER commit or push without the user's **explicit approval**.
- Always ask before committing and pushing. Example: "Ready to commit and push?"
- When the user says "commit and push" or gives clear approval, proceed
  immediately.
- NEVER forget to bump `VERSION` before committing. If you forget, the update
  process breaks.

### Release Flow
- Pushing to `main` triggers `.github/workflows/build-phar.yml`, which runs the
  tests, builds the PHAR and creates/updates the GitHub Release named
  `v<VERSION>` (see README "Releasing a new version").

### Making Changes
- Ask before making assumptions about scope. When in doubt, ask.
- Don't revert changes without asking first.
- If multiple features are being worked on, confirm with the user before
  bundling them into a single commit or splitting them.