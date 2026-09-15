# Project Overview

This is a project focused on giving users lesser known indie game recommendations. Users authenticate via steam, so we can pull their library and compare their preferences against the indie games. It's still a WIP, but its core technologies are PostgreSQL for user data, and eventually vector database for recommendation. Core stack is PHP 8.5 + Laravel 13 (migrated from Kotlin/Spring on 2026-09-15).

## Project Rules

### Rule #1: Always use TDD
Whenever working on a new feature or bugfix, always implement the tests first which will initially fail and only be fixed once the implementation is done.

### Rule #2: Prefer Integration tests over Unit tests
Whenever writing a test, prefer integration tests that actually execute dependent code. If you're fixing func1, and func1 calls func2 which creates entities and fire messages, assert that the related entities/messages/whatever that should execute related to the fix was actually executed.

### Rule #3: 1-sentence class and method descriptions
Write a 1-sentence description on top of all classes and methods: Write a succinct but thorough summary of what a class or a method does right above its definition.

### Rule #4: Document non-trivial hacks
Document all non-trivial hacks or monkey-patches directly in code if scattered among multiple files. Always reference the other related files.

### Rule #5: Documentation
Write end-user documentation to docs/user_documentation.md. Write a public API documentation into docs/api_documentation.md, make it production-ready so that it can be directly shared with customers; don’t include internal information there. Write a high-level internal documentation about the implementation into docs/internal_documentation.md. Never write any local memories. You must include the memories in one of these documentation markdown files instead. If needed, create additional files in the docs/#{topic}.md directory and add them to git. These are just some examples. As of the time this is being written, no public api exists yet.

### Rule #6: Simplicity
Keep things simple. Don't overcomplicate the code. Write simple, human-readable, concise code. Whenever getting to a solution, think: "could this be solved in a simpler way?". Only scenarios where the complexity is required are allowed to actually be complex. Use ponytail skill.

### Rule #7: E2E Feature testing
Whenever finishing implementing a feature, test it E2E either with a browser (if applicable) or via API request. Don't just test the happy path, test unhappy paths and one or two edge case (if applicable).

### Rule #8: Gracefully Fail
We should always prevent random 500's. A good error handling module should catch all errors, and always account for them.

### Rule #9: The "what if this breaks" rule
When implementing, always assume the worst. If you're writing a method that fires a message and adds to the DB, think: "what if the system dies during after sending the message, and the data was not commited?". This is a possible scenario for CDC, so always think of worst case scenarios, point them out, and propose solutions if required.

### Rule #10: Keeping this file updated
Whenever I tell you that you did something wrong, I might explicitly tell you to update this file. If so, you will update the Notes section. 

## Routing Table
## Notes
- Stack: PHP 8.5 + Laravel 13 + PHPUnit 12. Run the suite with `php artisan test`; it fakes every
  Steam call and uses in-memory SQLite, so it needs neither Docker nor an API key.
- Postgres runs via `docker compose up -d` on localhost:5432. Only the running app needs it.
- Migrated from Kotlin/Spring on 2026-09-15. The Spring Modulith rule was dropped with it: module
  boundaries are convention now, not enforced by a test. See docs/internal_documentation.md.
- Never write the Steam API key anywhere but `.env`.
