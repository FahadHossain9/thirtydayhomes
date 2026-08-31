# Deploying

Push to `main` → the live site updates. No zips, no uploading.

**Live:** https://thirtydayhomes.com (Hostinger)
**Repo:** `Md-Abu-Bakker-Siddik/thirtydayhomes-main`, branch `main`

---

## How it works

```
   you push to main
        │
        ▼
   GitHub Action  ──ssh──▶  server runs tools/deploy.sh
                                  │
                                  ├─ git reset --hard origin/main
                                  ├─ rsync themes/thirtydayhomes  → wp-content/themes/
                                  └─ rsync plugins/…-core         → wp-content/plugins/
                                  │
                                  ▼
                            curl the homepage, expect 200
```

The clone lives **outside** `public_html`. Two reasons, and both matter:

The repository does not have WordPress's shape — it keeps `plugins/` and
`themes/` at its root, so cloning it into the web root would put the theme at
`/themes/thirtydayhomes` rather than `/wp-content/themes/thirtydayhomes`,
where WordPress will never look.

And a clone inside the web root publishes `.git` to the internet. Anyone can
fetch `.git/config` and walk the object files back into the full source
history. That is a heavily scanned-for exposure; keeping the clone out of the
web root removes the possibility instead of relying on an `.htaccess` rule a
server rebuild could drop.

**A deploy only ever writes those two folders.** `wp-config.php`, uploads,
other plugins and WordPress core are never touched.

---

## One-time setup

### 1. Turn SSH on

Hostinger → **Advanced → SSH Access → Enable**. Note the details; yours are:

| | |
|---|---|
| Host | `46.202.199.59` |
| Port | `65002` |
| User | `u810959500` |

### 2. Make a key for GitHub to use

On **your own machine**, in PowerShell. The first line matters: `ssh-keygen`
does not create the folder, and fails with *No such file or directory* if it
is missing.

```powershell
New-Item -ItemType Directory -Force -Path "$env:USERPROFILE\.ssh" | Out-Null

ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\thirtydayhomes_deploy" -C "github-actions@thirtydayhomes" -N '""'
```

Two files appear. `thirtydayhomes_deploy.pub` is the public half and is safe
to share; `thirtydayhomes_deploy` is **private** and must never be committed,
pasted into a chat, or screenshotted.

### 3. Let that key into the server

Copy the **public** half:

```powershell
Get-Content "$env:USERPROFILE\.ssh\thirtydayhomes_deploy.pub"
```

Paste it into Hostinger → **SSH Access → SSH keys → Add key**.

Then check it works:

```powershell
ssh -p 65002 -i "$env:USERPROFILE\.ssh\thirtydayhomes_deploy" u810959500@46.202.199.59 "echo connected; pwd"
```

### 4. Clone the repo on the server

Still in that SSH session:

```bash
mkdir -p ~/repos
git clone https://github.com/Md-Abu-Bakker-Siddik/thirtydayhomes-main.git ~/repos/thirtydayhomes-main
```

**If that asks for a username and password, the repository is private** and
this route will not work — GitHub stopped accepting passwords over HTTPS.
Give the server its own read-only key instead:

```bash
# on the SERVER
ssh-keygen -t ed25519 -f ~/.ssh/github_readonly -N "" -C "thirtydayhomes-server"
cat ~/.ssh/github_readonly.pub
```

Add that public key at **GitHub → the repo → Settings → Deploy keys → Add
deploy key**. Leave *Allow write access* **unticked** — the server only ever
reads, and a key that cannot push cannot damage the repository if the server
is ever compromised.

Then tell SSH to use it, and clone over SSH:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_readonly
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

git clone git@github.com:Md-Abu-Bakker-Siddik/thirtydayhomes-main.git ~/repos/thirtydayhomes-main
```

Either way, confirm where WordPress actually lives:

```bash
ls ~/domains/thirtydayhomes.com/public_html/wp-config.php
```

If that last path is wrong, find the real one — it is whatever directory
holds `wp-config.php`:

```bash
find ~ -maxdepth 4 -name wp-config.php 2>/dev/null
```

### 5. Run it once by hand

Never let the first run be an automatic one:

```bash
bash ~/repos/thirtydayhomes-main/tools/deploy.sh
```

Expect the fetch, then two rsync blocks, then `done`. Load the site.

### 6. Add the GitHub secrets

Repo → **Settings → Secrets and variables → Actions → New repository secret**.

| Secret | Value |
|---|---|
| `SSH_PRIVATE_KEY` | the **whole** private file, `-----BEGIN…` to `-----END…` inclusive |
| `SSH_HOST` | `46.202.199.59` |
| `SSH_PORT` | `65002` |
| `SSH_USER` | `u810959500` |
| `REPO_DIR` | `/home/u810959500/repos/thirtydayhomes-main` |
| `WP_DIR` | `/home/u810959500/domains/thirtydayhomes.com/public_html` |
| `SITE_URL` | `https://thirtydayhomes.com` |
| `SSH_KNOWN_HOSTS` | `[46.202.199.59]:65002 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINtwFSZT9AasFsz7E6RuINORQeV7kIrPLqRSPign9tDq` |

