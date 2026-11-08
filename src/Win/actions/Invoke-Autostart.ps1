function Split-PhportoCommandLine {
    param([string]$Line)

    $itens  = New-Object System.Collections.Generic.List[string]
    $atual  = New-Object System.Text.StringBuilder
    $dentro = $false
    $tem    = $false
    $i      = 0
    $n      = $Line.Length

    while ($i -lt $n) {
        $c = $Line[$i]

        if ($c -eq '\') {
            $j = $i
            while ($j -lt $n -and $Line[$j] -eq '\') { $j++ }
            $barras = $j - $i
            $tem = $true
            if ($j -lt $n -and $Line[$j] -eq '"') {
                [void]$atual.Append('\', [int][Math]::Floor($barras / 2))
                if ($barras % 2 -eq 1) {
                    [void]$atual.Append('"')
                    $i = $j + 1
                } else {
                    $i = $j
                }
                continue
            }
            [void]$atual.Append('\', $barras)
            $i = $j
            continue
        }

        if ($c -eq '"') {
            $dentro = -not $dentro
            $tem = $true
            $i++
            continue
        }

        if ([char]::IsWhiteSpace($c) -and -not $dentro) {
            if ($tem) {
                $itens.Add($atual.ToString())
                [void]$atual.Clear()
                $tem = $false
            }
            $i++
            continue
        }

        [void]$atual.Append($c)
        $tem = $true
        $i++
    }

    if ($tem) { $itens.Add($atual.ToString()) }
    return , $itens.ToArray()
}

function Get-PhportoProcessCwd {
    param([int]$ProcessId)

    if (-not ('PhportoProc' -as [type])) {
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class PhportoProc {
    [DllImport("kernel32.dll")] static extern IntPtr OpenProcess(uint access, bool inherit, int pid);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
    [DllImport("kernel32.dll")] static extern bool ReadProcessMemory(IntPtr h, IntPtr addr, byte[] buf, IntPtr size, out IntPtr read);
    [DllImport("ntdll.dll")] static extern int NtQueryInformationProcess(IntPtr h, int cls, ref PBI pbi, int len, out int ret);
    [StructLayout(LayoutKind.Sequential)]
    struct PBI { public IntPtr R1; public IntPtr Peb; public IntPtr R2a; public IntPtr R2b; public IntPtr Pid; public IntPtr R3; }
    public static string Cwd(int pid) {
        if (IntPtr.Size != 8) return null;
        IntPtr h = OpenProcess(0x0410, false, pid);
        if (h == IntPtr.Zero) return null;
        try {
            PBI pbi = new PBI(); int ret; IntPtr rd;
            if (NtQueryInformationProcess(h, 0, ref pbi, Marshal.SizeOf(pbi), out ret) != 0) return null;
            byte[] b8 = new byte[8];
            if (!ReadProcessMemory(h, new IntPtr(pbi.Peb.ToInt64() + 0x20), b8, (IntPtr)8, out rd)) return null;
            long pp = BitConverter.ToInt64(b8, 0);
            byte[] us = new byte[16];
            if (!ReadProcessMemory(h, new IntPtr(pp + 0x38), us, (IntPtr)16, out rd)) return null;
            int len = BitConverter.ToUInt16(us, 0);
            long buf = BitConverter.ToInt64(us, 8);
            if (len <= 0 || len > 65534 || buf == 0) return null;
            byte[] s = new byte[len];
            if (!ReadProcessMemory(h, new IntPtr(buf), s, (IntPtr)len, out rd)) return null;
            return System.Text.Encoding.Unicode.GetString(s);
        } finally { CloseHandle(h); }
    }
}
'@
    }

    try {
        $cwd = [PhportoProc]::Cwd($ProcessId)
        if ([string]::IsNullOrWhiteSpace($cwd)) { return $null }
        return $cwd
    } catch {
        return $null
    }
}

function Find-PhportoServe {
    param([string]$Script)

    $alvo         = [IO.Path]::GetFullPath($Script)
    $achados      = New-Object System.Collections.Generic.List[int]
    $semConfirmar = 0
    $processos    = @(Get-CimInstance -ClassName Win32_Process -Filter "Name='php.exe'" -ErrorAction Stop)

    foreach ($p in $processos) {
        if ([string]::IsNullOrWhiteSpace($p.CommandLine)) {
            $semConfirmar++
            continue
        }

        $a = Split-PhportoCommandLine $p.CommandLine
        $idx = -1
        for ($k = 1; $k -lt $a.Count - 1; $k++) {
            if ([IO.Path]::GetFileName($a[$k]) -ieq 'phporto' -and $a[$k + 1] -ieq 'serve') {
                $idx = $k
                break
            }
        }
        if ($idx -lt 0) { continue }

        $caminho = $a[$idx]
        if ($caminho -notmatch '\A[A-Za-z]:\\') {
            $cwd = Get-PhportoProcessCwd -ProcessId $p.ProcessId
            if ([string]::IsNullOrWhiteSpace($cwd)) {
                $semConfirmar++
                continue
            }
            $caminho = [IO.Path]::Combine($cwd, $caminho)
        }

        try {
            $completo = [IO.Path]::GetFullPath($caminho)
        } catch {
            $semConfirmar++
            continue
        }

        if ([string]::Equals($completo, $alvo, [StringComparison]::OrdinalIgnoreCase)) {
            $achados.Add([int]$p.ProcessId)
        }
    }

    return [PSCustomObject]@{ Ids = $achados.ToArray(); SemConfirmar = $semConfirmar }
}

function Stop-PhportoTree {
    param([int]$ProcessId)

    & taskkill.exe /T /F /PID $ProcessId | Out-Null
}

function Invoke-Autostart {
    param(
        [string]$SubAction,
        [string]$Sid,
        [string]$Program,
        [string]$Script
    )

    $taskName = 'PHPorto'
    $opcoes   = 'on, off, stop'

    function Test-AutostartPaths {
        if ([string]::IsNullOrWhiteSpace($Program) -or [IO.Path]::GetFileName($Program) -ne 'php.exe' -or -not (Test-Path -LiteralPath $Program -PathType Leaf)) {
            Write-Status ERROR "Program is not an existing php.exe: '$Program'."
            return $false
        }
        if ([string]::IsNullOrWhiteSpace($Script) -or [IO.Path]::GetFileName($Script) -ne 'phporto' -or -not (Test-Path -LiteralPath $Script -PathType Leaf)) {
            Write-Status ERROR "Script is not an existing phporto file: '$Script'."
            return $false
        }
        return $true
    }

    function Test-AutostartUser {
        if ([string]::IsNullOrWhiteSpace($Sid) -or $Sid -notmatch '\AS-1-5-21-[0-9]{1,10}-[0-9]{1,10}-[0-9]{1,10}-[0-9]{1,10}\z') {
            Write-Status ERROR "Sid is not an account SID. Nothing was created."
            return $false
        }
        $atual = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        if ($Sid -cne $atual) {
            Write-Status ERROR "Sid does not match the account of this process. Nothing was created."
            return $false
        }
        return $true
    }

    function Enable-AutostartTask {
        if (-not (Test-AutostartPaths)) { return }
        if (-not (Test-AutostartUser)) { return }

        try {
            $action    = New-ScheduledTaskAction -Execute $Program -Argument ('"' + $Script + '" serve') -WorkingDirectory (Split-Path -Parent $Script)
            $trigger   = New-ScheduledTaskTrigger -AtStartup
            $settings  = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
                -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
            $conta     = [Security.Principal.WindowsIdentity]::GetCurrent().Name
            $principal = New-ScheduledTaskPrincipal -UserId $conta -LogonType S4U -RunLevel Limited
            Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
            Write-Status OK "Scheduled task '$taskName' registered (at startup, $conta, S4U, RunLevel Limited, no password stored)."
        } catch {
            Write-Status ERROR "Failed to register scheduled task: $($_.Exception.Message)"
        }
    }

    function Disable-AutostartTask {
        try {
            $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            if (-not $task) {
                Write-Status OK "No scheduled task '$taskName' to remove."
                return
            }
            Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop
            Write-Status OK "Scheduled task '$taskName' removed. A running server is not stopped."
        } catch {
            Write-Status ERROR "Failed to remove scheduled task: $($_.Exception.Message)"
        }
    }

    function Stop-AutostartTask {
        if ([string]::IsNullOrWhiteSpace($Script) -or [IO.Path]::GetFileName($Script) -ne 'phporto') {
            Write-Status ERROR "Script is not a phporto file: '$Script'."
            return
        }

        try {
            $busca = Find-PhportoServe -Script $Script
            foreach ($id in $busca.Ids) {
                Stop-PhportoTree -ProcessId $id
            }
            $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            if ($task) {
                Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            }
            Write-Status OK "Server stopped ($($busca.Ids.Count) process tree(s)). $($busca.SemConfirmar) php.exe process(es) could not be confirmed and were left alone."
        } catch {
            Write-Status ERROR "Failed to stop the server: $($_.Exception.Message)"
        }
    }

    if (-not $SubAction) {
        Write-Status ERROR "SubAction required. Options: $opcoes"
        return
    }

    switch ($SubAction) {
        'on'    { Enable-AutostartTask }
        'off'   { Disable-AutostartTask }
        'stop'  { Stop-AutostartTask }
        default { Write-Status ERROR "Unknown subaction '$SubAction'. Options: $opcoes" }
    }
}
