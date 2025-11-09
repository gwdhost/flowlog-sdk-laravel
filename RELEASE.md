# Releasing Flowlog Laravel SDK

This guide explains how to release a new version of the Flowlog Laravel SDK package.

## Prerequisites

1. Ensure all tests pass:
   ```bash
   composer test
   ```

2. Update the version in `README.md` if needed

3. Ensure `CHANGELOG.md` is updated (create one if it doesn't exist)

## Release Options

### Option 1: Publish to Packagist (Public)

If you want to publish to [Packagist](https://packagist.org) for public use:

1. **Push to GitHub/GitLab**:
   ```bash
   git add .
   git commit -m "Prepare for release v1.0.0"
   git push origin main
   ```

2. **Create a Git Tag**:
   ```bash
   git tag -a v1.0.0 -m "Release version 1.0.0"
   git push origin v1.0.0
   ```

3. **Submit to Packagist**:
   - Go to https://packagist.org
   - Click "Submit" and enter your repository URL
   - Packagist will automatically detect new tags

4. **Install via Composer**:
   ```bash
   composer require flowlog/flowlog-laravel
   ```

### Option 2: Private Git Repository

If you want to keep it private and use a Git repository:

1. **Push to your Git repository**:
   ```bash
   git add .
   git commit -m "Prepare for release v1.0.0"
   git push origin main
   ```

2. **Create a Git Tag**:
   ```bash
   git tag -a v1.0.0 -m "Release version 1.0.0"
   git push origin v1.0.0
   ```

3. **Add repository to composer.json** in your application:
   ```json
   {
       "repositories": [
           {
               "type": "vcs",
               "url": "https://github.com/your-org/flowlog-laravel"
           }
       ],
       "require": {
           "flowlog/flowlog-laravel": "^1.0"
       }
   }
   ```

### Option 3: Local Path (Development)

For local development, you can use a path repository (as you're currently doing):

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../flowlog-sdks/flowlog-laravel"
        }
    ],
    "require": {
        "flowlog/flowlog-laravel": "*"
    }
}
```

## Versioning

Follow [Semantic Versioning](https://semver.org/):
- **MAJOR** (1.0.0): Breaking changes
- **MINOR** (0.1.0): New features, backward compatible
- **PATCH** (0.0.1): Bug fixes, backward compatible

## Release Checklist

- [ ] All tests pass
- [ ] README.md is up to date
- [ ] CHANGELOG.md is updated
- [ ] Version number is correct
- [ ] Code is committed and pushed
- [ ] Git tag is created and pushed
- [ ] Package is submitted to Packagist (if public)
- [ ] Release notes are published (if using GitHub releases)

## Post-Release

After releasing:

1. Update the version number for the next development cycle
2. Create a new branch for the next version if needed
3. Announce the release (if public)

