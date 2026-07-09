#!/usr/bin/env bash
set -euo pipefail

# Set your fork/branch, then: sudo ./scripts/apply-justcall-patches.sh
GITHUB_REPO="saaslabsco/dd-trace-php"
GITHUB_BRANCH="saaslabspatch"
DD_SOURCES="/opt/datadog/dd-library/0.99.1/dd-trace-sources"

BASE="https://raw.githubusercontent.com/${GITHUB_REPO}/${GITHUB_BRANCH}"

fetch() {
    curl -fsSL "${BASE}/$1" -o "$2"
}

mkdir -p "${DD_SOURCES}/src/Integrations/Integrations/Mysql"

fetch bridge/custom_init.php "${DD_SOURCES}/bridge/custom_init.php"
fetch bridge/w3c_curl_inject.php "${DD_SOURCES}/bridge/w3c_curl_inject.php"
fetch src/Integrations/Integrations/Curl/CurlIntegration.php \
    "${DD_SOURCES}/src/Integrations/Integrations/Curl/CurlIntegration.php"
fetch src/Integrations/Integrations/Mysql/MysqlCommon.php \
    "${DD_SOURCES}/src/Integrations/Integrations/Mysql/MysqlCommon.php"
fetch src/Integrations/Integrations/Mysql/MysqlIntegration.php \
    "${DD_SOURCES}/src/Integrations/Integrations/Mysql/MysqlIntegration.php"
fetch src/Integrations/Integrations/IntegrationsLoader.php \
    "${DD_SOURCES}/src/Integrations/Integrations/IntegrationsLoader.php"
fetch bridge/_files_integrations.php \
    "${DD_SOURCES}/bridge/_files_integrations.php"
fetch bridge/_files_integrations.PHP5.php \
    "${DD_SOURCES}/bridge/_files_integrations.PHP5.php"

echo "Done. Patches copied to ${DD_SOURCES}"
