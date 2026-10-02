SHELL := /bin/bash

.PHONY: lint lint-php lint-twig lint-js lint-css lint-yaml lint-container lint-translations lint-composer build lint-staged hooks-install test

lint: lint-php lint-twig lint-js lint-css lint-yaml lint-container lint-translations lint-composer build

test:
	cd app && vendor/bin/phpunit
	cd app && npx vitest run

lint-php:
	cd app && vendor/bin/phpstan analyse

lint-twig:
	cd app && bin/console lint:twig templates/

lint-js:
	cd app && npx eslint assets/ tests/js/
	cd app && npx tsc --noEmit

lint-css:
	cd app && npx stylelint "assets/styles/**/*.css"

lint-yaml:
	cd app && bin/console lint:yaml config translations

lint-container:
	cd app && bin/console lint:container

lint-translations:
	cd app && bin/console lint:translations --locale=fr --locale=en

lint-composer:
	cd app && composer validate

# The full asset build (QUA-09): an unresolvable import in a .js passes eslint,
# tsc and the vitest suite, and only fails here.
build:
	cd app && npm run build

# Lints only the files touched by the staged commit, bucketed by extension.
# A linter with nothing to check in its bucket is skipped, not run full-repo.
lint-staged:
	@files=$$(git diff --cached --name-only --diff-filter=ACM); \
	php_files=""; twig_files=""; js_files=""; ts_staged=""; css_files=""; \
	for f in $$files; do \
		case "$$f" in \
			app/*.php) php_files="$$php_files $${f#app/}" ;; \
			app/*.twig) twig_files="$$twig_files $${f#app/}" ;; \
			app/*.js) js_files="$$js_files $${f#app/}" ;; \
			app/*.ts) js_files="$$js_files $${f#app/}"; ts_staged=1 ;; \
			app/*.css) css_files="$$css_files $${f#app/}" ;; \
		esac; \
	done; \
	status=0; \
	if [ -n "$$php_files" ]; then \
		echo "==> lint-php (staged)"; \
		(cd app && vendor/bin/phpstan analyse $$php_files) || status=1; \
	fi; \
	if [ -n "$$twig_files" ]; then \
		echo "==> lint-twig (staged)"; \
		(cd app && bin/console lint:twig $$twig_files) || status=1; \
	fi; \
	if [ -n "$$js_files" ]; then \
		echo "==> lint-js (staged)"; \
		(cd app && npx eslint $$js_files) || status=1; \
	fi; \
	if [ -n "$$ts_staged" ]; then \
		echo "==> tsc (whole project)"; \
		(cd app && npx tsc --noEmit) || status=1; \
	fi; \
	if [ -n "$$css_files" ]; then \
		echo "==> lint-css (staged)"; \
		(cd app && npx stylelint $$css_files) || status=1; \
	fi; \
	exit $$status

hooks-install:
	mkdir -p .git/hooks
	cp githooks/pre-commit .git/hooks/pre-commit
	chmod +x .git/hooks/pre-commit
