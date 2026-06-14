#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Command line parameter variables
PARAM_PROJECT_NAME=""
PARAM_PRODUCTION_URL=""
PARAM_DEV_URL=""
PARAM_GITHUB_OWNER=""
PARAM_AUTO_DETECT=false

# Detected values (from existing files or git)
DETECTED_PROJECT_NAME=""
DETECTED_GITHUB_OWNER=""
DETECTED_PRODUCTION_URL=""
VALUES_ALREADY_FILLED=false

# Function to print colored output
print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# =============================================================================
# LEVEL 1: Check if values are already filled in template files
# =============================================================================
detect_filled_values() {
    print_info "Checking for existing configuration..."

    # Check if compose.yml has placeholders or actual values
    if [ -f "compose.yml" ]; then
        if grep -q "{{PROJECT_NAME}}" compose.yml 2>/dev/null; then
            # Placeholders still present - need to configure
            print_info "  Template placeholders detected - configuration needed"
            VALUES_ALREADY_FILLED=false
        else
            # No placeholders - extract existing values
            VALUES_ALREADY_FILLED=true

            # Extract project name from container_name: projectname-php
            DETECTED_PROJECT_NAME=$(grep -oP 'container_name:\s*\K[^-\s]+' compose.yml 2>/dev/null | head -1)

            # Fallback: extract from network name
            if [ -z "$DETECTED_PROJECT_NAME" ]; then
                DETECTED_PROJECT_NAME=$(grep -oP 'name:\s*\K[^-\s]+(?=-network)' compose.yml 2>/dev/null | head -1)
            fi
        fi
    fi

    # Check GitHub workflow for owner
    if [ -f ".github/workflows/docker-laravel.yml" ]; then
        if ! grep -q "{{GITHUB_REPOSITORY_OWNER}}" .github/workflows/docker-laravel.yml 2>/dev/null; then
            # Extract existing owner
            local filled_owner
            filled_owner=$(grep -oP 'ghcr\.io/\K[^/]+' .github/workflows/docker-laravel.yml 2>/dev/null | head -1)
            if [ -n "$filled_owner" ]; then
                DETECTED_GITHUB_OWNER="$filled_owner"
            fi
        fi
    fi

    # Check production .env.example for URL
    if [ -f "docker-laravel/production/.env.example" ]; then
        if ! grep -q "{{PRODUCTION_URL}}" docker-laravel/production/.env.example 2>/dev/null; then
            local filled_url
            filled_url=$(grep -oP 'APP_URL=\K.+' docker-laravel/production/.env.example 2>/dev/null | head -1)
            if [ -n "$filled_url" ] && [ "$filled_url" != "http://localhost" ]; then
                DETECTED_PRODUCTION_URL="$filled_url"
            fi
        fi
    fi

    # Report what we found
    if [ "$VALUES_ALREADY_FILLED" = true ]; then
        print_success "Found existing configuration:"
        [ -n "$DETECTED_PROJECT_NAME" ] && echo -e "   Project name:    ${CYAN}$DETECTED_PROJECT_NAME${NC}"
        [ -n "$DETECTED_GITHUB_OWNER" ] && echo -e "   GitHub owner:    ${CYAN}$DETECTED_GITHUB_OWNER${NC}"
        [ -n "$DETECTED_PRODUCTION_URL" ] && echo -e "   Production URL:  ${CYAN}$DETECTED_PRODUCTION_URL${NC}"
    fi
}

# =============================================================================
# LEVEL 2: Detect from git remote (for fresh installs or missing values)
# =============================================================================
detect_from_git() {
    if git rev-parse --git-dir > /dev/null 2>&1; then
        local remote_url git_owner git_repo
        remote_url=$(git remote get-url origin 2>/dev/null)
        if [ -n "$remote_url" ]; then
            # Extract owner and repo from GitHub URL
            git_owner=$(echo "$remote_url" | sed -n 's#.*github\.com[:/]\([^/]*\)/.*#\1#p')
            git_repo=$(echo "$remote_url" | sed -n 's#.*github\.com[:/][^/]*/\([^.]*\).*#\1#p')

            if [ -z "$DETECTED_GITHUB_OWNER" ] && [ -n "$git_owner" ]; then
                DETECTED_GITHUB_OWNER="$git_owner"
                print_info "Auto-detected GitHub owner from git: $DETECTED_GITHUB_OWNER"
            fi
            if [ -z "$DETECTED_PROJECT_NAME" ] && [ -n "$git_repo" ]; then
                DETECTED_PROJECT_NAME="$git_repo"
                print_info "Auto-detected project name from git: $DETECTED_PROJECT_NAME"
            fi
        fi
    fi
}

