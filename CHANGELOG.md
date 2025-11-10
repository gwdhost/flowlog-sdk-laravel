# Changelog

All notable changes to this project will be documented in this file.

The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this
project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Updated to support Laravel 10, 11, and 12
- Updated README with version-specific middleware installation
  instructions

## [1.0.0] - 2025-11-09

### Added

- Initial release of Flowlog Laravel SDK
- Async logging via Laravel queues
- Automatic context extraction (user, request, route, trace IDs)
- Exception reporting integration
- Configurable query logging
- Configurable HTTP request/response logging
- Job/queue event logging (enabled by default)
- Flowlog facade and helper functions
- Middleware for automatic iteration/trace ID generation
- Comprehensive configuration options
- Unit and feature tests

### Features

- Batched log delivery with configurable batch size and interval
- Automatic retry logic for failed log deliveries
- Exclusion lists for routes and jobs to prevent logging loops
- Support for Laravel 10, 11, and 12
