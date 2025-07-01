# Task Manager API - Architecture Documentation

## Overview

This directory contains comprehensive documentation of all architectural decisions made in the Task Manager API project. The documentation covers every major decision, including:

- Technology stack choices (PHP 8.2, MySQL 8.0, Redis 7)
- Architecture patterns (Layered Architecture, Repository Pattern, Strategy Pattern)
- Authentication & Security (JWT, Bcrypt, Multi-layer security)
- Database design (Normalization, Indexing, Views)
- Caching strategies (Multi-layer caching, Cache invalidation)
- API design (RESTful principles, Pagination)
- Testing strategies (Unit, Integration, BDD)
- Performance optimizations
- Error handling & Logging
- Deployment & Infrastructure

## Files

- **`ARCHITECTURAL_DECISIONS.md`** - The complete architecture documentation in Markdown format
- **`generate-architecture-pdf.sh`** - Script to generate PDF and HTML versions
- **`docs/pdf/`** - Generated PDF and HTML files (after running the script)

## Viewing the Documentation

### Option 1: Read the Markdown File
You can read the documentation directly in the `ARCHITECTURAL_DECISIONS.md` file. Most code editors and GitHub will render it nicely.

### Option 2: Generate PDF (Recommended)

#### Prerequisites
You need `pandoc` and a LaTeX distribution installed:

**macOS:**
```bash
brew install pandoc
brew install --cask mactex-no-gui
```

**Ubuntu/Debian:**
```bash
sudo apt-get update
sudo apt-get install pandoc texlive-latex-base texlive-fonts-recommended texlive-latex-extra
```

**Windows:**
- Install pandoc from https://pandoc.org/installing.html
- Install MiKTeX from https://miktex.org/download

#### Generate the PDF
```bash
# Make the script executable (first time only)
chmod +x generate-architecture-pdf.sh

# Generate the PDF
./generate-architecture-pdf.sh
```

The PDF will be generated at: `docs/pdf/Task_Manager_API_Architecture_Decisions.pdf`

### Option 3: Generate HTML
The script also generates an HTML version that can be viewed in any web browser:
```bash
./generate-architecture-pdf.sh
# Open docs/pdf/Task_Manager_API_Architecture_Decisions.html in your browser
```

### Option 4: Manual PDF Generation
If the script doesn't work, you can manually generate the PDF:
```bash
# Simple PDF
pandoc ARCHITECTURAL_DECISIONS.md -o architecture.pdf

# With table of contents
pandoc ARCHITECTURAL_DECISIONS.md -o architecture.pdf --toc --toc-depth=2

# With nice formatting
pandoc ARCHITECTURAL_DECISIONS.md -o architecture.pdf \
    --toc \
    --highlight-style=tango \
    --variable fontsize=11pt \
    --variable geometry:margin=1in
```

## Document Structure

The architecture documentation is organized into the following sections:

1. **Executive Summary** - High-level overview and key principles
2. **Technology Stack Decisions** - Programming language, database, caching choices
3. **Architecture Pattern Decisions** - Design patterns and architectural styles
4. **Authentication & Security Decisions** - Security implementation choices
5. **Database Design Decisions** - Schema design and optimization strategies
6. **Caching Strategy Decisions** - Cache architecture and invalidation
7. **API Design Decisions** - REST principles and endpoint design
8. **Testing Strategy Decisions** - Test types and coverage approach
9. **Performance Optimization Decisions** - Performance improvements
10. **Error Handling & Logging Decisions** - Error management approach
11. **Deployment & Infrastructure Decisions** - Containerization and deployment

Each decision includes:
- **The Decision** - What was chosen
- **Rationale** - Why it was chosen
- **Pros** - Benefits of the approach
- **Cons** - Drawbacks and limitations
- **Alternatives Considered** - Other options evaluated and why they weren't chosen

## Key Insights

### Technology Choices
- **PHP 8.2** chosen for maturity, ecosystem, and team expertise
- **MySQL 8.0** for ACID compliance and relational data modeling
- **Redis 7** for high-performance caching and rate limiting

### Architecture Principles
- **Layered Architecture** for clear separation of concerns
- **Repository Pattern** for database abstraction
- **Strategy Pattern** for extensible filtering system

### Security Approach
- **JWT Authentication** for stateless, scalable auth
- **Defense in Depth** with multiple security layers
- **Bcrypt** for secure password hashing

### Performance Strategy
- **Multi-layer Caching** to minimize database load
- **Strategic Indexing** for optimal query performance
- **Connection Pooling** for resource efficiency

## Contributing

When making architectural changes:
1. Update the `ARCHITECTURAL_DECISIONS.md` file
2. Document the decision, rationale, pros/cons, and alternatives
3. Regenerate the PDF for distribution
4. Commit both the markdown and generated files

## Questions?

For questions about specific architectural decisions, refer to the relevant section in the documentation. Each decision includes detailed rationale and trade-off analysis. 