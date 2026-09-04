#!/bin/sh
# Installs the .NET/Java build+analysis toolchain (.NET SDK, Roslynator, Temurin JDK,
# Maven, Gradle, SpotBugs + Find Security Bugs, Opengrep) shared by the
# static-analysis-collector and ops targets of docker/Dockerfile. Every version pin
# lives here — the single source of truth an environment variable can override, though
# in practice both callers just take the defaults.
#
# PATH/JAVA_HOME cannot be set from here: they must be image ENV, set by the caller
# after this script runs (see docker/Dockerfile).
set -eu

# Roslynator.DotNet.Cli (the NuGet package providing the `roslynator` CLI) has its own
# independent version scheme, separate from the main dotnet/roslynator repo's 4.x tags
# — do not confuse the two. 0.13.1 is the latest published version (confirmed against
# NuGet's own API) and is required, not optional: 0.12.0 cannot analyze a solution
# under .NET 10 SDK at all (System.MissingMethodException from its bundled MSBuild
# assemblies — see issue #368), so this is a compatibility fix, not just a currency bump.
: "${DOTNET_CHANNEL:=10.0}"
: "${ROSLYNATOR_VERSION:=0.13.1}"
: "${SPOTBUGS_VERSION:=4.10.3}"
: "${SPOTBUGS_SHA256:=53c03a77da9746ed0c17aae6c0a9419a12ddeb8bf61dd7209a2e417550afd01d}"
: "${FINDSECBUGS_VERSION:=1.14.0}"
: "${MAVEN_VERSION:=3.9.16}"
: "${GRADLE_VERSION:=9.7.0}"
# Opengrep (https://github.com/opengrep/opengrep) — LGPL-2.1 community fork of Semgrep, a
# single self-contained static binary, no Python runtime, native SARIF output. Analyzes
# C#/Java/JavaScript/TypeScript source directly, no build required.
: "${OPENGREP_VERSION:=1.27.1}"
: "${OPENGREP_SHA256:=58053da76672bbeb5b0a5441021c58338707052e10f81d777140ca879bd491ce}"
: "${OPENGREP_RULES_REF:=f1d2b562b414783763fd02a6ed2736eaed622efa}"

# --- .NET SDK — Microsoft's official install script (served over HTTPS from
# Microsoft's own CDN), not the packages.microsoft.com apt repo: that repo's signing
# key currently fails Debian trixie's sequoia-based apt signature policy (its
# self-signature predates SHA-256 re-certification and is rejected as "SHA1 is not
# considered secure since 2026-02-01") — an upstream Microsoft key-hygiene issue
# outside this repo's control, not something to work around by weakening apt's
# signature verification. The install script is Microsoft's own documented approach
# for container/CI use and avoids the apt/gpg trust chain entirely.
curl -fsSL https://dot.net/v1/dotnet-install.sh -o /tmp/dotnet-install.sh
chmod +x /tmp/dotnet-install.sh
/tmp/dotnet-install.sh --channel "${DOTNET_CHANNEL}" --install-dir /usr/share/dotnet
rm /tmp/dotnet-install.sh
ln -s /usr/share/dotnet/dotnet /usr/local/bin/dotnet

# --- Roslynator CLI (shared tool path, accessible to all users) ---
dotnet tool install \
    --version "${ROSLYNATOR_VERSION}" \
    --tool-path /usr/local/dotnet-tools \
    roslynator.dotnet.cli

# --- Java (Eclipse Temurin JDK, current LTS — signed Adoptium apt repo) ---
curl -fsSL https://packages.adoptium.net/artifactory/api/gpg/key/public \
    | gpg --dearmor -o /usr/share/keyrings/adoptium.gpg
echo "deb [signed-by=/usr/share/keyrings/adoptium.gpg] https://packages.adoptium.net/artifactory/deb $(lsb_release -sc) main" \
    > /etc/apt/sources.list.d/adoptium.list