# Function to validate and prepare directory structure
validate_directory() {
    print_info "Validating directory structure..."

    # Case 1: Check if we're in an existing Laravel project root
    if [ -f "artisan" ]; then
        print_info "Detected existing Laravel project. Moving files to www/ folder..."

        # Create www directory
        mkdir -p www

        # Move all files except www to www/ folder (handle setup files gracefully)
        for item in *; do
            if [ "$item" != "www" ] && [ "$item" != "setup.sh" ] && [ "$item" != "compose.yml.template" ] && [ "$item" != "docker-laravel" ] && [ "$item" != ".env.example" ]; then
                mv "$item" www/
            fi
        done

        # Move hidden files too (like .env, .git, etc.)
        for item in .*; do
            if [ "$item" != "." ] && [ "$item" != ".." ] && [ -e "$item" ]; then
                mv "$item" www/
            fi
        done

        print_success "Laravel project moved to www/ folder"
        return
    fi

    # Case 2: Check if we're in a prepared directory with www/ folder
    if [ -d "www" ]; then
        # Check if Laravel project has required files
        if [ ! -f "www/artisan" ]; then
            print_error "No 'artisan' file found in 'www/'. Are you sure this is a Laravel project?"
            exit 1
        fi

        # Check for unexpected files (allow setup files and www folder)
        visible_files=$(ls -1 2>/dev/null | wc -l)
        if [ "$visible_files" -gt 0 ]; then
            allowed_files="www setup.sh compose.yml compose.override.yaml docker-laravel .env .env.example DOCKER_README.md .github CLAUDE.md deploy Dockerfile.pocketdev .gitignore"
            for file in *; do
                if [ -e "$file" ]; then
                    case " $allowed_files " in
                        *" $file "*) ;;
                        *)
                            print_error "Unexpected file/folder found: $file"
                            print_info "Expected: empty directory with 'www/' folder containing Laravel"
                            exit 1
                            ;;
                    esac
                fi
            done
        fi

        print_success "Directory structure validation passed"
        return
    fi

    # Case 3: Neither Laravel root nor prepared directory
    print_error "Invalid directory structure detected"
    print_info ""
    print_info "This setup script expects one of:"
    print_info "  1. Laravel project root (with 'artisan' file) - will move to www/"
    print_info "  2. Empty directory with 'www/' folder containing Laravel"
    print_info ""
    print_info "Current directory contents:"
    ls -la
    exit 1
}

# Check prerequisites
check_prerequisites() {
    print_info "Checking prerequisites..."

    if ! command_exists docker; then
        print_error "Docker is not installed. Please install Docker first."
        exit 1
    fi

    if ! command_exists docker-compose && ! docker compose version &>/dev/null; then
        print_error "Docker Compose is not installed. Please install Docker Compose first."
        exit 1
    fi

    print_success "Prerequisites check passed"
}

# Function to get user input with default value
get_input() {
    local prompt="$1"
    local default="$2"
    local provided="$3"
    local result

    # If a value was provided via command line, use it
    if [[ -n "$provided" ]]; then
        echo "$provided"
        return
    fi

    # Otherwise, prompt the user
    read -p "$prompt [$default]: " result
    echo "${result:-$default}"
}

