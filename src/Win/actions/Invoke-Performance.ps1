function Invoke-Performance {
    param(
        [ValidateSet('on', 'off')]
        [string]$State = 'on'
    )

    $balancedGuid = '381b4222-f694-41f0-9685-ff5bb260df2e'
    $ultimateGuid = 'e9a42b02-d5df-448d-aa00-03f14749eb61'
    $hiPerfGuid   = '8c5e7fda-e8bf-4a96-9a85-a6e23a8c635c'

    if ($State -eq 'on') {
        Write-Status INFO "Detecting available high-performance power plan..."

        $planOutput = powercfg /list 2>&1
        $targetGuid = $null

        if ($planOutput -match [regex]::Escape($ultimateGuid)) {
            $targetGuid = $ultimateGuid
        }

        if (-not $targetGuid) {
            foreach ($line in $planOutput) {
                if ($line -match 'Ultimate|Desempenho M.ximo') {
                    if ($line -match '([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})') {
                        $targetGuid = $Matches[1]
                        break
                    }
                }
            }
        }

        if (-not $targetGuid) {
            Write-Status INFO "Attempting to add Ultimate Performance via duplicatescheme..."
            powercfg -duplicatescheme $ultimateGuid 2>&1 | Out-Null
            $planOutput = powercfg /list 2>&1
            if ($planOutput -match [regex]::Escape($ultimateGuid)) {
                $targetGuid = $ultimateGuid
            }
        }

        if (-not $targetGuid) {
            if ($planOutput -match [regex]::Escape($hiPerfGuid)) {
                $targetGuid = $hiPerfGuid
            }
        }

        if (-not $targetGuid) {
            Write-Status WARNING "No high-performance plan found. Using Balanced."
            $targetGuid = $balancedGuid
        }

        Write-Status INFO "Activating: $targetGuid"
        try {
            powercfg -setactive $targetGuid
            Write-Status OK "Power plan activated."
        } catch {
            Write-Status ERROR $_.Exception.Message
        }
    } else {
        Write-Status INFO "Switching back to the Balanced plan..."
        try {
            powercfg -setactive $balancedGuid
            Write-Status OK "Balanced plan activated."
        } catch {
            Write-Status ERROR $_.Exception.Message
        }
    }
}
