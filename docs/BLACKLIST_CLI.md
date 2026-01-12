# Blacklist CLI Management

Command-line tools for managing the blacklist in Tether. All commands should be run inside the Docker container.

## Quick Reference

| Command | Description |
|---------|-------------|
| `blacklist:add` | Add entry to blacklist |
| `blacklist:list` | List/search blacklist entries |
| `blacklist:remove` | Remove entry from blacklist |
| `blacklist:export` | Export to JSON (backup) |
| `blacklist:import` | Import from JSON (restore/sync) |

## Commands

### 1. Add Entry (`blacklist:add`)

Add a new entry to the blacklist. Supports both interactive and parameter modes.

**Interactive mode:**
```bash
docker exec -it tether_app php artisan blacklist:add
```

Prompts for:
- IBAN
- Email
- First name
- Last name
- BIC
- Reason
- Source (manual, support, system-auto, chargeback)

**With parameters:**
```bash
docker exec tether_app php artisan blacklist:add \
  --iban=DE89370400440532013000 \
  --email=fraud@example.com \
  --first-name=John \
  --last-name=Doe \
  --bic=COBADEFFXXX \
  --reason="Chargeback AC04" \
  --source=chargeback
```

**Options:**

| Option | Description |
|--------|-------------|
| `--iban` | IBAN to blacklist |
| `--email` | Email to blacklist |
| `--first-name` | First name |
| `--last-name` | Last name |
| `--bic` | BIC code |
| `--reason` | Reason for blacklisting (default: "Manual blacklist") |
| `--source` | Source: manual, support, system-auto, chargeback |

**Note:** At least one identifier (IBAN, email, name, or BIC) is required.

**Examples:**
```bash
# Block IBAN after chargeback
docker exec tether_app php artisan blacklist:add \
  --iban=ES9121000418450200051332 \
  --reason="Chargeback MD06" \
  --source=chargeback

# Block email from support request
docker exec tether_app php artisan blacklist:add \
  --email=fraudster@example.com \
  --reason="Reported fraud" \
  --source=support

# Block by name (when IBAN unknown)
docker exec tether_app php artisan blacklist:add \
  --first-name=John \
  --last-name=Fraudster \
  --reason="Multiple chargebacks" \
  --source=manual
```

---

### 2. List/Search Entries (`blacklist:list`)

View and search blacklist entries.

**Basic usage:**
```bash
docker exec tether_app php artisan blacklist:list
```

**With filters:**
```bash
# Search by IBAN, email, or name
docker exec tether_app php artisan blacklist:list --search=DE893

# Filter by source
docker exec tether_app php artisan blacklist:list --source=chargeback

# Show more entries
docker exec tether_app php artisan blacklist:list --limit=100

# Combined filters
docker exec tether_app php artisan blacklist:list --source=support --limit=50
```

**Options:**

| Option | Description |
|--------|-------------|
| `--search` | Search in IBAN, email, first_name, last_name |
| `--source` | Filter by source (manual, support, system-auto, chargeback) |
| `--limit` | Number of entries to show (default: 20) |

**Output example:**
```
Showing 20 of 3133 entries

+------+------------------------+-------------------+------------+----------------------+------------+------------+
| ID   | IBAN                   | Email             | Name       | Reason               | Source     | Created    |
+------+------------------------+-------------------+------------+----------------------+------------+------------+
| 3133 | DE89370400440532013000 | -                 | John Doe   | Chargeback AC04      | chargeback | 2026-01-09 |
| 3132 | ES9121000418450200051  | fraud@example.com | -          | Reported fraud       | support    | 2026-01-08 |
+------+------------------------+-------------------+------------+----------------------+------------+------------+
```

---

### 3. Remove Entry (`blacklist:remove`)

Remove an entry from the blacklist. Shows entry details and asks for confirmation.

**By IBAN:**
```bash
docker exec -it tether_app php artisan blacklist:remove --iban=DE89370400440532013000
```

**By ID:**
```bash
docker exec -it tether_app php artisan blacklist:remove --id=123
```

**Interactive mode:**
```bash
docker exec -it tether_app php artisan blacklist:remove
# Will prompt for IBAN or ID
```

**Options:**

| Option | Description |
|--------|-------------|
| `--id` | Remove by database ID |
| `--iban` | Remove by IBAN |

**Note:** Always use `-it` flag for interactive confirmation.

**Example workflow:**
```bash
# 1. Search for entry
docker exec tether_app php artisan blacklist:list --search=DE893

# 2. Remove by ID (from search results)
docker exec -it tether_app php artisan blacklist:remove --id=3133

# Output:
# +--------+------------------------+
# | Field  | Value                  |
# +--------+------------------------+
# | ID     | 3133                   |
# | IBAN   | DE89370400440532013000 |
# | Email  | -                      |
# | Name   | John Doe               |
# | Reason | Chargeback AC04        |
# | Source | chargeback             |
# +--------+------------------------+
#
# Remove this entry? (yes/no) [no]:
```

