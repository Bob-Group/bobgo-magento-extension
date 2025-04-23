#!/bin/bash

# Exit immediately if a command exits with a non-zero status
#
set -e

# Function to log messages
log() {
    echo "** $1"
}

# Function to install jq if not installed
install_jq() {
    if command -v jq >/dev/null 2>&1; then
        log "jq is already installed."
    else
        log "jq is not installed. Attempting to install..."

        if [[ "$OSTYPE" == "darwin"* ]]; then
            if command -v brew >/dev/null 2>&1; then
                log "Installing jq using Homebrew..."
                brew install jq
            else
                log "Homebrew is not installed. Please install Homebrew first:"
                log "https://brew.sh/"
                exit 1
            fi
        elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
            if command -v apt-get >/dev/null 2>&1; then
                log "Installing jq using apt-get..."
                sudo apt-get update
                sudo apt-get install -y jq
            else
                log "apt-get not found. Please install jq manually."
                exit 1
            fi
        else
            log "Unsupported OS. Please install jq manually."
            exit 1
        fi

        if command -v jq >/dev/null 2>&1; then
            log "jq installed successfully."
        else
            log "Failed to install jq. Please install it manually."
            exit 1
        fi
    fi
}

# Function to ensure Perl is installed
install_perl() {
    if command -v perl >/dev/null 2>&1; then
        log "Perl is already installed."
    else
        log "Perl is not installed. Please install Perl to proceed."
        exit 1
    fi
}

# Function to extract version using jq
get_version() {
    if command -v jq >/dev/null 2>&1; then
        jq -r '.version' package.json
    else
        # Fallback to grep and awk if jq is not available
        grep '"version":' package.json | head -1 | awk -F'"' '{print $4}'
    fi
}

# Function to update the 'Version:' header in bobpay-plugin.php using Perl
update_version_header() {
    local VERSION=$1
    local FILE="composer.json"

    log "Updating 'version:' header in ${FILE} using Perl..."

    # Use Perl to update the 'Version:' line
    perl -pi -e "s/(\"version\":\s*\")([0-9.]+)(\"\,)/\${1}${VERSION}\"\,/g" "$FILE"
    log "'version:' header updated to ${VERSION}."
}

update_define_constant() {
    local VERSION=$1
    local FILE="etc/module.xml"

    log "Updating 'version' constant in ${FILE} using Perl..."

    # Use Perl to replace the version constant
    perl -pi -e "s/(setup_version=\")([^\"]*)/\${1}${VERSION}/g" "$FILE"
    log "'setup_version' constant updated to ${VERSION}."
}

# Function to build the plugin
build_plugin() {
    log "Building plugin..."
    npm install
    log "Plugin built successfully."
}

# Function to create the zip package
create_zip() {
    local VERSION=$1
    local ZIPNAME="bobgo-magento-plugin.zip"
    local PACKAGE_DIR="package"

    log "Creating zip file: ${ZIPNAME}..."

    # Remove any existing package directory and zip file
    rm -rf "${PACKAGE_DIR}"
    rm -f "${ZIPNAME}"

    # Create the package directory
    mkdir -p "${PACKAGE_DIR}"

    # Use rsync to copy files, excluding those in .distignore
    log "Copying files to package directory..."
    rsync -av --exclude-from='.distignore' ./ "${PACKAGE_DIR}/"

    # Create the zip file from the package directory
    log "Creating zip file..."
    cd "${PACKAGE_DIR}"
    zip -r "../${ZIPNAME}" .
    cd ..

    # Clean up the package directory
    log "Cleaning up..."
    rm -rf "${PACKAGE_DIR}"

    log "Zip file '${ZIPNAME}' created successfully."
}

# Main script execution
main() {
    log "Starting build process..."

    install_jq
    install_perl

    VERSION=$(get_version)
    log "Extracted version: ${VERSION}"

    build_plugin
    update_version_header "${VERSION}"
    update_define_constant "${VERSION}"
    create_zip "${VERSION}"

    log "Build process completed successfully."
}

# Execute the main function
main