# =============================================================================
# Get a config value using priority: CLI param > filled value > git detect > prompt
# =============================================================================
get_config_value() {
    local name="$1"
    local cli_param="$2"
    local filled_value="$3"
    local git_value="$4"
    local default="$5"
    local prompt="$6"

    # Priority 1: CLI parameter (highest)
    if [[ -n "$cli_param" ]]; then
        echo "$cli_param"
        return
    fi

    # Priority 2: Already filled in files (from previous setup)
    if [[ -n "$filled_value" ]]; then
        if [[ "$PARAM_AUTO_DETECT" == true ]]; then
            print_info "Using existing $name: $filled_value"
            echo "$filled_value"
            return
        else
            # Ask user if they want to use existing value
            local use_existing
            use_existing=$(get_input "Use existing $name '$filled_value'? (yes/no)" "yes" "")
            if [[ "$use_existing" =~ ^[Yy]([Ee][Ss])?$ ]]; then
                echo "$filled_value"
                return
            fi
        fi
    fi

    # Priority 3: Auto-detected from git
    if [[ -n "$git_value" ]]; then
        if [[ "$PARAM_AUTO_DETECT" == true ]]; then
            print_info "Using auto-detected $name: $git_value"
            echo "$git_value"
            return
        else
            # Use git value as default in prompt
            default="$git_value"
        fi
    fi

    # Priority 4: Interactive prompt
    get_input "$prompt" "$default" ""
}

