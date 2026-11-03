#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$pluginSlug = 'breakdance-slovak-ui'
$pluginDir = Join-Path $projectRoot $pluginSlug
$mainFile = Join-Path $pluginDir "$pluginSlug.php"
$readmeFile = Join-Path $pluginDir 'readme.txt'
$translationBuildScript = Join-Path $projectRoot 'Build Scripts\build-translations.mjs'
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

function Build-Translations {
    if ($null -eq (Get-Command node -ErrorAction SilentlyContinue)) {
        throw 'Node.js is required to build translations (node was not found in PATH).'
    }

    # Validates Translations/breakdance-sk_SK.json and generates plugin languages/js and languages/php files.
    & node $translationBuildScript
    if ($LASTEXITCODE -ne 0) {
        throw 'Translation validation/build failed; fix the reported errors and run the release again.'
    }
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

        foreach ($required in @("$pluginSlug.php", 'readme.txt', 'languages/js/breakdance-sk_SK.json')) {
            if ($null -eq $archive.GetEntry("$pluginSlug/$required")) {
                throw "ZIP is missing '$pluginSlug/$required'."
            }
        }

        foreach ($jsonEntry in @($entries | Where-Object { $_.FullName -like "$pluginSlug/languages/*.json" })) {
            try {
                $null = [System.Text.Json.JsonDocument]::Parse((Get-ZipEntryText $jsonEntry))
            }
            catch {
                throw "ZIP contains invalid JSON: $($jsonEntry.FullName)"
            }
        }

        $mainText = Get-ZipEntryText $archive.GetEntry("$pluginSlug/$pluginSlug.php")
        if ($mainText -notmatch "(?m)^\s*\*\s*Version:\s*$([regex]::Escape($ExpectedVersion))\s*$" -or
            $mainText -notmatch [regex]::Escape("define('BSU_PLUGIN_VERSION', '$ExpectedVersion');")) {
            throw "ZIP plugin version metadata is not synchronized at $ExpectedVersion."
        }

        $readmeText = Get-ZipEntryText $archive.GetEntry("$pluginSlug/readme.txt")
        if ($readmeText -notmatch "(?m)^Stable tag:\s*$([regex]::Escape($ExpectedVersion))\s*$" -or
            $readmeText -notmatch "(?m)^= $([regex]::Escape($ExpectedVersion)) =\s*$") {
            throw "ZIP readme version metadata is not synchronized at $ExpectedVersion."
        }
    }
    finally {
        $archive.Dispose()
    }
}

Build-Translations

$php = Read-Utf8Text $mainFile
if ($php -notmatch '(?m)^\s*\*\s*Version:\s*(\d+)\.(\d+)\.(\d+)\s*$') {
    throw "Could not find plugin version in header of $mainFile"
}

$major = [int]$Matches[1]
$oldVersion = "$($Matches[1]).$($Matches[2]).$($Matches[3])"

# The first release ships the version currently in the sources; later releases bump MINOR.
$currentZip = Join-Path $releasesDir "Breakdance Slovak UI $oldVersion.zip"
$isFirstRelease = -not (Test-Path -LiteralPath $currentZip)
$newVersion = if ($isFirstRelease) { $oldVersion } else { '{0}.{1}.0' -f $major, ([int]$Matches[2] + 1) }

$updatedPhp = $php -replace '(?m)^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+\s*$', "`${1}$newVersion"
$updatedPhp = $updatedPhp -replace "(define\('BSU_PLUGIN_VERSION',\s*')\d+\.\d+\.\d+('\);)", "`${1}$newVersion`${2}"
if ($updatedPhp -notmatch [regex]::Escape("define('BSU_PLUGIN_VERSION', '$newVersion');")) {
    throw 'Could not update BSU_PLUGIN_VERSION in the plugin entry file.'
}

$readme = Read-Utf8Text $readmeFile
if ($readme -notmatch '(?m)^Stable tag:\s*\d+\.\d+\.\d+\s*$' -or $readme -notmatch '(?m)^== Changelog ==\s*\r?\n') {
    throw "Could not find Stable tag or Changelog section in $readmeFile"
}
$updatedReadme = $readme -replace '(?m)^(Stable tag:\s*)\d+\.\d+\.\d+\s*$', "`${1}$newVersion"
if (-not $isFirstRelease) {
    $note = "`r`n= $newVersion =`r`n- version bump to $newVersion`r`n"
    $updatedReadme = $updatedReadme -replace '(?m)^(== Changelog ==\s*\r?\n)', "`${1}$note"
}

$zipName = "Breakdance Slovak UI $newVersion.zip"
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
    Write-Utf8Text (Join-Path $stagingPluginDir 'readme.txt') $updatedReadme

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

Write-Host "Version: $oldVersion -> $newVersion"
Write-Host "Validated release package: $zipPath"
