function Invoke-WinutilThemeChange {
    <#
    .SYNOPSIS
        Does nothing in PHPorto: it themes the WinUtil window, which does not exist here.

    .DESCRIPTION
        Upstream, this function repaints the WinUtil user interface in 'Light' or 'Dark'
        mode. PHPorto has no WinUtil window, so there is nothing to repaint. The Windows
        dark mode itself is the registry part of WPFToggleDarkMode, which
        Invoke-WinUtilTweaks applies before this is called.

    .EXAMPLE
        Invoke-WinutilThemeChange -theme "Auto"
        # Returns without doing anything.
    #>
    param (
        [string]$theme = "Auto"
    )
}
