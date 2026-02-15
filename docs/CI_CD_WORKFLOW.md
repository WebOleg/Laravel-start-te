# CI/CD Workflow Guide

Complete guide for the development and deployment workflow at Tether.

---

## Overview

Feature Branch → Develop Branch → Staging Branch → Main Branch
↓ ↓ ↓
Your code Auto-deploy Manual confirm
to staging to production

---

## Repositories

We have **two separate repositories**:

| Repository   | Technology           | URL                                      |
| ------------ | -------------------- | ---------------------------------------- |
| **Backend**  | Laravel (PHP)        | github.com/ThomasBlake777/Tether-Laravel |
| **Frontend** | Next.js (TypeScript) | github.com/ThomasBlake777/Tether-Front   |

Each repository has its own CI/CD pipeline.

---

## Automated Tests

### Backend (Laravel)

Tests run automatically on every PR:

# What runs in CI:

php artisan test

-   Unit tests
-   Feature tests
-   Database migrations check

### Frontend (Next.js)

Tests run automatically on every PR:

# What runs in CI:

npm run build

-   TypeScript compilation
-   Build errors check
-   Type checking

**PR cannot be merged if tests fail ❌**

---

## Branch Structure

| Branch      | Server                            | Deploy Type                 |
| ----------- |-----------------------------------| --------------------------- |
| `develop`   | Develop (199.217.98.92)           | **Automatic** on push       |
| `staging`   | Staging (137.184.105.172)         | **Automatic** on push       |
| `main`      | Production (testingiscool.online) | **Manual** confirm required |
| `feature/*` | -                                 | Development branches        |
| `fix/*`     | -                                 | Bug fix branches            |
| `hotfix/*`  | -                                 | Urgent production fixes     |

---

## Visual Flow
```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│   feature/your-feature                                          │
│           │                                                     │
│           │  PR (Pull Request)                                  │
│           │                                                     │
│           ▼                                                     │
│    ┌────────────┐                                               │
│    │ Run Tests  │                                               │
│    │ Backend:   │ php artisan test                              │
│    │ Frontend:  │ npm run build                                 │
│    └─────┬──────┘                                               │
│          │                                                      │
│          │  Tests pass ✅                                       │
│          ▼                                                      │
│    ┌──────────┐        ┌─────────────────────────┐              │
│    │ develop  │─────►  │     Develop Server      │              │
│    │ branch   │ AUTO   │   http://199.217.98.92  │              │
│    └────┬─────┘ DEPLOY └─────────────────────────┘              │
│         │                                                       │
│         │  PR + Merge                                           │
│         │                                                       │
│         ▼                                                       │
│    ┌──────────┐        ┌─────────────────────────┐              │
│    │ staging  │─────►  │     Staging Server      │              │
│    │ branch   │ AUTO   │ http://137.184.105.172  │              │
│    └────┬─────┘ DEPLOY └─────────────────────────┘              │
│         │                                                       │
│         │  PR + Merge                                           │
│         │                                                       │
│         ▼                                                       │
│    ┌──────────┐         ┌─────────────────────────┐             │
│    │  main    │─────►   │    Production Server    │             │
│    │  branch  │ MANUAL  │   testingiscool.online  │             │
│    └──────────┘ CONFIRM └─────────────────────────┘             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```
---

## Step-by-Step Workflow

### Step 1: Create Feature Branch

Always start from the `develop` branch:

# Update staging branch

git checkout develop
git pull origin develop

# Create your feature branch

git checkout -b feature/your-feature-name

**Branch naming conventions:**

| Type          | Pattern                | Example                    |
| ------------- | ---------------------- | -------------------------- |
| New feature   | `feature/description`  | `feature/bav-integration`  |
| Bug fix       | `fix/description`      | `fix/webhook-signature`    |
| Urgent fix    | `hotfix/description`   | `hotfix/login-error`       |
| Refactoring   | `refactor/description` | `refactor/billing-service` |
| Documentation | `docs/description`     | `docs/api-guide`           |

---

### Step 2: Develop Your Feature

Write your code and commit regularly:

# Make changes to your code

# ...

# Stage and commit

git add .
git commit -m "feat(scope): description of change"

# Push to your branch

git push origin feature/your-feature-name

**Commit message format:**

type(scope): short description

Types:

-   feat → new feature
-   fix → bug fix
-   refactor → code refactoring
-   docs → documentation
-   test → adding tests
-   chore → maintenance tasks

**Examples:**

git commit -m "feat(billing): add BAV mismatch exclusion"
git commit -m "fix(webhook): correct XML echo response"
git commit -m "docs(blacklist): add CLI documentation"

---

### Step 3: Pull Request to Develop
When your feature is ready for initial testing:

1. **Push your latest changes:**

    - git push origin feature/your-feature-name

2. **Create Pull Request on GitHub:**

    - Set base branch: develop

    - Set compare branch: feature/your-feature-name

    - Click "Create pull request"

3. **Merge**

