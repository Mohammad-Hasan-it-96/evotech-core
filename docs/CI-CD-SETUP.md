# CI/CD setup — deploy `main` automatically

Every step below is tagged with **where you run it**:

| Tag | Where |
| --- | --- |
| 💻 **LAPTOP** | your Windows machine, in **Git Bash** (not PowerShell — the commands are POSIX) |
| 🖥️ **SERVER** | logged into the VPS over SSH |
| 🌐 **GITHUB** | in the browser, on the repository page |

Work through them in order. Steps 1–4 are the ones that bite; the rest is filling
in forms.

---

## What we are building

```
you push to main
        │
        ▼
GitHub Actions  ──►  runs tests / build          (fails here = nothing deploys)
        │
        ▼
    ssh to VPS  ──►  git pull  ──►  install  ──►  restart  ──►  health check
```

Two separate credentials are involved, and mixing them up is the most common
reason this fails. They point in **opposite directions**:

| Key | Lives on | Lets | Direction |
| --- | --- | --- | --- |
| **Deploy key** (step 1) | GitHub Secrets | GitHub Actions log into your VPS | Actions → VPS |
| **Server key** (step 5) | the VPS | the VPS pull from GitHub | VPS → GitHub |

You need both. Step 1 alone is not enough — that is the trap.

---

## 1. 💻 LAPTOP — generate the deploy key

