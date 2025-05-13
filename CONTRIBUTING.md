# Contributing to Composer Repository Generator

Thank you for considering contributing to the Composer Repository Generator! This document provides guidelines and instructions to help you contribute effectively.

## Table of Contents

1. [Development Environment Setup](#development-environment-setup)
2. [Coding Standards](#coding-standards)
3. [Testing](#testing)
4. [Pull Request Process](#pull-request-process)
5. [Issue Reporting Guidelines](#issue-reporting-guidelines)
6. [Security Vulnerabilities](#security-vulnerabilities)
7. [Development Workflow](#development-workflow)

## Development Environment Setup

### Prerequisites

- PHP 8.3 or higher
- Composer 2.0 or higher
- Git

### Setup Steps

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/composer-repository-generator.git
   cd composer-repository-generator
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Set up Git remote for upstream:
   ```bash
   git remote add upstream https://github.com/petebishop/composer-repository-generator.git
   ```

5. Create a branch for your work:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Coding Standards

This project follows [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/) and uses PHP strict typing.

Key points to remember:

- Use strict typing with `declare(strict_types=1);` at the top of all PHP files
- Add appropriate PHPDoc blocks for all classes, methods, and properties
- Use type hints and return type declarations
- Use constructor property promotion where appropriate
- Follow the code organization already present in the repository

### Code Style Tools

The project uses PHP CS Fixer to maintain code standards. Run it before submitting your code:

```bash
composer cs-check  # Check for issues
composer cs-fix    # Fix issues automatically
```

## Testing

### Writing Tests

- All new features should include unit tests
- Tests should be written using PHPUnit
- Place tests in the `/tests` directory following the same namespace structure as the main code
- Tests should cover both normal use cases and edge cases, including error handling

### Running Tests

```bash
composer test             # Run all tests
composer test-coverage    # Run tests with coverage report
```

## Pull Request Process

1. Update your fork with the latest changes from the upstream repository:
   ```bash
   git fetch upstream
   git rebase upstream/main
   ```

2. Make your changes and ensure they meet the following criteria:
   - Follow the coding standards
   - Include appropriate tests
   - Update documentation if needed
   - All tests pass

3. Commit your changes with a clear and descriptive commit message:
   ```bash
   git commit -m "Add feature: description of the changes"
   ```

4. Push to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```

5. Create a Pull Request (PR) through the GitHub interface with:
   - A clear title and description
   - Reference to any related issues (e.g., "Fixes #123")
   - A list of changes made

6. Respond to any feedback on your PR from maintainers

## Issue Reporting Guidelines

### Before Submitting an Issue

- Check if the issue already exists in the GitHub issues
- Ensure you're using the latest version of the package
- Try to reproduce the issue in a clean environment

### Issue Template

When submitting an issue, please include:

1. **Description**: Clear and concise description of the issue
2. **Steps to Reproduce**: Detailed steps to reproduce the behavior
3. **Expected Behavior**: Description of what you expected to happen
4. **Actual Behavior**: Description of what actually happened
5. **Environment**:
   - PHP version
   - Composer version
   - Operating system
   - Package version
6. **Additional Context**: Any other relevant information, logs, or screenshots

## Security Vulnerabilities

If you discover a security vulnerability, please do NOT open an issue. Instead, email [your-email@example.com](mailto:your-email@example.com) with details. Security vulnerabilities will be addressed promptly.

## Development Workflow

### Branching Strategy

- `main` - Contains the stable release code
- `develop` - Integration branch for features and bug fixes
- `feature/*` - New features and enhancements
- `bugfix/*` - Bug fixes
- `release/*` - Preparation for a new release
- `hotfix/*` - Urgent fixes for production

### Release Process

1. Features and bug fixes are merged into `develop`
2. When ready for release, a release branch is created from `develop`
3. After testing and finalization, the release branch is merged into `main`
4. A tag is created with the new version number following [Semantic Versioning](https://semver.org/)
5. Changes are then merged back into `develop`

### Versioning

This project follows [Semantic Versioning](https://semver.org/) (SemVer):

- **MAJOR** version when making incompatible API changes
- **MINOR** version when adding functionality in a backward-compatible manner
- **PATCH** version when making backward-compatible bug fixes

## Tools Used in Development

- **Composer** - Dependency management
- **PHPUnit** - Testing framework
- **PHP CS Fixer** - Code style enforcement
- **PHPStan** - Static analysis
- **GitHub Actions** - CI/CD pipeline

---

Thank you for contributing to make Composer Repository Generator better!

