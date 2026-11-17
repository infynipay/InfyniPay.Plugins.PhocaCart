<#
    Builds an installable Joomla package zip for the Infynipay PhocaCart plugin.

    The plugin manifest (infynipay.xml) must sit at the ROOT of the zip, so we
    archive the individual files/folders listed in the manifest rather than the
    repository folder itself.

    Entry names use FORWARD slashes. PowerShell's built-in Compress-Archive writes
    Windows-style backslash separators which PHP's ZipArchive (used by Joomla's
    installer) does not split into folders — producing files literally named
    "src\Extension\...". We therefore build the archive entry-by-entry with
    System.IO.Compression so every path is portable.

    Output: tests/e2e/dist/plg_pcp_infynipay.zip
#>

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Repo root is three levels up from this script (tests/e2e/scripts -> repo root).
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$distDir  = Join-Path $PSScriptRoot '..\dist'
$zipPath  = Join-Path $distDir 'plg_pcp_infynipay.zip'

# Entries that make up the installable package (mirrors <files>/<languages> in infynipay.xml).
$entries = @(
    'infynipay.php',
    'infynipay.xml',
    'script.php',
    'src',
    'services',
    'images',
    'layouts',
    'media',
    'language'
)

New-Item -ItemType Directory -Force -Path $distDir | Out-Null
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Collect (absolutePath -> entryName) pairs with forward-slash entry names.
$files = New-Object System.Collections.Generic.List[object]
foreach ($entry in $entries) {
    $full = Join-Path $repoRoot $entry
    if (-not (Test-Path $full)) {
        Write-Warning "Skipping missing entry: $entry"
        continue
    }

    if (Test-Path $full -PathType Leaf) {
        $files.Add([pscustomobject]@{ Path = $full; Name = $entry })
    } else {
        Get-ChildItem -Path $full -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($repoRoot.Length).TrimStart('\', '/')
            $files.Add([pscustomobject]@{ Path = $_.FullName; Name = ($rel -replace '\\', '/') })
        }
    }
}

$zip = [System.IO.Compression.ZipFile]::Open(
    $zipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)
try {
    foreach ($f in $files) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $f.Path,
            $f.Name,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $zip.Dispose()
}

Write-Host "Built plugin package: $((Resolve-Path $zipPath).Path)  ($($files.Count) files)"
