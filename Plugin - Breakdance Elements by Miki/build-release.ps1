#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$pluginSlug = 'breakdance-elements-by-miki'
$pluginDir = Join-Path $projectRoot $pluginSlug
$releasesDir = Join-Path $projectRoot 'Releases'
$pluginFile = Join-Path $pluginDir "$pluginSlug.php"
$readmeFile = Join-Path $pluginDir 'readme.txt'
$utf8NoBom = [System.Text.UTF8Encoding]::new($false)

if (-not (Test-Path -LiteralPath $pluginFile)) {
    throw "Plugin entry file not found: $pluginFile"
}
if (-not (Test-Path -LiteralPath $readmeFile)) {
    throw "Plugin readme not found: $readmeFile"
}

if (-not (Test-Path -LiteralPath $releasesDir)) {
    New-Item -ItemType Directory -Path $releasesDir | Out-Null
}

$pluginText = [System.IO.File]::ReadAllText($pluginFile, [System.Text.Encoding]::UTF8)
$versionMatch = [regex]::Match($pluginText, '(?m)^[ \t]*\*[ \t]*Version:[ \t]*(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)[ \t]*\r?$')
if (-not $versionMatch.Success) {
    throw "Could not read plugin version from $pluginFile"
}

$readmeText = [System.IO.File]::ReadAllText($readmeFile, [System.Text.Encoding]::UTF8)
$stableTagMatch = [regex]::Match($readmeText, '(?m)^Stable tag:[ \t]*(?<version>\d+\.\d+\.\d+)[ \t]*\r?$')
if (-not $stableTagMatch.Success) {
    throw "Could not read Stable tag from $readmeFile"
}

$currentVersion = '{0}.{1}.{2}' -f $versionMatch.Groups['major'].Value, $versionMatch.Groups['minor'].Value, $versionMatch.Groups['patch'].Value
if ($stableTagMatch.Groups['version'].Value -ne $currentVersion) {
    throw "Plugin Version ($currentVersion) and readme Stable tag ($($stableTagMatch.Groups['version'].Value)) do not match."
}

$major = [int]$versionMatch.Groups['major'].Value
$minor = [int]$versionMatch.Groups['minor'].Value
$minor++
$patch = 0
$version = '{0}.{1}.{2}' -f $major, $minor, $patch
$archivePath = Join-Path $releasesDir "Breakdance Elements by Miki $version.zip"
while (Test-Path -LiteralPath $archivePath) {
    $minor++
    $version = '{0}.{1}.{2}' -f $major, $minor, $patch
    $archivePath = Join-Path $releasesDir "Breakdance Elements by Miki $version.zip"
}

$updatedPluginText = $pluginText -replace '(?m)^([ \t]*\*[ \t]*Version:[ \t]*)\d+\.\d+\.\d+([ \t]*)(\r?)$', "`${1}$version`$2`$3"
$updatedReadmeText = $readmeText -replace '(?m)^(Stable tag:[ \t]*)\d+\.\d+\.\d+([ \t]*)(\r?)$', "`${1}$version`$2`$3"
$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ([guid]::NewGuid().ToString())
$stagingPluginDir = Join-Path $stagingRoot $pluginSlug
$temporaryZip = Join-Path $releasesDir ('.' + [guid]::NewGuid().ToString() + '.tmp.zip')
$sourceFilesUpdated = $false

try {
    New-Item -ItemType Directory -Path $stagingRoot | Out-Null
    Copy-Item -LiteralPath $pluginDir -Destination $stagingRoot -Recurse
    [System.IO.File]::WriteAllText((Join-Path $stagingPluginDir "$pluginSlug.php"), $updatedPluginText, $utf8NoBom)
    [System.IO.File]::WriteAllText((Join-Path $stagingPluginDir 'readme.txt'), $updatedReadmeText, $utf8NoBom)

    Compress-Archive -Path $stagingPluginDir -DestinationPath $temporaryZip -CompressionLevel Optimal

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($temporaryZip)
    try {
        $entries = @($archive.Entries | Where-Object { -not [string]::IsNullOrEmpty($_.FullName) })
        $requiredEntries = @(
            "$pluginSlug/$pluginSlug.php",
            "$pluginSlug/readme.txt",
            "$pluginSlug/includes/i18n.php",
            "$pluginSlug/languages/sk_SK.php",
            "$pluginSlug/elements/index.php",
            "$pluginSlug/macros/index.php",
            "$pluginSlug/presets/index.php"
        )
        foreach ($elementDir in Get-ChildItem -LiteralPath (Join-Path $pluginDir 'elements') -Directory) {
            foreach ($file in 'element.php', 'html.twig', 'css.twig', 'default.css') {
                $requiredEntries += "$pluginSlug/elements/$($elementDir.Name)/$file"
            }
        }
        foreach ($requiredEntry in $requiredEntries) {
            if (-not ($entries | Where-Object { $_.FullName -eq $requiredEntry })) {
                throw "Release ZIP is missing '$requiredEntry'."
            }
        }
        $badEntries = @($entries | Where-Object { $_.FullName -like '*\*' -or -not $_.FullName.StartsWith("$pluginSlug/") })
        if ($badEntries.Count -gt 0) {
            throw "Release ZIP has entries outside '$pluginSlug/' or with backslashes: $($badEntries[0].FullName)"
        }
    }
    finally {
        $archive.Dispose()
    }

    $sourceFilesUpdated = $true
    [System.IO.File]::WriteAllText($pluginFile, $updatedPluginText, $utf8NoBom)
    [System.IO.File]::WriteAllText($readmeFile, $updatedReadmeText, $utf8NoBom)
    Move-Item -LiteralPath $temporaryZip -Destination $archivePath
}
catch {
    if ($sourceFilesUpdated) {
        [System.IO.File]::WriteAllText($pluginFile, $pluginText, $utf8NoBom)
        [System.IO.File]::WriteAllText($readmeFile, $readmeText, $utf8NoBom)
    }
    throw
}
finally {
    if (Test-Path -LiteralPath $temporaryZip) {
        Remove-Item -LiteralPath $temporaryZip -Force
    }
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}

Write-Host "Version updated: $($versionMatch.Groups['major'].Value).$($versionMatch.Groups['minor'].Value).$($versionMatch.Groups['patch'].Value) -> $version"
Write-Host "Created and validated: $archivePath"