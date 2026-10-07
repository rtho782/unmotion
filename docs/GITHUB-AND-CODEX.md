# Put the repository on GitHub and open it in Codex

## 1. Extract and initialize Git

In PowerShell, replace the example path with the folder where you extracted the delivered ZIP:

```powershell
Set-Location 'C:\Dev\unmotion-0.3.0-beta7-repository'
git init -b main
git add .
git commit -m "Import recovered unMotion 0.3.0-beta7 baseline"
```

Check the commit before publishing:

```powershell
git status
git log -1 --stat
```

## 2. Create and push the GitHub repository

### GitHub website method

1. In GitHub, choose **New repository**.
2. Name it `unmotion` (or another name you prefer).
3. Choose public or private.
4. Do **not** initialize it with a README, `.gitignore` or license; those already exist locally.
5. Copy the repository URL and run:

```powershell
git remote add origin https://github.com/YOUR-USER/unmotion.git
git push -u origin main
```

### GitHub CLI method

If GitHub CLI is installed and authenticated:

```powershell
gh repo create unmotion --private --source . --remote origin --push
```

Change `--private` to `--public` if desired.

## 3. Clone into the development VM (if different)

```powershell
New-Item -ItemType Directory -Force 'C:\Dev' | Out-Null
Set-Location 'C:\Dev'
git clone https://github.com/YOUR-USER/unmotion.git
Set-Location '.\unmotion'
```

## 4. Open in Codex Desktop

1. Open the Codex desktop app.
2. Choose **Open folder** / **Add project** and select the repository folder (for example `C:\Dev\unmotion`).
3. Start a task in that project. Codex will read the root `AGENTS.md` automatically as repository guidance.
4. For the first task, use a prompt such as:

```text
Read README.md, AGENTS.md, CHANGELOG.md and docs/PROVENANCE.md.
Inspect the recovered 0.3.0-beta7 source without changing it. Summarize the
architecture, the safety invariants, and what access would be needed to test
against UNRAID-DEV01 and UNRAID-DEV02. Do not contact or modify either host yet.
```

Keep the repository folder as the Codex project. Future tasks will then share the code, Git state and durable instructions stored in the repository, without depending on the old ChatGPT conversation.

