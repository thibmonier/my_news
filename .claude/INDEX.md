# Claude-Craft Rules Index - PHP

> Condensed reference for PHP 8.5, Composer, PSR Standards, Pest 4, PHPUnit 12

## Architecture Quick Reference

```
src/
├── Domain/           # Business logic
│   ├── Entity/       # Domain entities
│   └── Service/      # Domain services
├── Application/      # Use cases
│   ├── Command/      # Write operations
│   └── Query/        # Read operations
├── Infrastructure/   # External concerns
│   ├── Persistence/  # Database repositories
│   └── Http/         # Controllers, API
└── tests/
```

**Dependency Rule**: Infrastructure -> Application -> Domain (INWARD ONLY)

## Coding Standards

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `UserService` |
| Methods | camelCase | `getUserById` |
| Constants | UPPER_SNAKE | `MAX_RETRIES` |
| Private | camelCase | `\$privateField` |

**Standards**: PSR-1, PSR-4 (autoloading), PSR-12 (coding style).

## Testing Quick Reference

**TDD Cycle**: RED → GREEN → REFACTOR

**PHP Stack**: PHPUnit + Mockery + PHPStan + Psalm

**Coverage Target**: ≥80% | **Key Metrics**: Branch, Statement, Integration

## Security Essentials

- **Input Validation**: ALWAYS validate at boundaries
- **No Secrets in Code**: Use environment variables
- **Parameterized Queries**: Never concatenate SQL
- **OWASP Top 10**: Be aware of common vulnerabilities

## Universal Principles

| Principle | Key Points |
|-----------|------------|
| **SOLID** | Single responsibility, Open/closed, Liskov, Interface segregation, Dependency inversion |
| **KISS** | Keep it simple, avoid over-engineering |
| **DRY** | Don't repeat yourself, extract common logic |
| **YAGNI** | Don't add functionality until needed |

## Full Reference Documentation

### Base (Universal)
- `base/solid-principles.md` - SOLID principles in depth
- `base/kiss-dry-yagni.md` - Simplicity principles
- `base/workflow-analysis.md` - Development workflow
- `base/git-workflow.md` - Git best practices
- `base/documentation.md` - Documentation standards

### PHP Specific
- `php/architecture.md` - Clean Architecture for PHP
- `php/coding-standards.md` - PSR-1, PSR-4, PSR-12
- `php/testing.md` - PHPUnit patterns
- `php/tooling.md` - Composer, PHP-CS-Fixer
- `php/quality-tools.md` - PHPStan, Psalm
- `php/security.md` - OWASP, security best practices
