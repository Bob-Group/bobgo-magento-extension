const fs = require('fs');

// Read version from package.json
const packageJson = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const version = packageJson.version;

// Files to update
const filesToUpdate = ['composer.json', 'etc/module.xml'];

// Function to update version in files
function updateVersionInFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf8');

  if (filePath === 'composer.json') {
    // Update the Version header in the plugin file
    // Update the version in composer.json
    content = content.replace(
        /(\"version\":\s*\")([0-9.]+)(\"\,)/g,
        `$1${version}$3`
    );
  }

  if (filePath === 'etc/module.xml') {
    // Update the version tag in etc/module.xml
    content = content.replace(
      /(setup_version=")([^"]*)/g,
      `$1${version}`
    );

  }

  fs.writeFileSync(filePath, content);
}

// Update each file
filesToUpdate.forEach(updateVersionInFile);