**What happens after merge:**
Merge to develop branch → Auto Deploy → Live on Develop Server (http://199.217.98.92/admin)

### Step 4: Pull Request to Staging

Once verified on Develop:

1. **Create Pull Request:**

   - Set base branch: staging
   - Set compare branch: develop (or your feature branch if strict)

2. **Wait for tests:**

    - GitHub Actions runs automatically
    - **Backend:** `php artisan test` (unit & feature tests)
    - **Frontend:** `npm run build` (TypeScript compilation)
    - ❌ Red = tests failed, fix the issues
    - ✅ Green = tests passed, ready to merge

3. **Merge the PR:**
    - Click "Merge pull request"
    - Delete the feature branch (optional)

**What happens after merge:**

Merge to staging branch
↓
GitHub Actions
↓
Automatic Deploy ✅
↓
Live on Staging Server
http://137.184.105.172

---

### Step 5: Test on Staging

Before promoting to production:

1. **Open staging URL:**

    - Admin panel: http://137.184.105.172/admin

2. **Test your changes:**

    - Verify the feature works correctly
    - Check for any errors
    - Test edge cases

3. **If something is wrong:**
    - Create a fix branch
    - PR to staging again
    - Repeat testing

---

### Step 6: Pull Request to Main (Production)

When staging is tested and approved:

1. **Create Pull Request on GitHub:**

    - Click "New pull request"
    - Set base branch: `main`
    - Set compare branch: `staging`
    - Add description: "Release: [list of features]"
    - Click "Create pull request"

2. **Wait for tests:**

    - GitHub Actions runs tests again
    - ✅ Must be green to proceed

3. **Merge the PR:**
    - Click "Merge pull request"

---

### Step 7: Deploy to Production (Manual)

After merging to main, **deployment requires manual confirmation**:

1. **Go to GitHub repository**

2. **Click "Actions" tab**

3. **Find the deployment workflow:**

    - "Deploy Backend" for Laravel
    - "Deploy Frontend" for Next.js

4. **Click "Run workflow"** button

5. **Select branch:** `main`

6. **Click green "Run workflow"** button

7. **Wait for completion:**
    - Watch the progress
    - ✅ Green = deployed successfully
    - ❌ Red = check logs for errors

**Production deployment flow:**

Merge to main branch
↓
GitHub Actions
↓
Tests run ✅
↓
⏸️ WAITING FOR MANUAL CONFIRM
↓
You click "Run workflow"
↓
Zero-downtime deploy
↓
Live on Production ✅
https://testingiscool.online

**Zero-downtime deployment order:**

1. Node 1 → maintenance mode → update → health check → online
   (Node 2 handles all traffic)

2. Node 2 → maintenance mode → update → health check → online
   (Node 1 handles all traffic)

3. Worker → update → restart Horizon

---

## Running Tests Locally

Before pushing, run tests locally to catch issues early:

### Backend (Laravel)

cd tether-laravel

# Run all tests

php artisan test

# Run specific test file

php artisan test --filter=EmpWebhookTest

# Run with coverage

php artisan test --coverage

### Frontend (Next.js)

cd tether-front

# Build (same as CI)

npm run build

# Development mode

npm run dev

# Type check only

npx tsc --noEmit

---

## Common Scenarios

### Scenario 1: New Feature

# 1. Start from develop

git checkout develop
git pull origin develop

# 2. Create feature branch

    git checkout -b feature/analytics-dashboard

# 3. Develop and commit

    git add .
    git commit -m "feat(analytics): add chargeback rate chart"
    git push origin feature/analytics-dashboard

# 4. PR to develop → Merge
    http://199.217.98.92/admin (Test here first)
# 5. PR to staging → Merge
    http://137.184.105.172/admin (Final QA)
# 6. PR to main → Merge
# 7. GitHub → Actions → Run workflow
    Production deployed ✅

---

### Scenario 2: Bug Fix

# 1. Start from develop

    git checkout develop
    git pull origin develop

# 2. Create fix branch

    git checkout -b fix/webhook-event-parsing

# 3. Fix the bug

    git add .
    git commit -m "fix(webhook): use event param for chargebacks"
    git push origin fix/webhook-event-parsing

# 4. PR to develop → Merge → Test on Develop
# 5. PR to staging → Merge → Test on Staging
# 6. PR to main → Merge → Manual deploy

---

### Scenario 3: Hotfix (Urgent Production Fix)

For critical issues that need immediate fix:

# 1. Start from main (not staging!)

    git checkout main
    git pull origin main

# 2. Create hotfix branch

    git checkout -b hotfix/critical-login-error

# 3. Fix the issue

    git add .
    git commit -m "fix(auth): resolve 500 error on login"
    git push origin hotfix/critical-login-error

# 4. PR directly to main

# main ← hotfix/critical-login-error

# Merge

# 5. GitHub → Actions → Run workflow IMMEDIATELY

# 6. Sync staging with main

    git checkout staging
    git merge main
    git push origin staging

---

### Scenario 4: Frontend + Backend Changes

When both repositories need updates:

# === BACKEND (Tether-Laravel) ===

cd tether-laravel
git checkout staging && git pull
git checkout -b feature/new-api

# ... make changes ...

# Run tests locally first

php artisan test

git commit -m "feat(api): add new endpoint"
git push origin feature/new-api

# PR to staging → Merge

# === FRONTEND (Tether-Front) ===

cd tether-front
git checkout staging && git pull
git checkout -b feature/new-ui

# ... make changes ...

# Build locally first

npm run build

git commit -m "feat(ui): add UI for new endpoint"
git push origin feature/new-ui

# PR to develop → merge
# === TEST ON DEVELOP ===
# Verify http://199.217.98.92/admin

# === PROMOTE ===
# PR develop → staging (both repos)
# PR staging → main (both repos)
# Deploy Backend FIRST
# Deploy Frontend SECOND
**Important:** Always deploy backend before frontend when both have changes.
---

## Quick Reference

### Git Commands

| Action         | Command                            |
| -------------- | ---------------------------------- |
| Update develop | `git checkout develop && git pull` |
| Create branch  | `git checkout -b feature/name`     |
| Push branch    | `git push origin feature/name`     |
| Switch branch  | `git checkout branch-name`         |

### Local Testing

| Repository | Command            |
| ---------- | ------------------ |
| Backend    | `php artisan test` |
| Frontend   | `npm run build`    |

### Deployment

| Target     | How                                     |
| ---------- | --------------------------------------- |
| Staging    | Merge PR to `staging` (automatic)       |
| Production | Merge PR to `main` + confirm in Actions |

### URLs

| Environment | URL                                |
| ----------- |------------------------------------|
| Develop     | http://199.217.98.92/admin         |
| Staging     | http://137.184.105.172/admin       |
| Production  | https://testingiscool.online/admin |

### Repositories

| Project  | Technology           | Repository                               |
| -------- | -------------------- | ---------------------------------------- |
| Backend  | Laravel (PHP)        | github.com/ThomasBlake777/Tether-Laravel |
| Frontend | Next.js (TypeScript) | github.com/ThomasBlake777/Tether-Front   |

---

## Rules & Best Practices

### ✅ Do

- Start feature branches from develop.
- Test on Develop server before promoting to Staging.
- Run tests locally before pushing.
- Deploy backend before frontend.
- Write clear PR descriptions.

### ❌ Don't

-   Push directly to `main` , `staging` or `develop`
-   Merge with failing tests
-   Deploy on Friday evening
-   Skip staging testing
-   Deploy frontend before backend (when both changed)

---

## Troubleshooting

### Tests are failing

1. Check GitHub Actions logs
2. Run tests locally:
    - Backend: `php artisan test`
    - Frontend: `npm run build`
3. Fix the issue in your branch
4. Push again
5. Tests will re-run

### Staging not updated after merge

1. Check GitHub Actions tab
2. Look for errors in the workflow
3. If green but not updated → check server logs

### Production deploy failed

1. Check GitHub Actions logs
2. DO NOT panic
3. Fix the issue and re-run workflow
4. Or rollback: deploy previous commit

### Need to rollback production

1. Find the last working commit in `main`
2. GitHub → Actions → Run workflow with that commit
3. Or revert the merge commit and deploy

---

## Summary Flowchart

```
+-------------------------+
|          START          |
+-----------+-------------+
            |
            v
+-------------------------+
| Create Feature Branch   |
|     (from develop)      |
+-----------+-------------+
            |
            v
+-------------------------+
|   Develop & Commit      |
+-----------+-------------+
            |
            v
+-------------------------+
|   Run Tests Locally     |
+-----------+-------------+
            |
            v
+-------------------------+
|  Push to Feature Branch |
+-----------+-------------+
            |
            v
+-------------------------+
| Create PR:              |
| develop <--- feature    |
+-----------+-------------+
            |
            v
      +-----+-----+
      | Pass CI?  |
      +-----+-----+
      |     |
   NO |     | YES
      v     v
+-------+ +-------------------------+
| Fix   | |    Merge to develop     |
+-------+ +-----------+-------------+
                      |
                      v
          +-------------------------+
          | AUTO-DEPLOY to Develop  |
          |    (199.217.98.92)      |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          |     Test on Develop     |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          | Create PR:              |
          | staging <--- develop    |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          |    Merge to staging     |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          | AUTO-DEPLOY to Staging  |
          |    (137.184.105.172)    |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          |     Test on Staging     |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          | Create PR:              |
          | main <--- staging       |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          |      Merge to main      |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          |    GitHub Actions:      |
          |     MANUAL CONFIRM      |
          +-----------+-------------+
                      |
                      v
          +-------------------------+
          |  PRODUCTION DEPLOYED ✅ |
          +-----------+-------------+
                      |
                      v
                  +-------+
                  |  END  |
                  +-------+
```

---

## Questions?

Contact the team lead or check the GitHub Actions logs for deployment issues.