Open **Git Bash** and run:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/evotech_deploy -N ""
```

That writes two files:

- `~/.ssh/evotech_deploy` — the **private** key → goes into GitHub Secrets
- `~/.ssh/evotech_deploy.pub` — the **public** key → goes onto the server

> `-N ""` means no passphrase. Correct here: nobody can type a passphrase inside
> a GitHub Action. That is exactly why this is a dedicated key and not your
> personal one — if it leaks you revoke this one key and your own access is
> untouched.

---

## 2. 🖥️ SERVER — find your usernames and paths

> ### ⚠️ There are TWO users, one per site
>
> CloudPanel creates a separate system user for each site, and this deployment
> has two sites:
>
> | Site | User | Path |
> | --- | --- | --- |
> | `evotech-sys.com` (Next.js) | `evotech-sys` | `/home/evotech-sys/htdocs/evotech-sys.com` |
> | `api.evotech-sys.com` (Laravel) | `evotech-sys-api` | `/home/evotech-sys-api/htdocs/api.evotech-sys.com` |
>
> **Keep it that way.** It is the correct setup: each site is confined to its own
> user, so a compromise of the website cannot read the API's `.env` — which holds
> the database password and the Firebase service-account path.
>
> The consequence for this guide: **`~/.ssh` is per-user**, so steps 3, 4 and 5
> must be done **twice** — once logged in as each user. The workflows already
> support this, because `SSH_USER` is a *per-repository* secret.

Log in as each user in turn and run:

```bash
whoami
echo "$HOME"
ls -d ~/htdocs/*
```

Write down, for each:

- the username → **`SSH_USER`** (different value in each repo's secrets)
- the Next.js path → **`WEB_PATH`** (in `evotech-web` secrets)
- the Laravel path → **`API_PATH`** (in `evotech-core` secrets)

> Never deploy as `root` into a directory owned by a site user: the files end up
> root-owned and the web server can no longer write to `storage/` or `.next/`.

---

## 3. 🖥️ SERVER — create `~/.ssh` (this is what you hit)

> **Run steps 3, 4 and 5 once as `evotech-sys`, then again as `evotech-sys-api`.**
> Each has its own home directory, so work done as one user does not exist for
> the other. Confirm with `whoami` before each block — running these as the wrong
> user is the easiest mistake to make here, and it fails much later with a
> confusing error.

There is nothing wrong. The directory does not exist until something creates it,
and SSH is **deliberately fussy** about its permissions: if they are too open it
ignores the directory entirely and you get "Permission denied" with no
explanation.

Run exactly this:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
touch ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Check it:

```bash
ls -ld ~/.ssh ~/.ssh/authorized_keys
```

Expect `drwx------` on the directory and `-rw-------` on the file. Anything more
permissive and SSH will refuse to use it.

```bash
# also make sure your home directory is not group-writable — same failure mode
chmod go-w ~
```

---

## 4. Install the deploy key on the server

### 4a. 💻 LAPTOP — print the public key

```bash
cat ~/.ssh/evotech_deploy.pub
```

One line starting `ssh-ed25519 AAAA…`. Copy the whole thing.

> `ssh-copy-id` often does not exist on Windows. Pasting is fine.

### 4b. 🖥️ SERVER — append it

```bash
echo 'PASTE_THE_WHOLE_LINE_HERE' >> ~/.ssh/authorized_keys
```

Use **single quotes**, and make sure it lands as one line:

```bash
tail -1 ~/.ssh/authorized_keys
wc -l ~/.ssh/authorized_keys
```

### 4c. 💻 LAPTOP — prove it works

**Do not skip this.** If this fails, every later step fails with a confusing error.

```bash
ssh -i ~/.ssh/evotech_deploy evotech-sys@<SSH_HOST>     "whoami && pwd"
ssh -i ~/.ssh/evotech_deploy evotech-sys-api@<SSH_HOST> "whoami && pwd"
```

Each must print its own username and home directory **without asking for a
password**. If either asks, see Troubleshooting → *Permission denied*.

> The **same** deploy key is used for both users — you simply append the same
> public line to each user's `authorized_keys`. That keeps one secret value to
> manage and one key to revoke. (Separate keys per user also work if you prefer
> tighter isolation; then `SSH_KEY` differs per repo too.)

---

## 5. 🖥️ SERVER — let the server pull from GitHub

**This is the step that is easy to miss.** The deploy key from step 1 lets GitHub
log in *to the server*. It does nothing for the server pulling *from GitHub* — and
if your repos are private, `git pull` will hang waiting for a password that
nobody can type. (This is the same authentication wall you hit before.)

### 5a. 🖥️ SERVER — make a key for the server itself

```bash
ssh-keygen -t ed25519 -C "vps-pull" -f ~/.ssh/github_pull -N ""
cat ~/.ssh/github_pull.pub
```

Copy that public line.

### 5b. 🖥️ SERVER — tell SSH to use it for GitHub

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  User git
  IdentityFile ~/.ssh/github_pull
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

### 5c. 🌐 GITHUB — add it as a deploy key, per repo

For **each** repo (`evotech-core`, `evotech-web`):

**Settings → Deploy keys → Add deploy key**

| Repo | Add the key generated by | Title |
| --- | --- | --- |
| `evotech-web` | `evotech-sys` | `vps-pull-web` |
| `evotech-core` | `evotech-sys-api` | `vps-pull-api` |

- **Allow write access: leave UNCHECKED** — the server only ever reads.

> GitHub refuses the same deploy key on two repositories. The two-user setup
> solves that for free: each user generates its own key in its own `~/.ssh`, so
> they are already different keys and there is nothing to work around.

### 5d. 🖥️ SERVER — point the remotes at SSH and test

As **`evotech-sys`**:

```bash
cd ~/htdocs/evotech-sys.com
git remote set-url origin git@github.com:Mohammad-Hasan-it-96/evotech-web.git
git ls-remote origin -h refs/heads/main     # must succeed with no prompt
```

As **`evotech-sys-api`**:

```bash
cd ~/htdocs/api.evotech-sys.com
git remote set-url origin git@github.com:Mohammad-Hasan-it-96/evotech-core.git
git ls-remote origin -h refs/heads/main
```

Both must print a commit hash. If either prompts for anything, stop and fix it —
the deploy will hang otherwise.

---

## 6. 🖥️ SERVER — pre-flight the working copies

The deploy **refuses to run on a dirty tree** (by design — the server grew its own
commit once this way).

```bash
cd <WEB_PATH> && git status --short && git rev-parse --abbrev-ref HEAD
cd <API_PATH> && git status --short && git rev-parse --abbrev-ref HEAD
```

Both must be **empty output** and on **main**. If a file is listed, look at it
before discarding — `git diff <file>` — then `git checkout -- <file>`.

Also confirm the tools exist for a **non-interactive** shell (see Troubleshooting
→ *command not found*, this is a real trap):

```bash
ssh -i ~/.ssh/evotech_deploy <SSH_USER>@<SSH_HOST> "which git composer php node npm"
```

Run this **from your laptop**, not on the server — that is the only way to
reproduce the environment the Action actually gets. Every line must print a path.

---

## 7. 🌐 GITHUB — add the secrets

Per repo: **Settings → Secrets and variables → Actions → New repository secret**

### Both repos — same value

| Name | Value |
| --- | --- |
| `SSH_HOST` | VPS IP or hostname |
| `SSH_KEY` | **entire contents** of `~/.ssh/evotech_deploy` — see below |
| `SSH_PORT` | only if your SSH port is not 22 |

### Both repos — DIFFERENT value

| Repo | `SSH_USER` |
| --- | --- |
| `evotech-web` | `evotech-sys` |
| `evotech-core` | `evotech-sys-api` |

> This is the one that is easy to get wrong. Setting both to `evotech-sys` makes
> the API deploy log into the wrong home directory, and `cd "$API_PATH"` then
> fails with "Permission denied" or "No such file or directory" — pointing at the
> path, when the real problem is the user.

To get `SSH_KEY` — 💻 LAPTOP:

```bash
cat ~/.ssh/evotech_deploy
```

Copy **everything**, including these lines:

```
-----BEGIN OPENSSH PRIVATE KEY-----
...
-----END OPENSSH PRIVATE KEY-----
```

> Note this is `evotech_deploy`, **not** `evotech_deploy.pub`. The public one goes
> on the server; the private one goes here. Missing the BEGIN/END lines is the
> single most common cause of a failed first run.

### `evotech-core` only

| Name | Value |
| --- | --- |
| `API_PATH` | e.g. `/home/evotech-sys/htdocs/api.evotech-sys.com` |
| `API_BASE_URL` | `https://api.evotech-sys.com` — **no trailing slash** |

### `evotech-web` only

| Name | Value |
| --- | --- |
| `WEB_PATH` | e.g. `/home/evotech-sys/htdocs/evotech-sys.com` |
| `WEB_BASE_URL` | `https://evotech-sys.com` — **no trailing slash** |

---

## 8. 🌐 GITHUB — merge the workflow branches

Branch `ci/auto-deploy-main` exists in both repos.

> ⚠️ **Merging starts a deploy immediately.** Do not merge until steps 1–7 are
> done and step 4c and 5d both passed.

Merge **`evotech-core` first**, then **`evotech-web`** — the dashboard's decline
button calls an API endpoint that must exist first.

---

## 9. Verify the first run

🌐 **GITHUB → Actions tab** — watch the run.

Then check by hand:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.evotech-sys.com/up
curl -s https://evotech-sys.com/config/fawateer.json
```

Expect `200`, and JSON containing
`"base_url": "https://api.evotech-sys.com/api/fawateer"`.

Open `/ar/dashboard/devices`: table headers should sit over their columns, and a
pending row should show a **رفض** (decline) button.

---

## Troubleshooting

### `Permission denied (publickey)`

The most common failure. In order of likelihood:

1. You pasted the `.pub` file into `SSH_KEY` instead of the private key.
2. The private key is missing its `BEGIN`/`END` lines.
3. Permissions — 🖥️ SERVER: `chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys && chmod go-w ~`
4. The key landed wrapped across lines in `authorized_keys` — `wc -l` it; it must be one line per key.

Reproduce it from 💻 LAPTOP with `ssh -v -i ~/.ssh/evotech_deploy <user>@<host>` —
the `-v` output names the actual reason.

### `composer: command not found` / `npm: command not found`

A non-interactive SSH session does **not** read `.bashrc` or `.profile`, so
anything installed via `nvm` or into `~/bin` is not on `PATH` — even though it
works fine when you log in normally. This is why step 6 tests via `ssh`, not on
the server directly.

Fix by making the tool findable system-wide, 🖥️ **SERVER**:

```bash
which npm            # e.g. /home/you/.nvm/versions/node/v22.x/bin/npm
sudo ln -s <that path> /usr/local/bin/npm
sudo ln -s <node path> /usr/local/bin/node
```

Or add an explicit `PATH` line at the top of the workflow's `script:`.

### `fatal: could not read Username for 'https://github.com'`

Step 5 was skipped or incomplete — the server is still on an HTTPS remote with no
credentials. Redo 5b–5d and confirm `git ls-remote origin` succeeds with no prompt.

### `Working tree is dirty — refusing to deploy`

Intended. 🖥️ SERVER: `cd <path> && git status --short`, inspect each file with
`git diff <file>`, then `git checkout -- <file>`. Do not force past it — this
guard exists because the server once made its own commit and diverged.

### `Host key verification failed`

🖥️ SERVER, once: `ssh-keyscan github.com >> ~/.ssh/known_hosts`

### The deploy succeeds but the site is unchanged

Next.js serves the build it loaded at start. `deploy.sh` restarts the process, so
if you skipped it and pulled by hand, restart. Confirm which commit is live:
🖥️ SERVER `cd <WEB_PATH> && git log --oneline -1`.

---

## What this does not cover

- **The legacy backend** (`IchancyBot-SmartAgentBack`) is deliberately **not** on
  CD — it is the server being retired, and it has a fail-closed security guard
  that must be configured by hand. Deploy it manually: set `ADMIN_API_TOKEN` in
  `.env` **before** `git pull`, then `php artisan config:clear`.
- **Rollback.** There is none yet. To revert, `git revert` on GitHub and let the
  deploy run again — which is why the tests gate the deploy.
- **Database backups.** Every green push runs `migrate --force` on production. A
  bad migration deploys itself. Have a backup schedule before relying on this.
