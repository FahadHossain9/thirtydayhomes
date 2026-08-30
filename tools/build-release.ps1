<#
    Builds the two installable zips.

        powershell -ExecutionPolicy Bypass -File tools\build-release.ps1

    Output lands in dist\ , which is gitignored.

    WHY A SCRIPT AND NOT "right-click, Send to, Compressed folder".

    WordPress needs the zip to contain ONE top-level folder named exactly
    what the theme or plugin folder is called. Zipping the *contents* of the
    folder produces an archive WordPress rejects with "the package could not
    be installed: no valid theme found", which is the usual reason a hand-made
    zip fails. This builds the folder first and zips the folder.

    It also strips things that must never reach a live server - .git, editor
    droppings, logs - and reads the version out of the source files so the
    filename cannot disagree with what the code reports.
#>

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root  = Split-Path -Parent $PSScriptRoot
$dist  = Join-Path $root 'dist'
$stage = Join-Path $dist 'stage'

# ---- read the versions from source, never hardcode them -------------------

$themeSrc  = Join-Path $root 'themes\thirtydayhomes'
$pluginSrc = Join-Path $root 'plugins\thirtydayhomes-core'

$themeVersion  = ([regex]::Match((Get-Content (Join-Path $themeSrc 'style.css') -Raw), 'Version:\s*([0-9.]+)')).Groups[1].Value
$pluginVersion = ([regex]::Match((Get-Content (Join-Path $pluginSrc 'thirtydayhomes-core.php') -Raw), 'Version:\s*([0-9.]+)')).Groups[1].Value

if (-not $themeVersion -or -not $pluginVersion) {
    throw 'Could not read a version out of style.css or thirtydayhomes-core.php.'
}

# The plugin header and the VERSION constant drive different things - the
# header is what WordPress shows, the constant is what triggers the upgrade
# routine. If they disagree, an update silently skips re-registering roles.
$constVersion = ([regex]::Match((Get-Content (Join-Path $pluginSrc 'thirtydayhomes-core.php') -Raw), "const VERSION\s*=\s*'([0-9.]+)'")).Groups[1].Value

if ($constVersion -ne $pluginVersion) {
    throw "Plugin header says $pluginVersion but const VERSION says $constVersion. Make them match before releasing."
}

# ---- stage, excluding everything that must not ship -----------------------

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Path $stage -Force | Out-Null

$exclude = @('.git', '.github', 'node_modules', '.DS_Store', 'Thumbs.db', '*.log', '*.zip')

function Stage([string]$from, [string]$name) {
    $to = Join-Path $stage $name
    robocopy $from $to /E /NFL /NDL /NJH /NJS /NP /XD '.git' '.github' 'node_modules' /XF '.DS_Store' 'Thumbs.db' '*.log' '*.zip' | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed for $name (exit $LASTEXITCODE)" }
    return $to
}

$themeStaged  = Stage $themeSrc  'thirtydayhomes'
$pluginStaged = Stage $pluginSrc 'thirtydayhomes-core'

# ---- zip, with the folder itself inside the archive -----------------------

# Entries are written one at a time with FORWARD SLASHES, deliberately.
#
# [ZipFile]::CreateFromDirectory on .NET Framework writes entry names using
# the platform separator, so on Windows every path in the archive came out as
# "thirtydayhomes\style.css". A backslash is a legal FILENAME character in the
# zip spec, not a separator, so a Linux server unpacks that as one file
# literally called "thirtydayhomes\style.css" - no folder, no style.css, and
# WordPress reports "no valid theme found". This is the single most common
# reason a hand-built zip fails on a live server.
function Pack([string]$folder, [string]$rootName, [string]$zipName) {

    $zipPath = Join-Path $dist $zipName
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

    $base    = (Resolve-Path $folder).Path.TrimEnd('\')
    $archive = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')

    try {
        foreach ($file in Get-ChildItem $folder -Recurse -File) {
            $relative = $file.FullName.Substring($base.Length + 1) -replace '\\', '/'
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive, $file.FullName, "$rootName/$relative", 'Optimal'
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }

    return $zipPath
}

$themeZip  = Pack $themeStaged  'thirtydayhomes'      "thirtydayhomes-theme-$themeVersion.zip"
$pluginZip = Pack $pluginStaged 'thirtydayhomes-core' "thirtydayhomes-core-$pluginVersion.zip"

Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue

# ---- prove each zip is shaped the way WordPress needs ---------------------

function Check([string]$zip, [string]$expectedFolder, [string]$mustContain) {
    $a = [System.IO.Compression.ZipFile]::OpenRead($zip)
    try {
        # @() on both: a pipeline returning ONE item unrolls to a scalar, and
        # $roots[0] on a bare string is its first CHARACTER, so a correct
        # single-root archive reported itself broken.
        $names = @($a.Entries | ForEach-Object { $_.FullName })
        $roots = @($names | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
        $ok = ($roots.Count -eq 1) -and ($roots[0] -eq $expectedFolder) -and ($names -contains "$expectedFolder/$mustContain")
        $leaked = $names | Where-Object { $_ -match '(^|/)\.git/' -or $_ -match 'node_modules/' }
        "{0,-42} {1}" -f (Split-Path $zip -Leaf), $(if ($ok -and -not $leaked) { 'OK' } else { 'BROKEN' })
        "    single root folder '$expectedFolder' : $(if ($roots.Count -eq 1 -and $roots[0] -eq $expectedFolder) { 'yes' } else { 'NO - ' + ($roots -join ', ') })"
        "    contains $mustContain".PadRight(46) + ": $(if ($names -contains "$expectedFolder/$mustContain") { 'yes' } else { 'NO' })"
        "    no .git / node_modules leaked".PadRight(46) + ": $(if ($leaked) { 'NO' } else { 'yes' })"
        "    files".PadRight(46) + ": $($names.Count)"
        "    size".PadRight(46) + ": $([math]::Round((Get-Item $zip).Length / 1kb, 1)) KB"
    } finally {
        $a.Dispose()
    }
}

""
"=== built into $dist ==="
Check $themeZip  'thirtydayhomes'      'style.css'
""
Check $pluginZip 'thirtydayhomes-core' 'thirtydayhomes-core.php'
""
"Theme  version: $themeVersion"
"Plugin version: $pluginVersion"
