param([Parameter(Mandatory)][ValidatePattern('^\d+\.\d+\.\d+$')][string]$ExpectedVersion)
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$manifest = Get-Content -LiteralPath (Join-Path $repoRoot 'release-files.json') -Raw | ConvertFrom-Json
$main = Get-Content -LiteralPath (Join-Path $repoRoot 'ai-article-assistant.php') -Raw
$readme = Get-Content -LiteralPath (Join-Path $repoRoot 'readme.txt') -Raw
if ($main -notmatch '(?m)^ \* Version: ([0-9.]+)\s*$' -or $Matches[1] -ne $ExpectedVersion) { throw 'Main header version mismatch' }
if ($main -notmatch "define\( 'AAA_VERSION', '([0-9.]+)' \)" -or $Matches[1] -ne $ExpectedVersion) { throw 'Version constant mismatch' }
if ($readme -notmatch '(?m)^Stable tag: ([0-9.]+)\s*$' -or $Matches[1] -ne $ExpectedVersion) { throw 'Readme version mismatch' }
if ($manifest.version -ne $ExpectedVersion) { throw 'Release manifest version mismatch' }
$paths = @{}
foreach ($relative in $manifest.files) {
    $path = [IO.Path]::GetFullPath((Join-Path $repoRoot $relative))
    if (-not $path.StartsWith($repoRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or $relative -match '(^|/)(tests|tools|dist|\.git)(/|$)' -or $paths.ContainsKey($relative)) { throw 'Unsafe or duplicate distribution path' }
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing runtime file: $relative" }
    if ((Get-Item -LiteralPath $path).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Reparse point cannot be packaged' }
    $paths[$relative] = $path
    if ($relative.EndsWith('.php')) { & php -l $path; if ($LASTEXITCODE -ne 0) { throw 'PHP syntax failed' } }
    if ($relative.EndsWith('.js')) { & node --check $path; if ($LASTEXITCODE -ne 0) { throw 'JavaScript syntax failed' } }
}
$vendor = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'puc-5.7-manifest.json') -Raw | ConvertFrom-Json -AsHashtable
foreach ($entry in $vendor.files.GetEnumerator()) {
    $relative = 'vendor/plugin-update-checker/' + $entry.Key
    if (-not $paths.ContainsKey($relative) -or (Get-FileHash -LiteralPath $paths[$relative] -Algorithm SHA256).Hash -ne $entry.Value) { throw "Vendor mismatch: $relative" }
}
& php (Join-Path $repoRoot 'tests/updater.php')
if ($LASTEXITCODE -ne 0) { throw 'Updater boundary tests failed' }
$dist = Join-Path $repoRoot 'dist'
New-Item -ItemType Directory -Path $dist -Force | Out-Null
$zipPath = [IO.Path]::GetFullPath((Join-Path $dist 'ai-article-assistant.zip'))
if (-not $zipPath.StartsWith($repoRoot + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Output outside repository' }
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath }
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::Open($zipPath, [IO.Compression.ZipArchiveMode]::Create)
try { foreach ($relative in ($paths.Keys | Sort-Object)) { [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $paths[$relative], 'ai-article-assistant/' + $relative) | Out-Null } } finally { $archive.Dispose() }
$archive = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    if ($archive.Entries.Count -ne $paths.Count) { throw 'ZIP entry count mismatch' }
    foreach ($entry in $archive.Entries) {
        if (-not $entry.FullName.StartsWith('ai-article-assistant/')) { throw 'Invalid ZIP root' }
        $relative = $entry.FullName.Substring('ai-article-assistant/'.Length)
        if (-not $paths.ContainsKey($relative)) { throw 'Unexpected ZIP entry' }
        $stream = $entry.Open(); $sha = [Security.Cryptography.SHA256]::Create()
        try { $digest = [Convert]::ToHexString($sha.ComputeHash($stream)) } finally { $sha.Dispose(); $stream.Dispose() }
        if ($digest -ne (Get-FileHash -LiteralPath $paths[$relative] -Algorithm SHA256).Hash) { throw "ZIP content mismatch: $relative" }
        $paths.Remove($relative)
    }
    if ($paths.Count) { throw 'ZIP missing source files' }
} finally { $archive.Dispose() }
$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash
Set-Content -LiteralPath "$zipPath.sha256" -Value "$hash  ai-article-assistant.zip" -Encoding ascii
Write-Output "VERIFIED $ExpectedVersion : $zipPath"
Write-Output "SHA256: $hash"
