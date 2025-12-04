# Code Style Guide

## Overview

This project follows PSR-12 coding standards with additional conventions for consistency and maintainability.

## PHP Standards

### PSR Compliance

- **PSR-1**: Basic Coding Standard
- **PSR-4**: Autoloading Standard
- **PSR-12**: Extended Coding Style

### File Structure

Every PHP file must follow this structure:
```php
<?php

/**
 * Brief description of the file purpose.
 */

namespace App\Namespace;

use External\Classes;
use App\Internal\Classes;

class ClassName
{
    // Constants first
    public const STATUS_ACTIVE = 'active';
    
    // Properties
    private string $property;
    
    // Methods
    public function method(): void
    {
        //
    }
}
```

## PHPDoc Standards

### File Header

Every file MUST have a brief description comment:
```php
<?php

/**
 * Handles user authentication and token management.
 */

namespace App\Http\Controllers;
```

### Class Documentation
```php
/**
 * Manages debtor records and their lifecycle.
 *
 * This model handles IBAN masking, status transitions,
 * and relationships to VOP logs and billing attempts.
 */
class Debtor extends Model
{
}
```

### Method Documentation

Document methods when:
- Return type is not obvious
- Parameters need explanation
- Method has side effects
```php
/**
 * Calculate success rate as percentage.
 *
 * @return float Percentage between 0 and 100
 */
public function getSuccessRateAttribute(): float
{
    // ...
}
```

### When NOT to Document

Skip PHPDoc when code is self-explanatory:
```php
// Good - no docs needed
public function isPending(): bool
{
    return $this->status === self::STATUS_PENDING;
}

// Good - no docs needed for simple accessors
public function getFullNameAttribute(): string
{
    return trim("{$this->first_name} {$this->last_name}");
}
```

### Test Methods

Test methods are self-documenting through naming:
```php
// Good - name explains what it tests
public function test_index_requires_authentication(): void

// Good - descriptive test name
public function test_show_returns_masked_iban(): void
```

## Naming Conventions

### Classes

| Type | Convention | Example |
|------|------------|---------|
| Models | Singular, PascalCase | `Debtor`, `VopLog` |
| Controllers | PascalCase + Controller | `DebtorController` |
| Resources | PascalCase + Resource | `DebtorResource` |
| Factories | PascalCase + Factory | `DebtorFactory` |
| Tests | PascalCase + Test | `DebtorControllerTest` |

### Methods

| Type | Convention | Example |
|------|------------|---------|
| Actions | camelCase verb | `store()`, `update()` |
| Accessors | get{Name}Attribute | `getFullNameAttribute()` |
| Mutators | set{Name}Attribute | `setIbanAttribute()` |
| Booleans | is/has/can prefix | `isPending()`, `canRetry()` |
| Relationships | camelCase noun | `debtors()`, `latestVopLog()` |

### Variables
```php
// Good
$debtor = Debtor::find($id);
$vopLogs = $debtor->vopLogs;
$isVerified = $vopLog->isVerified();

// Bad
$d = Debtor::find($id);
$data = $debtor->vopLogs;
$flag = $vopLog->isVerified();
```

### Constants
```php
// Good - uppercase with underscores
public const STATUS_PENDING = 'pending';
public const RISK_HIGH = 'high';

// Bad
public const statusPending = 'pending';
public const Status_Pending = 'pending';
```

## Laravel Conventions

### Models
```php
class Debtor extends Model
{
    // 1. Traits
    use HasFactory, SoftDeletes;

    // 2. Constants
    public const STATUS_PENDING = 'pending';

    // 3. Properties
    protected $fillable = ['first_name', 'last_name'];
    protected $hidden = ['iban'];
    protected $casts = ['amount' => 'decimal:2'];

    // 4. Relationships
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    // 5. Accessors & Mutators
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // 6. Helper Methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
```

### Controllers
```php
class DebtorController extends Controller
{
    // List - always return collection
    public function index(Request $request): AnonymousResourceCollection
    {
        $debtors = Debtor::query()
            ->when($request->has('status'), fn($q) => $q->where('status', $request->input('status')))
            ->paginate();

        return DebtorResource::collection($debtors);
    }

    // Single - always return resource
    public function show(Debtor $debtor): DebtorResource
    {
        return new DebtorResource($debtor);
    }
}
```

### Resources
```php
class DebtorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // IDs first
            'id' => $this->id,
            'upload_id' => $this->upload_id,

            // Main data
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,

            // Sensitive data masked
            'iban_masked' => $this->masked_iban,

            // Monetary values
            'amount' => (float) $this->amount,
            'currency' => $this->currency,

            // Status
            'status' => $this->status,

            // Timestamps last
            'created_at' => $this->created_at?->toISOString(),

            // Conditional relations
            'upload' => new UploadResource($this->whenLoaded('upload')),
        ];
    }
}
```

## Database Conventions

### Migrations
```php
// Table names: plural, snake_case
Schema::create('billing_attempts', function (Blueprint $table) {
    // Primary key first
    $table->id();

    // Foreign keys
    $table->foreignId('debtor_id')->constrained()->cascadeOnDelete();

    // Data columns alphabetically
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3)->default('EUR');
    $table->string('status')->default('pending');

    // Timestamps last
    $table->timestamps();
    $table->softDeletes();

    // Indexes
    $table->index(['status', 'created_at']);
});
```

### Column Naming

| Type | Convention | Example |
|------|------------|---------|
| Foreign key | {table}_id | `debtor_id` |
| Boolean | is_{name} | `is_verified` |
| Timestamp | {action}_at | `processed_at` |
| JSON | {name} (no suffix) | `meta`, `payload` |

## Testing Conventions

### File Organization
```
tests/
└── Feature/
    └── Admin/
        ├── UploadControllerTest.php
        ├── DebtorControllerTest.php
        ├── VopLogControllerTest.php
        └── BillingAttemptControllerTest.php
```

### Test Structure
```php
class DebtorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Common setup
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_index_returns_debtors_list(): void
    {
        // Arrange
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        // Act
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors');

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
```

### Test Naming
```php
// Pattern: test_{method}_{scenario}_{expected_result}

public function test_index_returns_debtors_list(): void
public function test_index_requires_authentication(): void
public function test_index_filters_by_status(): void
public function test_show_returns_404_for_nonexistent_debtor(): void
public function test_show_returns_masked_iban(): void
```

## Git Conventions

### Commit Messages

Follow Conventional Commits:
```
type(scope): description

[optional body]
```

**Types:**

| Type | Description |
|------|-------------|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation |
| `test` | Adding tests |
| `refactor` | Code refactoring |
| `chore` | Maintenance tasks |

**Examples:**
```
feat(admin): add Admin controllers and API routes
fix(auth): add HasApiTokens trait to User model
test(admin): add feature tests for Admin API endpoints
docs: add API and architecture documentation
chore: add Makefile for development commands
```

### Branch Naming
```
feature/add-admin-panel
fix/iban-masking
docs/api-documentation
```

## IDE Setup

### PHPStorm / VS Code

Recommended extensions:
- PHP Intelephense
- Laravel Idea (PHPStorm)
- Laravel Blade Snippets
- EditorConfig

### .editorconfig
```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.php]
indent_style = space
indent_size = 4

[*.{js,json,yml,yaml}]
indent_style = space
indent_size = 2
```
