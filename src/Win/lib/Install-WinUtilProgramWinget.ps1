Function Install-WinUtilProgramWinget {
    <#

    .SYNOPSIS
        Installs or uninstalls each program with winget, one at a time, and returns each exit code

    .PARAMETER Action
        Install or Uninstall

    .PARAMETER Programs
        The winget package IDs, matched exactly

    #>
    param (
        [Parameter(Mandatory=$true)]
        [ValidateSet("Install", "Uninstall")]
        [string]$Action,

        [Parameter(Mandatory=$true)]
        [string[]]$Programs
    )

    foreach ($Program in $Programs) {
        if ($Action -eq 'Install') {
            $Arguments = "install --id `"$Program`" --exact --accept-package-agreements --accept-source-agreements --source winget --silent --disable-interactivity"
        } else {
            $Arguments = "uninstall --id `"$Program`" --exact --accept-source-agreements --source winget --silent --disable-interactivity"
        }
        $Process = Start-Process -FilePath winget -ArgumentList $Arguments -NoNewWindow -Wait -PassThru
        [PSCustomObject]@{ Program = $Program; ExitCode = $Process.ExitCode }
    }
}
