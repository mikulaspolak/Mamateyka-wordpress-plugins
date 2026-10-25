Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$pluginSlug = 'farske-oznamy'
$pluginDir = Join-Path $projectRoot $pluginSlug
$pluginFile = Join-Path $pluginDir "$pluginSlug.php"
$releasesDir = Join-Path $projectRoot 'Releases'
$utf8NoBom = [System.Text.UTF8Encoding]::new($false)

function Read-Utf8Text {
    param([string]$Path)
    return [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
}

function Write-Utf8Text {
    param([string]$Path, [string]$Content)
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
}

# Only these paths are ever packaged - new dev/tooling files can never leak into the plugin zip.
$packageIncludes = @('uninstall.php', 'admin', 'assets', 'includes', "$pluginSlug.php")
$disallowedEntryPatterns = @('*.zip', '.git/*', '.vscode/*', '.idea/*', 'scripts/*', 'build-release.ps1')

function Assert-ValidPluginPackage {
    param(
        [string]$ZipPath,
        [string]$ExpectedRootName,
        [string]$ExpectedVersion
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        foreach ($entry in $archive.Entries) {
            if ($entry.FullName.Contains('\')) {
                throw "ZIP entry uses backslash path separator (breaks Linux/PHP ZipArchive extraction): '$($entry.FullName)'."
            }

            foreach ($pattern in $disallowedEntryPatterns) {
                if ($entry.FullName -like "*/$pattern" -or $entry.FullName -like $pattern) {
                    throw "ZIP contains a disallowed dev-only path: '$($entry.FullName)'."
                }
            }
        }
    }
    finally {
        $archive.Dispose()
    }

    $tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ([System.Guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null

    try {
        Expand-Archive -Path $ZipPath -DestinationPath $tempRoot -Force

        $rootEntries = @(Get-ChildItem -Path $tempRoot -Force)
        if ($rootEntries.Count -ne 1) {
            throw "ZIP must contain exactly one root folder, found $($rootEntries.Count)."
        }

        if ($rootEntries[0].Name -ne $ExpectedRootName) {
            throw "ZIP root folder must be '$ExpectedRootName', found '$($rootEntries[0].Name)'."
        }

        $pluginMainFile = Join-Path $tempRoot (Join-Path $ExpectedRootName "$ExpectedRootName.php")
        if (-not (Test-Path $pluginMainFile)) {
            throw "ZIP is missing '$ExpectedRootName/$ExpectedRootName.php'."
        }

        $mainFileContent = Get-Content -Path $pluginMainFile -Raw
        if ($mainFileContent -notmatch "\* Version:\s+$([regex]::Escape($ExpectedVersion))\s") {
            throw "Packaged '$ExpectedRootName.php' does not contain expected 'Version: $ExpectedVersion' header."
        }
        if ($mainFileContent -notmatch [regex]::Escape("define('OZNAMY_VERSION', '$ExpectedVersion');")) {
            throw "Packaged '$ExpectedRootName.php' does not contain expected OZNAMY_VERSION define for $ExpectedVersion."
        }
        if ($mainFileContent -notmatch [regex]::Escape("define('OZNAMY_DB_VERSION', '$ExpectedVersion');")) {
            throw "Packaged '$ExpectedRootName.php' does not contain expected OZNAMY_DB_VERSION define for $ExpectedVersion."
        }
    }
    finally {
        if (Test-Path $tempRoot) {
            Remove-Item -Path $tempRoot -Recurse -Force
        }
    }
}

if (-not (Test-Path $pluginFile)) {
    throw "Plugin file not found: $pluginFile"
}

$content = Read-Utf8Text $pluginFile

$versionMatch = [regex]::Match($content, "define\('OZNAMY_VERSION',\s*'(?<version>\d+\.\d+\.\d+)'\);")
if (-not $versionMatch.Success) {
    throw "Could not find OZNAMY_VERSION in $pluginSlug.php."
}

$currentVersion = $versionMatch.Groups['version'].Value
$parts = $currentVersion.Split('.')
if ($parts.Count -ne 3) {
    throw "Unexpected semantic version format: $currentVersion"
}

$major = [int] $parts[0]
$minor = [int] $parts[1]
$nextVersion = '{0}.{1}.0' -f $major, ($minor + 1)
$releaseNote = 'Release package generated.'

$updatedContent = $content
$updatedContent = [regex]::Replace(
    $updatedContent,
    '(^ \* Version:\s+).+$',
    [System.Text.RegularExpressions.MatchEvaluator] {
        param($match)
        $match.Groups[1].Value + $nextVersion
    },
    [System.Text.RegularExpressions.RegexOptions]::Multiline
)
$updatedContent = [regex]::Replace($updatedContent, "define\('OZNAMY_VERSION',\s*'\d+\.\d+\.\d+'\);", "define('OZNAMY_VERSION', '$nextVersion');", 1)
$updatedContent = [regex]::Replace($updatedContent, "define\('OZNAMY_DB_VERSION',\s*'\d+\.\d+\.\d+'\);", "define('OZNAMY_DB_VERSION', '$nextVersion');", 1)

$changelogAnchor = ' * Changelog:'
$changelogLine = " *   $nextVersion - $releaseNote"
if ($updatedContent.Contains($changelogLine)) {
    throw "Changelog already contains version $nextVersion."
}

if (-not $updatedContent.Contains($changelogAnchor)) {
    throw "Could not find changelog section in $pluginSlug.php."
}

$updatedContent = $updatedContent.Replace($changelogAnchor, "$changelogAnchor`r`n$changelogLine")

if ($updatedContent -eq $content) {
    throw 'Version file was not updated. Expected markers may have changed.'
}

if (-not ($updatedContent.Contains("* Version:     $nextVersion") -and $updatedContent.Contains("define('OZNAMY_VERSION', '$nextVersion');") -and $updatedContent.Contains("define('OZNAMY_DB_VERSION', '$nextVersion');"))) {
    throw 'Version values are not fully synchronized after update.'
}

$releaseName = "Farske oznamy $nextVersion.zip"
if (-not (Test-Path -LiteralPath $releasesDir)) {
    New-Item -ItemType Directory -Path $releasesDir | Out-Null
}
$versionedZipPath = Join-Path $releasesDir $releaseName
if (Test-Path -LiteralPath $versionedZipPath) {
    throw "Release already exists; refusing to overwrite it: $versionedZipPath"
}

$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ([System.Guid]::NewGuid().ToString())
$stagingPluginDir = Join-Path $stagingRoot $pluginSlug
$temporaryZipPath = Join-Path $releasesDir ('.' + [guid]::NewGuid().ToString() + '.tmp.zip')

try {
    New-Item -ItemType Directory -Path $stagingPluginDir -Force | Out-Null

    foreach ($item in $packageIncludes) {
        $sourcePath = Join-Path $pluginDir $item
        if (-not (Test-Path $sourcePath)) {
            throw "Expected plugin file/folder not found: $sourcePath"
        }
        Copy-Item -Path $sourcePath -Destination $stagingPluginDir -Recurse -Force
    }

    if (-not (Test-Path (Join-Path $stagingPluginDir "$pluginSlug.php"))) {
        throw "Expected plugin entry file not found in staging directory: $stagingPluginDir"
    }

    Write-Utf8Text (Join-Path $stagingPluginDir "$pluginSlug.php") $updatedContent

    Add-Type -AssemblyName System.IO.Compression
    $archiveStream = [System.IO.File]::Open($temporaryZipPath, [System.IO.FileMode]::CreateNew)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new(
            $archiveStream,
            [System.IO.Compression.ZipArchiveMode]::Create
        )
        try {
            $files = Get-ChildItem -Path $stagingPluginDir -File -Recurse
            foreach ($file in $files) {
                $relativePath = $file.FullName.Substring($stagingRoot.Length).TrimStart([char[]]@('\', '/'))
                $entryName = $relativePath.Replace('\', '/')
                $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)

                $entryStream = $entry.Open()
                try {
                    $sourceStream = [System.IO.File]::OpenRead($file.FullName)
                    try {
                        $sourceStream.CopyTo($entryStream)
                    }
                    finally {
                        $sourceStream.Dispose()
                    }
                }
                finally {
                    $entryStream.Dispose()
                }
            }
        }
        finally {
            $archive.Dispose()
        }
    }
    finally {
        $archiveStream.Dispose()
    }

    Assert-ValidPluginPackage -ZipPath $temporaryZipPath -ExpectedRootName $pluginSlug -ExpectedVersion $nextVersion
    Write-Utf8Text $pluginFile $updatedContent
    Move-Item -LiteralPath $temporaryZipPath -Destination $versionedZipPath
}
finally {
    if (Test-Path -LiteralPath $temporaryZipPath) {
        Remove-Item -LiteralPath $temporaryZipPath -Force
    }
    if (Test-Path $stagingRoot) {
        Remove-Item -Path $stagingRoot -Recurse -Force
    }
}

Write-Host "Version updated: $currentVersion -> $nextVersion"
Write-Host "Versioned archive: $versionedZipPath"
Write-Host "Archive root: $pluginSlug"
Write-Host "Archive main file: $pluginSlug/$pluginSlug.php"
Write-Host ''
Write-Host 'Note: WordPress detects updates by the internal folder/file path, not the uploaded ZIP filename.'
Write-Host 'Uploading this ZIP over an existing install with the same folder name will offer "Replace current with uploaded".'
