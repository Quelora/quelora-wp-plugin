# filepath: Makefile

# =============================================================================
# Quelora Integration — Build & Release Makefile
# =============================================================================
SHELL := /bin/bash

# --- Project files -----------------------------------------------------------
PHP_FILE  := quelora.php
PKG_FILE  := package.json
DIST_DIR  := dist

# --- Version extraction (reads from package.json as single source of truth) --
CURRENT_VERSION := $(shell node -p "require('./$(PKG_FILE)').version" 2>/dev/null || echo "0.0.0")

# Split into parts for arithmetic bumping
VERSION_MAJOR := $(shell echo "$(CURRENT_VERSION)" | cut -d. -f1)
VERSION_MINOR := $(shell echo "$(CURRENT_VERSION)" | cut -d. -f2)
VERSION_PATCH := $(shell echo "$(CURRENT_VERSION)" | cut -d. -f3)

# The name field in package.json determines the zip filename produced by wp-scripts
PKG_NAME  := $(shell node -p "require('./$(PKG_FILE)').name" 2>/dev/null || echo "quelora-wp-integration")
ZIP_SRC   := $(PKG_NAME).zip
ZIP_DEST  := $(DIST_DIR)/$(PKG_NAME)-$(CURRENT_VERSION).zip

# =============================================================================
# DEFAULT TARGET
# =============================================================================

.DEFAULT_GOAL := help

# =============================================================================
# DEPENDENCY & BUILD TARGETS
# =============================================================================

.PHONY: install
install: ## Install npm dependencies
	@printf "\033[0;36m▶ Installing dependencies...\033[0m\n"
	npm install
	@printf "\033[0;32m✔ Dependencies installed.\033[0m\n"

.PHONY: build
build: ## Run production build (JS bundle + i18n .mo compilation)
	@printf "\033[0;36m▶ Building plugin v$(CURRENT_VERSION)...\033[0m\n"
	npm run build
	@printf "\033[0;32m✔ Build complete.\033[0m\n"

.PHONY: zip
zip: ## Package the plugin and place the versioned .zip in ./dist
	@printf "\033[0;36m▶ Packaging plugin v$(CURRENT_VERSION)...\033[0m\n"
	npm run plugin-zip
	@mkdir -p $(DIST_DIR)
	@mv -f $(ZIP_SRC) $(ZIP_DEST)
	@printf "\033[0;32m✔ Plugin zip saved to: $(ZIP_DEST)\033[0m\n"

.PHONY: release
release: build zip ## Full production release: build + zip
	@printf "\033[0;32m✔ Release v$(CURRENT_VERSION) ready → $(ZIP_DEST)\033[0m\n"

.PHONY: clean
clean: ## Remove build artifacts (build/ directory)
	@printf "\033[0;33m▶ Cleaning build artifacts...\033[0m\n"
	rm -rf build
	@printf "\033[0;32m✔ Clean complete.\033[0m\n"

.PHONY: clean-dist
clean-dist: ## Remove all previously generated zips from ./dist
	@printf "\033[0;33m▶ Cleaning dist directory...\033[0m\n"
	rm -rf $(DIST_DIR)
	@printf "\033[0;32m✔ Dist directory removed.\033[0m\n"

.PHONY: clean-all
clean-all: clean clean-dist ## Remove build/ and dist/ directories
	@printf "\033[0;32m✔ Full clean complete.\033[0m\n"

# =============================================================================
# VERSION MANAGEMENT
# =============================================================================

.PHONY: version
version: ## Print the current plugin version
	@echo "$(CURRENT_VERSION)"

.PHONY: version-patch
version-patch: ## Bump patch version: 2.0.0 → 2.0.1
	$(eval NEW_PATCH   := $(shell echo $$(($(VERSION_PATCH) + 1))))
	$(eval NEW_VERSION := $(VERSION_MAJOR).$(VERSION_MINOR).$(NEW_PATCH))
	@$(MAKE) --no-print-directory _apply-version NEW_VERSION=$(NEW_VERSION)

.PHONY: version-minor
version-minor: ## Bump minor version: 2.0.0 → 2.1.0
	$(eval NEW_MINOR   := $(shell echo $$(($(VERSION_MINOR) + 1))))
	$(eval NEW_VERSION := $(VERSION_MAJOR).$(NEW_MINOR).0)
	@$(MAKE) --no-print-directory _apply-version NEW_VERSION=$(NEW_VERSION)

.PHONY: version-major
version-major: ## Bump major version: 2.0.0 → 3.0.0
	$(eval NEW_MAJOR   := $(shell echo $$(($(VERSION_MAJOR) + 1))))
	$(eval NEW_VERSION := $(NEW_MAJOR).0.0)
	@$(MAKE) --no-print-directory _apply-version NEW_VERSION=$(NEW_VERSION)

# --- Internal: writes the new version to both package.json and quelora.php ---
.PHONY: _apply-version
_apply-version:
	@printf "\033[0;36m▶ Bumping version: $(CURRENT_VERSION) → $(NEW_VERSION)\033[0m\n"
	@node -e "\
		const fs = require('fs'); \
		const pkg = JSON.parse(fs.readFileSync('$(PKG_FILE)', 'utf8')); \
		pkg.version = '$(NEW_VERSION)'; \
		fs.writeFileSync('$(PKG_FILE)', JSON.stringify(pkg, null, 2) + '\n'); \
	"
	@sed -i "s/^\( \* Version:\s*\).*/\1$(NEW_VERSION)/" $(PHP_FILE)
	@sed -i "s/^\(define( 'QUELORA_VERSION', '\).*' );/\1$(NEW_VERSION)' );/" $(PHP_FILE)
	@printf "\033[0;32m✔ Version updated to $(NEW_VERSION) in $(PKG_FILE) and $(PHP_FILE).\033[0m\n"

# =============================================================================
# COMBINED BUMP + RELEASE SHORTCUTS
# =============================================================================

.PHONY: bump-patch
bump-patch: version-patch release ## Bump patch version and run a full release

.PHONY: bump-minor
bump-minor: version-minor release ## Bump minor version and run a full release

.PHONY: bump-major
bump-major: version-major release ## Bump major version and run a full release

# =============================================================================
# HELP
# =============================================================================

.PHONY: help
help: ## Show all available targets
	@printf "\n"
	@printf "  \033[0;36mQuelora Integration — Makefile\033[0m\n"
	@printf "  Current version: \033[0;32m$(CURRENT_VERSION)\033[0m\n"
	@printf "  Versioned zip:   \033[0;32m$(ZIP_DEST)\033[0m\n"
	@printf "\n"
	@printf "  Usage: make [target]\n"
	@printf "\n"
	@grep -E '^[a-zA-Z][a-zA-Z0-9_-]*:.*##' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*##"}; { printf "  \033[0;36m%-18s\033[0m %s\n", $$1, $$2 }'
	@printf "\n"