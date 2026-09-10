function Invoke-Tweaks {
    param(
        [string]$Preset,
        [switch]$Undo
    )

    # preset.json uses Title-Case keys (Standard / Minimal / Advanced)
    $key = (Get-Culture).TextInfo.ToTitleCase($Preset.ToLower())

    $list = $sync.configs.preset.$key
    if (-not $list) {
        Write-Status ERROR "Preset '$key' not found in preset.json"
        return
    }

    $modo = if ($Undo) { "Reverting" } else { "Applying" }
    Write-Status INFO "$modo preset '$key' ($($list.Count) tweaks)..."
    foreach ($checkbox in $list) {
        try {
            Invoke-WinUtilTweaks -CheckBox $checkbox -undo $Undo.IsPresent
            Write-Status OK $checkbox
        } catch {
            Write-Status ERROR "$checkbox -> $($_.Exception.Message)"
        }
    }
    Write-Status OK "Preset '$key' $($modo.ToLower()) successfully."
}