That last one pins the server's identity. Without it the deploy would have to
accept whatever machine answered, and would hand its key to anything that
took over the address.

To regenerate it later — after a server move, say — note that
`ssh-keyscan` on Windows **fails against this host**:

```
choose_kex: unsupported KEX method sntrup761x25519-sha512@openssh.com
```

The server runs OpenSSH 9.9 and Windows ships 9.5, which cannot negotiate its
default key exchange. Connect once instead; the host key is exchanged before
authentication, so this records it even though the login is refused:

```powershell
ssh -p 65002 -o StrictHostKeyChecking=accept-new `
    -o UserKnownHostsFile="$env:TEMP\tdh_hostkey" `
    -o KexAlgorithms=curve25519-sha256 `
    u810959500@46.202.199.59 exit

Get-Content "$env:TEMP\tdh_hostkey"
```

### 7. Push

Any push to `main` now deploys. Watch it under the repo's **Actions** tab.

---

## Day to day

```
build locally  →  verify  →  commit  →  push  →  it is live
```

Nothing else. Watch Actions if you want to see it happen.

**Deploy by hand** — Actions tab → *Deploy to thirtydayhomes.com* → **Run
workflow**. Useful when a deploy failed for a reason outside the code.

---

## Rolling back

There are two things to roll back, and they are not the same.

### The code — revert the commit and push

The server does `git reset --hard origin/main`, so it lands exactly on whatever
the branch says, including going backwards. This is the fast path and it covers
most of what goes wrong.

### The database — restore the pre-deploy snapshot

**A code revert does not undo a database change.** `Core::maybe_upgrade()` runs
whenever the plugin's version constant is higher than the stored one: it
re-registers roles and capabilities and can install tables. Once that has run,
going back to the old code leaves the migrated database behind it, and the old
code is not expecting it.

So every deploy takes a database snapshot **before** it writes anything:

```
~/backups/thirtydayhomes/pre-deploy/20260831-141500-before-deploy.sql.gz
```

A week of them is kept. To go back:

```bash
cd ~/backups/thirtydayhomes/pre-deploy
ls -lt | head                       # the newest is the one from the bad deploy
gunzip -c <file>.sql.gz > /tmp/rb.sql
wp --path=~/domains/thirtydayhomes.com/public_html db import /tmp/rb.sql
rm /tmp/rb.sql
```

Then revert the commit and push, so the code matches the database again.

If the snapshot step printed `!!` during the deploy, there is no snapshot — the
nightly backup in `tools/BACKUPS.md` is then the only way back, and it costs
everything since it ran.

### After any rollback

Purge the LiteSpeed cache. Old cached pages survive a deploy and a restore both.

---

## Two rules

**Bump the plugin version when you change the plugin.**
`Core::maybe_upgrade()` only reinstalls roles and tables when the stored
version is behind the code. Ship a capability change without a bump and the
code updates while the permissions do not — a landlord then silently cannot
do something the new code assumes they can. Both the plugin header and the
`VERSION` constant must match; the Action fails the build if they disagree,
and warns if the plugin changed without a bump.

**Never re-run the demo importer on the live site.**
It rebuilds both menus from scratch and overwrites the seeded pages,
including anything edited since. It is for first install only. The deploy
does not run it.

---

## Before launch, change one thing

Right now **every push to `main` goes straight to production**. That is the
right trade while the site has no customers and speed matters more than
ceremony.

Once real landlords are paying, it is the wrong one: a careless push takes
down a site that is charging cards. At that point switch the trigger in
`.github/workflows/deploy.yml` to deploy from tags instead:

```yaml
on:
  push:
    tags: [ 'v*' ]
  workflow_dispatch:
```

Then `main` stays freely pushable and going live becomes a deliberate act —
tag `v1.2.0` and push the tag.

---

## When something goes wrong

**`bash\r: No such file or directory`** — the script was checked out with
Windows line endings. `.gitattributes` pins `*.sh` to LF; if this appears,
re-clone on the server.

**`rsync: command not found`** — rare on Hostinger. Ask support to enable it,
or fall back to uploading the zips from `tools/build-release.ps1`.

**`Permission denied (publickey)`** — the public key is not on the server, or
`SSH_PRIVATE_KEY` is missing its `BEGIN`/`END` lines. Test the exact command
from step 3 locally first.

**The site 500s after a deploy** — the Action tells you, because it checks
for a 200. Revert the commit and push; that is the fastest fix, and the cause
can be found afterwards.

**A deploy succeeded but nothing changed** — check the Action actually ran on
the commit you expected, then `git log --oneline -1` on the server to see
where it landed.
