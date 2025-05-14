#!/bin/bash

# Configuration
WORKING_DIR="/home/bitnami/scripts"
CONFIG_FILE="$WORKING_DIR/config.cfg"
LOCK_FILE="/tmp/release_script.lock"
LOG_FILE="$WORKING_DIR/release_script.log"

# Ensure the script runs from the specified working directory
cd "$WORKING_DIR" || exit 1

# Load configuration
if [[ -f "$CONFIG_FILE" ]]; then
  source "$CONFIG_FILE"
else
  echo "Config file not found: $CONFIG_FILE" | tee -a "$LOG_FILE"
  exit 1
fi

# Functions
log() {
  local message=$1
  echo "$(date '+%Y-%m-%d %H:%M:%S') - $message" | tee -a "$LOG_FILE"
}

acquire_lock() {
  if [[ -e "$LOCK_FILE" ]]; then
    log "Script is already running."
    exit 1
  fi
  touch "$LOCK_FILE"
}

release_lock() {
  rm -f "$LOCK_FILE"
}

fetch_latest_version() {
   aws s3 ls "s3://$S3_BUCKET/" --region=$S3_REGION| sort | tail -n 1 | awk '{print $4}'
}

get_md5() {
  local file=$1
  md5sum "$file" | awk '{print $1}'
}

check_dependencies() {
  if ! command -v aws &>/dev/null; then
    log "AWS CLI is not installed. Please install it and configure it."
    exit 1
  fi
  if ! command -v jq &>/dev/null; then
    log "jq is not installed. Please install it."
    exit 1
  fi

  if ! command sudo $MAGENTO_CLI &>/dev/null; then 
    log "$MAGENTO_CLI not found. Update the binary path in $CONFIG_FILE"
    exit 1
  fi
}

extract_and_apply_release() {
  local release_file=$1
  
  sudo rm -rf $PLUGIN_DIR/* 
  sudo rm -rf /bitnami/magento/generated/*

  # Ensure the plugin directory exists
  if [[ ! -d "$PLUGIN_DIR" ]]; then
    mkdir -p "$PLUGIN_DIR"
  fi

  # Extract the new version to the plugin directory
  log "Extracting $release_file to $PLUGIN_DIR."
  
  if sudo unzip -o "$release_file" -d "$PLUGIN_DIR"; then
    log "Extraction successful."
  else
    log "Extraction failed."
    release_lock
    exit 1
  fi

  log "Clearing Plugin $PLUGIN_DIR directory"
  log "Backup Magento code."
  sudo $MAGENTO_CLI setup:backup --code

  log "Executing Magento commands."
  apply_release
}

apply_release() {
  sudo chown -R bitnami: $PLUGIN_DIR
  sudo $MAGENTO_CLI module:enable BobGroup_BobGo --clear-static-content
  sudo $MAGENTO_CLI cache:clean
  sudo $MAGENTO_CLI cache:flush
  sudo $MAGENTO_CLI setup:upgrade
  sudo $MAGENTO_CLI setup:di:compile
  sudo rm -rf "$MAGENTO_CACHE/*"  
}

main() {
  # Check dependencies
  check_dependencies

  # Acquire lock
  acquire_lock

  # Ensure the local directory exists
  mkdir -p "$LOCAL_DIR"

  # Determine the latest version on S3
  latest_s3_file=$(fetch_latest_version)
  if [[ -z "$latest_s3_file" ]]; then
    log "No files found in the S3 bucket with the $S3_PREFIX naming convention."
    release_lock
    exit 1
  fi
  latest_s3_file_path="s3://$S3_BUCKET/$latest_s3_file"

  # Determine the local version
  local_file="$LOCAL_DIR/$latest_s3_file"
  if [[ ! -f "$local_file" ]]; then
    # Download the file
    log "Fetching $latest_s3_file from S3."
    if aws s3 cp "$latest_s3_file_path" "$LOCAL_DIR/"; then
      log "File downloaded successfully."
      extract_and_apply_release "$local_file"
    else
      log "Failed to download the file from S3."
    fi
  else
    # Compare the MD5 hashes
    local_md5=$(get_md5 "$local_file")
    s3_md5=$(aws s3api head-object --bucket "$S3_BUCKET" --key "$latest_s3_file" --query "ETag" --output text | tr -d '"')

    if [[ "$local_md5" != "$s3_md5" ]]; then
      log "New version found, removing old file and fetching $latest_s3_file from S3."
      rm -f "$local_file"
      if aws s3 cp "$latest_s3_file_path" "$LOCAL_DIR/"; then
        log "New version downloaded successfully."
        extract_and_apply_release "$local_file"
      else
        log "Failed to download the new version from S3."
      fi
    else
      log "No new version found. Local file is up to date."
    fi
  fi

  # Release lock
  release_lock
}

# Run the main function
main
# fetch_latest_version
#apply_release
