$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$pluginSlug = 'knizica-mamateyka'
$pluginDir = Join-Path $projectRoot $pluginSlug
$mainFile = Join-Path $pluginDir "$pluginSlug.php"
$readmeFile = Join-Path $pluginDir 'readme.txt'
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

function New-PluginArchive {
    param(
        [string]$PluginDirectory,
        [string]$ArchivePath
    )

    Add-Type -AssemblyName System.IO.Compression
    $archiveStream = [System.IO.File]::Open($ArchivePath, [System.IO.FileMode]::CreateNew)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new(
            $archiveStream,
            [System.IO.Compression.ZipArchiveMode]::Create
        )
        try {
            $files = @(Get-ChildItem -LiteralPath $PluginDirectory -File -Recurse -Force)
            if ($files.Count -eq 0) {
                throw "No plugin files found in $PluginDirectory."
            }

            foreach ($file in $files) {
                $relativePath = $file.FullName.Substring($PluginDirectory.Length).TrimStart([char[]]@('\', '/'))
                $entryName = "$pluginSlug/$($relativePath.Replace('\', '/'))"
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
}

function Get-ZipEntryText {
    param([System.IO.Compression.ZipArchiveEntry]$Entry)

    $stream = $Entry.Open()
    try {
        $reader = [System.IO.StreamReader]::new($stream)
        try {
            return $reader.ReadToEnd()
        }
        finally {
            $reader.Dispose()
        }
    }
    finally {
        $stream.Dispose()
    }
}

function Assert-ValidPluginPackage {
    param(
        [string]$ZipPath,
        [string]$ExpectedVersion
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $entries = @($archive.Entries | Where-Object { -not [string]::IsNullOrEmpty($_.FullName) })
        if ($entries | Where-Object { $_.FullName.Contains('\') }) {
            throw 'ZIP entries must use forward-slash path separators.'
        }

        $roots = @($entries | ForEach-Object { $_.FullName.Split('/')[0] } | Sort-Object -Unique)
        if ($roots.Count -ne 1 -or $roots[0] -ne $pluginSlug) {
            throw "ZIP must contain exactly one root folder named '$pluginSlug'."
        }

        $mainEntry = $archive.GetEntry("$pluginSlug/$pluginSlug.php")
        $readmeEntry = $archive.GetEntry("$pluginSlug/readme.txt")
        if ($null -eq $mainEntry -or $null -eq $readmeEntry) {
            throw 'ZIP is missing the plugin entry point or readme.txt.'
        }

        $mainText = Get-ZipEntryText $mainEntry
        $readmeText = Get-ZipEntryText $readmeEntry
        if ($mainText -notmatch "(?m)^\s*\*\s*Version:\s*$([regex]::Escape($ExpectedVersion))\s*$" -or
            $mainText -notmatch [regex]::Escape("define('KM_VERSION', '$ExpectedVersion');") -or
            $readmeText -notmatch "(?m)^Stable tag:\s*$([regex]::Escape($ExpectedVersion))\s*$") {
            throw "ZIP version metadata is not synchronized at $ExpectedVersion."
        }
    }
    finally {
        $archive.Dispose()
    }
}

if (-not (Test-Path -LiteralPath $mainFile)) {
    throw "Plugin entry file not found: $mainFile"
}
if (-not (Test-Path -LiteralPath $readmeFile)) {
    throw "Plugin readme not found: $readmeFile"
}

$mainContent = Read-Utf8Text $mainFile
$versionMatch = [regex]::Match($mainContent, '(?m)^\s*\*\s*Version:\s*(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)\s*$')
if (-not $versionMatch.Success) {
    throw 'Could not find a semantic version in the plugin header.'
}

$major = [int]$versionMatch.Groups['major'].Value
$minor = [int]$versionMatch.Groups['minor'].Value + 1
$oldVersion = '{0}.{1}.{2}' -f $major, $versionMatch.Groups['minor'].Value, $versionMatch.Groups['patch'].Value
$newVersion = '{0}.{1}.0' -f $major, $minor

$updatedMain = $mainContent -replace '(?m)(^\s*\*\s*Version:\s*)\d+\.\d+\.\d+', "`${1}$newVersion"
$updatedMain = $updatedMain -replace "(define\(\s*'KM_VERSION',\s*')\d+\.\d+\.\d+('\s*\);)", "`${1}$newVersion`${2}"
if ($updatedMain -notmatch [regex]::Escape("define('KM_VERSION', '$newVersion');")) {
    throw 'Could not update KM_VERSION in the plugin entry file.'
}

$readmeContent = Read-Utf8Text $readmeFile
if ($readmeContent -notmatch '(?m)^Stable tag:\s*\d+\.\d+\.\d+\s*$') {
    throw 'Could not find a semantic Stable tag in readme.txt.'
}
$updatedReadme = $readmeContent -replace '(?m)^(Stable tag:\s*)\d+\.\d+\.\d+', "`${1}$newVersion"

$zipName = "Knizica Mamateyka $newVersion.zip"
if (-not (Test-Path -LiteralPath $releasesDir)) {
    New-Item -ItemType Directory -Path $releasesDir | Out-Null
}
$zipPath = Join-Path $releasesDir $zipName
if (Test-Path -LiteralPath $zipPath) {
    throw "Release already exists; refusing to overwrite it: $zipPath"
}

$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ([guid]::NewGuid().ToString())
$stagingPluginDir = Join-Path $stagingRoot $pluginSlug
$temporaryZip = Join-Path $releasesDir ('.' + [guid]::NewGuid().ToString() + '.tmp.zip')

try {
    New-Item -ItemType Directory -Path $stagingRoot | Out-Null
    Copy-Item -LiteralPath $pluginDir -Destination $stagingRoot -Recurse
    Write-Utf8Text (Join-Path $stagingPluginDir "$pluginSlug.php") $updatedMain
    Write-Utf8Text (Join-Path $stagingPluginDir 'readme.txt') $updatedReadme

    New-PluginArchive -PluginDirectory $stagingPluginDir -ArchivePath $temporaryZip
    Assert-ValidPluginPackage -ZipPath $temporaryZip -ExpectedVersion $newVersion

    Write-Utf8Text $mainFile $updatedMain
    Write-Utf8Text $readmeFile $updatedReadme
    Move-Item -LiteralPath $temporaryZip -Destination $zipPath
}
finally {
    if (Test-Path -LiteralPath $temporaryZip) {
        Remove-Item -LiteralPath $temporaryZip -Force
    }
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}

Write-Host "Version updated: $oldVersion -> $newVersion"
Write-Host "Validated release package: $zipPath"
