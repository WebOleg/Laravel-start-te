# Contributing Guide

## Getting Started

### Prerequisites

1. Docker & Docker Compose installed
2. Git configured with SSH key
3. Access to repository

### Setup
```bash
# Clone repository
git clone git@github.com:your-org/tether-laravel.git
cd tether-laravel

# Start environment
make up

# Install dependencies & migrate
make fresh
```

## Development Workflow

### 1. Create Feature Branch
```bash
git checkout main
git pull origin main
git checkout -b feature/your-feature-name
```

### 2. Make Changes

Follow the [Code Style Guide](CODE_STYLE.md).

### 3. Test Your Changes
```bash
# Run all tests
make test

# Run specific test
docker-compose exec app php artisan test --filter=YourTestName
```

### 4. Commit Changes

Follow Conventional Commits format:
```bash
git add .
git commit -m "feat(scope): add new feature"
```

**Commit Types:**

| Type | When to Use |
|------|-------------|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation only |
| `test` | Adding/updating tests |
| `refactor` | Code change that neither fixes nor adds |
| `chore` | Maintenance (deps, config) |

### 5. Push & Create PR
```bash
git push origin feature/your-feature-name
```

Create Pull Request on GitHub with:
- Clear title following commit convention
- Description of changes
- Link to related issue (if any)

## Code Review Process

### Before Requesting Review

- [ ] All tests pass (`make test`)
- [ ] Code follows style guide
- [ ] PHPDoc added for new files
- [ ] No debugging code left
- [ ] Migrations are reversible

### Review Checklist

Reviewers will check:

1. **Functionality**: Does it work as expected?
2. **Tests**: Are there adequate tests?
3. **Code Quality**: Clean, readable, maintainable?
4. **Security**: No exposed secrets, SQL injection, etc.?
5. **Performance**: No N+1 queries, efficient code?

## Creating New Features

### Adding a New Model

1. **Create Migration**
```bash
   docker-compose exec app php artisan make:migration create_table_name_table
```

2. **Create Model**
```bash
   docker-compose exec app php artisan make:model ModelName
```

3. **Create Resource**
```bash
   docker-compose exec app php artisan make:resource ModelNameResource
```

4. **Create Factory**
```bash
   docker-compose exec app php artisan make:factory ModelNameFactory
```

5. **Create Controller** (if needed)
```bash
   docker-compose exec app php artisan make:controller Admin/ModelNameController
```

6. **Create Test**
```bash
   docker-compose exec app php artisan make:test Admin/ModelNameControllerTest
```

7. **Add Route** in `routes/api.php`

### Adding New Endpoint

1. Add route in `routes/api.php`
2. Create/update Controller method
3. Create/update Resource if needed
4. Write Feature Test
5. Update `docs/API.md`

## Testing Guidelines

### What to Test

- **Happy path**: Normal successful operation
- **Authentication**: Unauthorized access returns 401
- **Not found**: Missing resources return 404
- **Filters**: Query parameters work correctly
- **Validation**: Invalid input handled properly

### Test Structure
```php
public function test_action_scenario_expectation(): void
{
    // Arrange - set up test data
    $model = Model::factory()->create();

    // Act - perform action
    $response = $this->getJson('/api/endpoint');

    // Assert - verify result
    $response->assertStatus(200);
}
```

### Running Tests
```bash
# All tests
make test

# Specific file
docker-compose exec app php artisan test --filter=ControllerTest

# Specific method
docker-compose exec app php artisan test --filter=test_method_name

# With coverage
docker-compose exec app php artisan test --coverage
```

## Database Changes

### Creating Migrations
```bash
docker-compose exec app php artisan make:migration add_column_to_table
```

### Migration Best Practices

1. **Always reversible**: Implement `down()` method
2. **Small changes**: One logical change per migration
3. **Default values**: For new required columns on existing tables
4. **Indexes**: Add for frequently queried columns

### Applying Migrations
```bash
# Apply new migrations
make migrate

# Fresh start (destroys data!)
make fresh
```

## Documentation

### When to Update Docs

- New endpoint → Update `docs/API.md`
- Architecture change → Update `docs/ARCHITECTURE.md`
- New convention → Update `docs/CODE_STYLE.md`
- New setup step → Update `README.md`

### Documentation Style

- Use clear, concise language
- Include code examples
- Keep tables for reference data
- Use diagrams for complex flows

## Troubleshooting

### Common Issues

**Tests failing after pull:**
```bash
make fresh
make test
```

**Container not starting:**
```bash
make down
docker-compose build --no-cache
make up
```

**Permission issues:**
```bash
docker-compose exec app chmod -R 777 storage bootstrap/cache
```

### Getting Help

1. Check existing documentation
2. Search closed issues/PRs
3. Ask in team Slack channel
4. Create GitHub issue with details

## Release Process

1. All tests passing on `main`
2. Version bump in appropriate files
3. Update CHANGELOG.md
4. Create GitHub release with tag
5. Deploy to staging → production
