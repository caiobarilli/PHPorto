function Invoke-Tweaks {
    param(
        [string]$Preset,
        [string[]]$Items,
        [switch]$Undo
    )

    if ($Preset -and $Items) {
        Write-Status ERROR "Use -Preset or -Items, not both."
        return
    }

    if ($Items) {
        $list   = @($Items | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        $rotulo = 'Selection'
        $alvo   = 'selection'
    } elseif ($Preset) {
        # preset.json uses Title-Case keys (Standard / Minimal / Advanced)
        $key = (Get-Culture).TextInfo.ToTitleCase($Preset.ToLower())

        $list = $sync.configs.preset.$key
        if (-not $list) {
            Write-Status ERROR "Preset '$key' not found in preset.json"
            return
        }
        $rotulo = "Preset '$key'"
        $alvo   = "preset '$key'"
    } else {
        Write-Status ERROR "No preset or items given."
        return
    }

    $modo = if ($Undo) { "Reverting" } else { "Applying" }
    Write-Status INFO "$modo $alvo ($($list.Count) tweaks)..."
    $falhas = @()
    foreach ($checkbox in $list) {
        if ($Items -and -not $sync.configs.tweaks.$checkbox) {
            Write-Status ERROR "$checkbox -> not applied, not in tweaks.json"
            $falhas += $checkbox
            continue
        }
        $ausentes = @(Get-PhportoMissingTweakCommand -CheckBox $checkbox -Undo:$Undo)
        if ($ausentes.Count -gt 0) {
            Write-Status ERROR "$checkbox -> not applied, missing command: $($ausentes -join ', ')"
            $falhas += $checkbox
            continue
        }
        try {
            Invoke-WinUtilTweaks -CheckBox $checkbox -undo $Undo.IsPresent
            Write-Status OK $checkbox
        } catch {
            Write-Status ERROR "$checkbox -> $($_.Exception.Message)"
            $falhas += $checkbox
        }
    }
    if ($falhas.Count -gt 0) {
        Write-Status ERROR "$rotulo $($modo.ToLower()) finished with $($falhas.Count) error(s): $($falhas -join ', ')"
        return
    }
    Write-Status OK "$rotulo $($modo.ToLower()) successfully."
}

<#
    Lists the commands a tweak's script would reach that do not exist here.

    Receives the tweak key and whether it is an undo. Follows every function
    the script calls that is not part of a module into that function's own
    body. Returns the missing command names, empty when all of them resolve
    or the tweak has no script for that direction.
#>
function Get-PhportoMissingTweakCommand {
    param(
        [string]$CheckBox,
        [switch]$Undo
    )

    $campo    = if ($Undo) { 'UndoScript' } else { 'InvokeScript' }
    $scripts  = @($sync.configs.tweaks.$CheckBox.$campo) | Where-Object { $_ }
    $ausentes = New-Object System.Collections.Generic.List[string]
    $vistas   = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)

    foreach ($texto in $scripts) {
        Find-PhportoMissingCommand -Ast ([scriptblock]::Create($texto).Ast) -Ausentes $ausentes -Vistas $vistas
    }

    return $ausentes.ToArray()
}

<#
    Walks one script AST and records the commands it calls that do not exist.

    Receives the AST, the list that collects missing names and the set of
    functions already walked. Returns nothing; fills the list.
#>
function Find-PhportoMissingCommand {
    param(
        [System.Management.Automation.Language.Ast]$Ast,
        [System.Collections.Generic.List[string]]$Ausentes,
        [System.Collections.Generic.HashSet[string]]$Vistas
    )

    $definidas = @($Ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true) |
        ForEach-Object { $_.Name })

    $chamadas = $Ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] }, $true) |
        ForEach-Object { $_.GetCommandName() } |
        Where-Object { $_ }

    foreach ($nome in $chamadas) {
        if ($nome -in $definidas -or $Ausentes.Contains($nome) -or -not $Vistas.Add($nome)) { continue }

        $comando = Get-Command -Name $nome -ErrorAction SilentlyContinue | Select-Object -First 1

        if ($null -eq $comando) {
            $Ausentes.Add($nome)
        } elseif ($comando.CommandType -eq 'Function' -and -not $comando.ModuleName -and $null -ne $comando.ScriptBlock) {
            Find-PhportoMissingCommand -Ast $comando.ScriptBlock.Ast -Ausentes $Ausentes -Vistas $Vistas
        }
    }
}
