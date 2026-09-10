function Invoke-Debloat {
    $appxToRemove = @(
        'Microsoft.BingNews'
        'Microsoft.BingWeather'
        'Microsoft.BingSearch'
        'Microsoft.GamingApp'
        'Microsoft.GetHelp'
        'Microsoft.Getstarted'
        'Microsoft.MicrosoftSolitaireCollection'
        'Microsoft.People'
        'Microsoft.PowerAutomateDesktop'
        'Microsoft.Todos'
        'Microsoft.WindowsFeedbackHub'
        'Microsoft.WindowsMaps'
        'Microsoft.XboxApp'
        'Microsoft.XboxGameOverlay'
        'Microsoft.XboxGamingOverlay'
        'Microsoft.XboxIdentityProvider'
        'Microsoft.XboxSpeechToTextOverlay'
        'Microsoft.YourPhone'
        'Microsoft.ZuneMusic'
        'Microsoft.ZuneVideo'
        'Clipchamp.Clipchamp'
        'MicrosoftTeams'
    )

    if ($appxToRemove.Count -eq 0) {
        Write-Status WARNING "No packages defined for removal."
        return
    }

    Write-Status INFO "Removing $($appxToRemove.Count) APPX package(s)..."
    foreach ($name in $appxToRemove) {
        try {
            Remove-WinUtilAPPX -Name $name
            Write-Status OK $name
        } catch {
            Write-Status ERROR "$name -> $($_.Exception.Message)"
        }
    }
    Write-Status OK "Debloat complete."
}