---

### 4. Export (`blacklist:export`)

Export all blacklist entries to JSON file. Useful for:
- Backup
- Version control
- Syncing between environments

**Default path:**
```bash
docker exec tether_app php artisan blacklist:export
# Exports to: database/data/blacklist.json
```

**Custom path:**
```bash
docker exec tether_app php artisan blacklist:export --path=/tmp/blacklist-backup.json
```

**Options:**

| Option | Description |
|--------|-------------|
| `--path` | Export file path (default: database/data/blacklist.json) |

**Output format:**
```json
[
  {
    "id": 1,
    "iban": "DE89370400440532013000",
    "iban_hash": "a1b2c3...",
    "reason": "Chargeback AC04",
    "source": "chargeback",
    "added_by": null,
    "first_name": "John",
    "last_name": "Doe",
    "email": null,
    "bic": "COBADEFFXXX",
    "created_at": "2026-01-09T10:30:00.000000Z",
    "updated_at": "2026-01-09T10:30:00.000000Z"
  }
]
```

**Commit to version control:**
```bash
docker exec tether_app php artisan blacklist:export
git add database/data/blacklist.json
git commit -m "chore: update blacklist data"
```

---

### 5. Import (`blacklist:import`)

Import blacklist entries from JSON file. Useful for:
- New server setup
- Staging sync
- Disaster recovery

**Dry run (preview only):**
```bash
docker exec tether_app php artisan blacklist:import --dry-run
```

**Actual import:**
```bash
docker exec tether_app php artisan blacklist:import
```

**Custom path:**
```bash
docker exec tether_app php artisan blacklist:import --path=/tmp/blacklist-backup.json
```

**Options:**

| Option | Description |
|--------|-------------|
| `--path` | Import file path (default: database/data/blacklist-seed.json) |
| `--dry-run` | Preview without making changes |

**Deduplication logic:**
- IBAN → unique key (highest priority)
- Email → unique key
- First name + Last name → unique key
- BIC → unique key

Existing entries are updated, new entries are created. No duplicates.

**Example output:**
```
Found 3133 entries in file
DRY RUN - no changes will be made
 3133/3133 [============================] 100%

Import completed:
  Created: 0
  Updated: 3133
  Skipped: 0
```

---

## Common Workflows

### Block IBAN after chargeback
```bash
docker exec tether_app php artisan blacklist:add \
  --iban=ES9121000418450200051332 \
  --reason="Chargeback MD06" \
  --source=chargeback
```

### Check if IBAN is blacklisted
```bash
docker exec tether_app php artisan blacklist:list --search=ES912
```

### Unblock IBAN (customer request)
```bash
# 1. Find the entry
docker exec tether_app php artisan blacklist:list --search=ES912

# 2. Remove it
docker exec -it tether_app php artisan blacklist:remove --iban=ES9121000418450200051332
```

### Backup before major changes
```bash
docker exec tether_app php artisan blacklist:export --path=database/data/blacklist-backup-$(date +%Y%m%d).json
```

### Sync blacklist to staging
```bash
# On production
docker exec tether_app php artisan blacklist:export

# Copy file to staging
scp database/data/blacklist.json staging:/path/to/project/database/data/blacklist-seed.json

# On staging
docker exec tether_app php artisan blacklist:import --dry-run
docker exec tether_app php artisan blacklist:import
```

### Setup new server
```bash
# Copy seed file to new server, then:
docker exec tether_app php artisan blacklist:import
```

---

## Sources Reference

| Source | Description | Typical Usage |
|--------|-------------|---------------|
| `manual` | Added manually by admin | General blocking |
| `support` | Customer support request | Fraud reports |
| `system-auto` | Automatic system action | Validation failures |
| `chargeback` | Chargeback webhook | Auto-blocked after CB |

---

## Database Schema

The `blacklists` table stores all entries:

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| iban | varchar | IBAN (nullable) |
| iban_hash | varchar | SHA256 hash for matching |
| email | varchar | Email (nullable) |
| first_name | varchar | First name (nullable) |
| last_name | varchar | Last name (nullable) |
| bic | varchar | BIC code (nullable) |
| reason | varchar | Reason for blacklisting |
| source | varchar | Source of entry |
| added_by | bigint | User ID who added (nullable) |
| created_at | timestamp | When added |
| updated_at | timestamp | Last update |

---

## Integration

Blacklist is automatically checked during:
1. **File upload** - Debtors matched against blacklist are marked invalid
2. **Billing** - Blacklisted IBANs are excluded from billing
3. **Chargeback webhook** - Auto-adds IBAN to blacklist when CB received

Current blacklist count: **3133 entries** (as of Jan 2026)
