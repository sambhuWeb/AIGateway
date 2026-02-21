# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AIGateway is a PHP Composer package that provides a unified interface for connecting to AI chat services (OpenAI, Anthropic, and extensible to other providers). It accepts configuration parameters (temperature, model, max_tokens, API key) and returns responses in JSON format.

**Status**: This project is being scaffolded. Reference the existing `/Users/sambhu/projects/sambhuWeb/Translator` repository for architectural patterns—it follows the same structure with DTOs, service interfaces, and provider-specific implementations.

## Build Commands

```bash
# Install dependencies
composer install

# Run all tests
composer run-script test
# or
vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/path/to/TestFile.php

# Run tests with bootstrap
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

## Architecture

Follow the Translator package pattern:

- **Interface-driven design**: Core `AIConnector` interface that all providers (OpenAI, Anthropic) implement
- **DTOs**: Input DTOs for request configuration (`AIRequestDTO`) and output DTOs for responses (`AIResponseDTO`)
- **Provider implementations**: Separate classes per provider under `src/AI/{Provider}/`
- **HTTP Client**: Shared `GuzzleHTTPClient` wrapper for API calls
- **PSR-4 autoloading**: Namespace `AIGateway\` maps to `src/`

### Expected Directory Structure

```
src/
├── AI/
│   ├── AIConnector.php           # Interface
│   ├── OpenAI/
│   │   └── OpenAIConnector.php
│   ├── Anthropic/
│   │   └── AnthropicConnector.php
│   └── DTO/
│       ├── Input/
│       │   └── AIRequestDTO.php
│       └── Output/
│           └── AIResponseDTO.php
├── Client/
│   └── GuzzleHTTPClient.php
└── Exception/
    └── AIGatewayException.php
tests/
```

## Key Features to Implement

- Caching layer for similar queries with `fresh` parameter to bypass cache
- Support for multiple AI providers through a common interface
- JSON input/output format
- Configurable model, temperature, max_tokens per request
