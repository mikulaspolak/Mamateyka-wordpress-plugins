$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$pluginSlug = 'hero-carousel-mini-plugin'
$pluginDir = Join-Path $projectRoot $pluginSlug
$mainFile = Join-Path $pluginDir "$pluginSlug.php"
$readmeFile = Join-Path $pluginDir 'README.txt'
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
    param([string]$ZipPath, [string]$ExpectedVersion)

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
        if ($null -eq $mainEntry) {
            throw "ZIP is missing '$pluginSlug/$pluginSlug.php'."
        }

        $mainText = Get-ZipEntryText $mainEntry
        if ($mainText -notmatch "(?m)^\s*\*\s*Version:\s*$([regex]::Escape($ExpectedVersion))\s*$" -or
            $mainText -notmatch [regex]::Escape("define('HCM_PLUGIN_VERSION', '$ExpectedVersion');")) {
            throw "ZIP version metadata is not synchronized at $ExpectedVersion."
        }
    }
    finally {
        $archive.Dispose()
    }
}

$php = Read-Utf8Text $mainFile
if ($php -notmatch '(?m)^\s*\*\s*Version:\s*(\d+)\.(\d+)\.(\d+)\s*$') {
    throw "Could not find plugin version in header of $mainFile"
}

$major = [int]$Matches[1]
$oldVersion = "$($Matches[1]).$($Matches[2]).$($Matches[3])"
$newVersion = '{0}.{1}.0' -f $major, ([int]$Matches[2] + 1)
$updatedPhp = $php -replace '(?m)^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+\s*$', "`${1}$newVersion"
$updatedPhp = $updatedPhp -replace "(define\('HCM_PLUGIN_VERSION',\s*')\d+\.\d+\.\d+('\);)", "`${1}$newVersion`${2}"
if ($updatedPhp -notmatch [regex]::Escape("define('HCM_PLUGIN_VERSION', '$newVersion');")) {
    throw 'Could not update HCM_PLUGIN_VERSION in the plugin entry file.'
}

$readme = Read-Utf8Text $readmeFile
if ($readme -notmatch '(?m)^Release Notes:\s*\r?\n') {
    throw "Could not find the Release Notes section in $readmeFile"
}
$note = "- $newVersion`r`n`t- version bump to $newVersion"
$updatedReadme = $readme -replace '(?m)^(Release Notes:\s*\r?\n)', "`${1}$note`r`n"

$zipName = "Hero carousel mini plugin $newVersion.zip"
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
    Write-Utf8Text (Join-Path $stagingPluginDir "$pluginSlug.php") $updatedPhp
    Write-Utf8Text (Join-Path $stagingPluginDir 'README.txt') $updatedReadme

    New-PluginArchive -PluginDirectory $stagingPluginDir -ArchivePath $temporaryZip
    Assert-ValidPluginPackage -ZipPath $temporaryZip -ExpectedVersion $newVersion

    Write-Utf8Text $mainFile $updatedPhp
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
