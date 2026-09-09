# Permissions (sub-users)

The server GitHub routes require the `git.*` permission group. Root admins always see the
GitHub tab; sub-users see it only when granted the git permissions.

## Automatic registration

PteroGit patches the panel's `app/Models/Permission.php` during installation and registers a
`git` group with the following actions:

| Permission key | Effect |
|---|---|
| `git.read` | View the GitHub tab, repository status, changes, branches, history and diffs |
| `git.pull` | Pull changes from GitHub into the server |
| `git.push` | Commit and push changes to GitHub (includes `git.commit`) |
| `git.commit` | Stage, unstage, discard and commit local changes |
| `git.manage-branches` | Create, switch and delete branches |
| `git.manage-repository` | Connect, initialize, disconnect and reset the repository |
| `git.manage-gitignore` | View and edit the `.gitignore` file |
| `git.revert` | Revert commits |
| `git.force-reset` | Perform a destructive hard reset of the repository |

## Granting permissions

1. Open a **server**.
2. Go to **Users** (sub-user management).
3. **Create** a sub-user or **Edit** an existing one.
4. Tick the checkboxes under the **Git** group.
5. Save.

The selected account will now see the **GitHub** tab on every server they have `git.read`
(or higher) permissions on.

## Notes

- Without any git sub-user permission, the GitHub tab is hidden unless the account is a
  root admin.
- Assigning the whole group via the header checkbox grants every `git.*` action at once.
- `git.push` requires `git.commit` behaviour; both are listed separately for fine-grained
  control (commit locally vs. push to GitHub).