apt-get update && apt-get install -y --no-install-recommends temurin-25-jdk
rm -rf /var/lib/apt/lists/*

# Fixed, architecture-independent path to the JDK just installed — the real Adoptium
# apt package path is architecture-specific (e.g. .../temurin-25-jdk-amd64 vs -arm64),
# so this symlink lets the caller's JAVA_HOME stay a single value regardless of build
# host architecture.
ln -s "$(dirname "$(dirname "$(readlink -f "$(which javac)")")")" /opt/java-home

# --- Maven + Gradle (build Java repos ahead of SpotBugs, which needs compiled .class
# files, not source) — installed from the official Apache/Gradle release archives with
# a verified checksum. Repos that ship their own wrapper (mvnw/gradlew) use that
# instead; these are the fallback for repos that don't. ---
wget -q "https://downloads.apache.org/maven/maven-3/${MAVEN_VERSION}/binaries/apache-maven-${MAVEN_VERSION}-bin.tar.gz" \
    -O /tmp/maven.tar.gz
MAVEN_SHA512=$(curl -fsSL \
    "https://downloads.apache.org/maven/maven-3/${MAVEN_VERSION}/binaries/apache-maven-${MAVEN_VERSION}-bin.tar.gz.sha512" \
    | awk '{print $1}')
echo "${MAVEN_SHA512}  /tmp/maven.tar.gz" | sha512sum -c -
mkdir -p /opt/maven
tar -xzf /tmp/maven.tar.gz -C /opt/maven --strip-components=1
rm /tmp/maven.tar.gz
ln -s /opt/maven/bin/mvn /usr/local/bin/mvn

wget -q "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip" \
    -O /tmp/gradle.zip
GRADLE_SHA256=$(curl -fsSL \
    "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip.sha256" \
    | awk '{print $1}')
echo "${GRADLE_SHA256}  /tmp/gradle.zip" | sha256sum -c -
mkdir -p /opt/gradle-extract
unzip -q /tmp/gradle.zip -d /opt/gradle-extract
mv "/opt/gradle-extract/gradle-${GRADLE_VERSION}" /opt/gradle
rm -rf /tmp/gradle.zip /opt/gradle-extract
ln -s /opt/gradle/bin/gradle /usr/local/bin/gradle

# --- SpotBugs + Find Security Bugs plugin (checksum- and signature-verified) ---
# SHA256 published at https://github.com/spotbugs/spotbugs/releases/tag/4.10.3
wget -q \
    "https://github.com/spotbugs/spotbugs/releases/download/${SPOTBUGS_VERSION}/spotbugs-${SPOTBUGS_VERSION}.tgz" \
    -O /tmp/spotbugs.tgz
echo "${SPOTBUGS_SHA256}  /tmp/spotbugs.tgz" | sha256sum -c -
mkdir -p /opt/spotbugs
tar -xzf /tmp/spotbugs.tgz -C /opt/spotbugs --strip-components=1
rm /tmp/spotbugs.tgz
ln -s /opt/spotbugs/bin/spotbugs /usr/local/bin/spotbugs
mkdir -p /opt/spotbugs-plugins
wget -q \
    "https://repo1.maven.org/maven2/com/h3xstream/findsecbugs/findsecbugs-plugin/${FINDSECBUGS_VERSION}/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar" \
    -O "/opt/spotbugs-plugins/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar"
FINDSECBUGS_SHA1=$(curl -fsSL \
    "https://repo1.maven.org/maven2/com/h3xstream/findsecbugs/findsecbugs-plugin/${FINDSECBUGS_VERSION}/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar.sha1")
echo "${FINDSECBUGS_SHA1}  /opt/spotbugs-plugins/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar" | sha1sum -c -
wget -q \
    "https://repo1.maven.org/maven2/com/h3xstream/findsecbugs/findsecbugs-plugin/${FINDSECBUGS_VERSION}/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar.asc" \
    -O /tmp/findsecbugs.jar.asc
gpg --keyserver hkps://keyserver.ubuntu.com \
    --recv-keys CFC10B69382CBCF5387E51484ECE492B63E38ACF
gpg --verify /tmp/findsecbugs.jar.asc "/opt/spotbugs-plugins/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar"
rm /tmp/findsecbugs.jar.asc

# --- Opengrep binary + rules ---
# opengrep_manylinux_x86 is the exact asset name opengrep's own install.sh resolves for
# a glibc-based Linux x86_64 host (as opposed to the musllinux/osx/windows/aarch64
# variants also published on the same release). Opengrep publishes cosign signatures
# (.cert/.sig) for each binary but no plain sha256sums file, so this checksum was
# computed directly from the binary at OPENGREP_VERSION, downloaded from this exact
# release URL over HTTPS.
#
# Only csharp/java/javascript/typescript are vendored from opengrep-rules — generic/
# (secret detection) is deliberately excluded, since Trivy already covers secrets in
# the repository-collection pipeline.
#
# Only the *.yaml/*.yml rule definitions are kept from those directories — opengrep's
# -f directory loader ignores everything else, and the accompanying *.test.* fixtures
# are intentionally-vulnerable example code (the thing each rule is meant to catch).
# Deleting them here, rather than never fetching them, is the actual fix for corporate
# TLS-inspecting proxies that flag them in transit: they are gone from the image and
# from this build's own filesystem before the layer is committed, so nothing later in
# the pipeline (a registry scanner, a later `docker save`/push) ever sees them either.
wget -q \
    "https://github.com/opengrep/opengrep/releases/download/v${OPENGREP_VERSION}/opengrep_manylinux_x86" \
    -O /usr/local/bin/opengrep
echo "${OPENGREP_SHA256}  /usr/local/bin/opengrep" | sha256sum -c -
chmod +x /usr/local/bin/opengrep
opengrep --version
wget -q \
    "https://github.com/opengrep/opengrep-rules/archive/${OPENGREP_RULES_REF}.tar.gz" \
    -O /tmp/opengrep-rules.tar.gz
mkdir -p /tmp/opengrep-rules-extract /opt/opengrep-rules
tar -xzf /tmp/opengrep-rules.tar.gz -C /tmp/opengrep-rules-extract --strip-components=1
cp -r /tmp/opengrep-rules-extract/csharp /tmp/opengrep-rules-extract/java \
    /tmp/opengrep-rules-extract/javascript /tmp/opengrep-rules-extract/typescript \
    /opt/opengrep-rules/
find /opt/opengrep-rules -type f ! -name '*.yaml' ! -name '*.yml' -delete
rm -rf /tmp/opengrep-rules.tar.gz /tmp/opengrep-rules-extract