# Main setup function
setup_laravel_docker() {
    print_info "🚀 Laravel Docker Setup"
    echo ""

    # Validate directory structure first
    validate_directory

    # Set Laravel directory (always "www" now)
    LARAVEL_DIR="www"

    # Level 1: Check if values are already filled
    detect_filled_values

    # Level 2: Detect from git (for missing values)
    detect_from_git

    echo ""

    # Get project name
    PROJECT_NAME=$(get_config_value \
        "project name" \
        "$PARAM_PROJECT_NAME" \
        "$DETECTED_PROJECT_NAME" \
        "$DETECTED_PROJECT_NAME" \
        "mylaravel" \
        "Enter your project name (lowercase, no spaces)")
    PROJECT_NAME=$(echo "$PROJECT_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]//g')

    # Get production URL
    PRODUCTION_URL=$(get_config_value \
        "production URL" \
        "$PARAM_PRODUCTION_URL" \
        "$DETECTED_PRODUCTION_URL" \
        "" \
        "https://example.com" \
        "Enter production URL (for .env.production)")

    # Get GitHub owner
    GITHUB_REPOSITORY_OWNER=$(get_config_value \
        "GitHub owner" \
        "$PARAM_GITHUB_OWNER" \
        "$DETECTED_GITHUB_OWNER" \
        "$DETECTED_GITHUB_OWNER" \
        "$(whoami)" \
        "Enter GitHub repository owner (for container registry)")

    echo ""
    print_info "Configuration:"
    echo -e "   Project name:    ${CYAN}$PROJECT_NAME${NC}"
    echo -e "   GitHub owner:    ${CYAN}$GITHUB_REPOSITORY_OWNER${NC}"
    echo -e "   Production URL:  ${CYAN}$PRODUCTION_URL${NC}"
    echo ""

    # Process compose.yml template
    print_info "Processing docker-compose.yml..."
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g; s/{{LARAVEL_DIR}}/$LARAVEL_DIR/g" compose.yml
    print_success "docker-compose.yml configured"

    # docker-laravel/shared/nginx/default.conf is now project-agnostic
    # (fastcgi_pass php:9000; — `php` is the compose service name, auto-aliased
    # by Docker on the project's internal network). No substitution needed.

    # Copy and process GitHub Action workflow
    print_info "Setting up GitHub Action workflow..."
    if [ ! -f ".github/workflows/docker-laravel.yml" ]; then
        print_error "GitHub workflow not found (.github/workflows/docker-laravel.yml)"
        print_info ""
        print_info "This usually means the template was not installed correctly."
        print_info "Please use the documented installation method:"
        print_info ""
        print_info "  curl -sSL https://raw.githubusercontent.com/tetrixdev/slim-docker-laravel-setup/main/install.sh | bash"
        print_info ""
        exit 1
    fi
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g; s/{{GITHUB_REPOSITORY_OWNER}}/$GITHUB_REPOSITORY_OWNER/g" .github/workflows/docker-laravel.yml
    print_success "GitHub Action workflow configured"

    # Create environment files
    print_info "Creating .env files..."

    # Update .env.example with project-specific values
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" .env.example

    # Create .env ONLY if it does not exist yet.
    #
    # Re-running setup on an existing project (e.g. after an infrastructure
    # update) must NEVER overwrite .env: it holds the generated APP_KEY and
    # DB_PASSWORD. Losing APP_KEY invalidates all encrypted data and sessions;
    # losing DB_PASSWORD locks the app out of its database. Neither is
    # recoverable. The refreshed .env.example is left in place so any new
    # template keys can be diffed in by hand.
    if [ -f ".env" ]; then
        print_success "Existing .env preserved (APP_KEY and DB_PASSWORD kept)"
        echo -e "   Review new template keys with: ${CYAN}diff .env .env.example${NC}"
    else
        # Create .env from .env.example with generated password and app key
        cp .env.example .env
        sed -i "s/DB_PASSWORD=laravel/DB_PASSWORD=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)/" .env

        # Generate Laravel APP_KEY using openssl (persists across container recreates)
        if ! command_exists openssl; then
            print_error "openssl is required to generate APP_KEY. Please install it and re-run setup."
            exit 1
        fi
        APP_KEY="base64:$(openssl rand -base64 32)"
        sed -i "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" .env
        print_success "Application key generated"

        # Set development APP_URL if provided (for Vite HMR on external devices)
        if [[ -n "$PARAM_DEV_URL" ]]; then
            sed -i "s|APP_URL=http://localhost|APP_URL=$PARAM_DEV_URL|" .env
            print_success "Development APP_URL set to $PARAM_DEV_URL"
        fi
    fi

    # Process docker-laravel/production/.env.example
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" docker-laravel/production/.env.example
    sed -i "s#{{PRODUCTION_URL}}#$PRODUCTION_URL#g" docker-laravel/production/.env.example
    sed -i "s/DB_PASSWORD=laravel/DB_PASSWORD=CHANGE_THIS_PASSWORD/" docker-laravel/production/.env.example

    # Process production deployment README
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g; s/{{GITHUB_REPOSITORY_OWNER}}/$GITHUB_REPOSITORY_OWNER/g" docker-laravel/production/README.md

    # Process Docker README
    sed -i "s/{{PROJECT_NAME}}/$PROJECT_NAME/g" DOCKER_README.md

    print_success "Production deployment package created in docker-laravel/production/"

    # Configure Vite for Docker
    # Replace vite.config.js with Docker-ready template that uses APP_URL for HMR
    if [ -f "$LARAVEL_DIR/vite.config.js" ]; then
        print_info "Configuring Vite for Docker..."
        cp docker-laravel/shared/vite.config.js "$LARAVEL_DIR/vite.config.js"
        print_success "Vite configured for Docker (using APP_URL for HMR)"
    fi

    # Configure trustProxies middleware for reverse proxy support (Laravel 11+)
    # This ensures Laravel recognizes HTTPS when behind proxy-nginx
    local bootstrap_app="$LARAVEL_DIR/bootstrap/app.php"
    if [ -f "$bootstrap_app" ]; then
        if grep -q "trustProxies" "$bootstrap_app"; then
            print_success "trustProxies already configured"
        elif grep -q "withMiddleware" "$bootstrap_app"; then
            print_info "Configuring trustProxies middleware..."
            # Replace empty middleware closure with trustProxies
            sed -i 's/->withMiddleware(function (Middleware $middleware): void {[[:space:]]*\/\/[[:space:]]*})/->withMiddleware(function (Middleware $middleware): void {\n        $middleware->trustProxies(at: '"'"'*'"'"');\n    })/g' "$bootstrap_app"
            # Also handle case where it's on multiple lines
            sed -i ':a;N;$!ba;s/->withMiddleware(function (Middleware $middleware): void {\n[[:space:]]*\/\/\n[[:space:]]*})/->withMiddleware(function (Middleware $middleware): void {\n        $middleware->trustProxies(at: '"'"'*'"'"');\n    })/g' "$bootstrap_app"
            if grep -q "trustProxies" "$bootstrap_app"; then
                print_success "trustProxies configured for reverse proxy support"
            else
                print_warning "Could not auto-configure trustProxies. Add manually to bootstrap/app.php:"
                echo '        $middleware->trustProxies(at: '"'"'*'"'"');'
            fi
        fi
    fi

    # Note: Laravel database configuration is handled via environment variables
    # Note: setup.sh is safe to re-run. install.sh runs it automatically after
    #       an infrastructure update; an existing .env is always preserved.

    echo ""
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}  🎉 Setup completed successfully!${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    echo ""
    print_info "Development:"
    echo "   docker compose up -d"
    echo "   → http://localhost"
    echo ""
    print_info "Production deployment:"
    echo "   1. Create GitHub release to trigger image build"
    echo "   2. Deploy: docker compose -f deploy/compose.yml up -d"
    echo "   → Images: ghcr.io/$GITHUB_REPOSITORY_OWNER/$PROJECT_NAME-php:latest"
    echo ""

    # Git diff reminder
    echo -e "${YELLOW}────────────────────────────────────────────────────────────${NC}"
    print_warning "Review changes with git before committing:"
    echo ""
    echo -e "   ${CYAN}git diff${NC}"
    echo ""
    echo "   Check for any manual customizations that may need to be re-applied."
    echo -e "${YELLOW}────────────────────────────────────────────────────────────${NC}"
    echo ""
}


# Display usage information
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "This script sets up Laravel with Docker (PHP 8.4-FPM + Nginx + PostgreSQL)"
    echo ""
    echo "Options:"
    echo "  -n, --project-name NAME     Set project name (lowercase, no spaces)"
    echo "  -u, --production-url URL    Set production URL for .env.production"
    echo "  -d, --dev-url URL           Set development APP_URL (for Vite HMR on external devices)"
    echo "  -o, --github-owner OWNER    Set GitHub repository owner manually"
    echo "  -a, --auto-detect           Auto-accept all detected values (no prompts)"
    echo "  -h, --help                  Show this help message"
    echo "  --check                     Check prerequisites only"
    echo ""
    echo "Examples:"
    echo "  # Interactive setup (default behavior)"
    echo "  $0"
    echo ""
    echo "  # Fully non-interactive setup"
    echo "  $0 -n myapp -u https://myapp.com -o myusername -a"
    echo ""
    echo "  # Auto-detect all values without prompting"
    echo "  $0 -a"
    echo ""
    echo "  # Mixed: provide some parameters, prompt for others"
    echo "  $0 -n myapp"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -n|--project-name)
            if [[ -n "$2" && "$2" != -* ]]; then
                PARAM_PROJECT_NAME="$2"
                shift 2
            else
                print_error "Option $1 requires a value"
                exit 1
            fi
            ;;
        -u|--production-url)
            if [[ -n "$2" && "$2" != -* ]]; then
                PARAM_PRODUCTION_URL="$2"
                shift 2
            else
                print_error "Option $1 requires a value"
                exit 1
            fi
            ;;
        -d|--dev-url)
            if [[ -n "$2" && "$2" != -* ]]; then
                PARAM_DEV_URL="$2"
                shift 2
            else
                print_error "Option $1 requires a value"
                exit 1
            fi
            ;;
        -o|--github-owner)
            if [[ -n "$2" && "$2" != -* ]]; then
                PARAM_GITHUB_OWNER="$2"
                shift 2
            else
                print_error "Option $1 requires a value"
                exit 1
            fi
            ;;
        -a|--auto-detect)
            PARAM_AUTO_DETECT=true
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        --check)
            check_prerequisites
            exit 0
            ;;
        -*)
            print_error "Unknown option: $1"
            usage
            exit 1
            ;;
        *)
            print_error "Unexpected argument: $1"
            usage
            exit 1
            ;;
    esac
done

# Run the setup
check_prerequisites
setup_laravel_docker
