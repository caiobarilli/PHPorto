# audit.ps1 - Windows Desktop Audit
# Save to C:\audit.ps1 and run as Administrator

$date = Get-Date -Format "dd.MM.yyyy"
$time = Get-Date -Format "HH:mm:ss"
$logDir = "C:\log\$date"

New-Item -ItemType Directory -Force -Path $logDir | Out-Null

# UTF-8 encoding without BOM (compatible with PS 5.1 and 7+)
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Block {
    param($file, $title, $content)
    $header = @"
================================================================================
$title
Generated: $date $time
================================================================================

"@
    [System.IO.File]::WriteAllText("$logDir\$file", ($header + $content), $utf8NoBom)
    Write-Host "[ OK ] $file"
}

# ============================================================
# PRIVILEGE CHECK
# ============================================================
$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "[ WARNING ] No Administrator privileges." -ForegroundColor Yellow
    Write-Host "            Network and task blocks may be incomplete." -ForegroundColor Yellow
    Write-Host ""
}

# ============================================================
# BLOCK 01 - SYSTEM
# ============================================================
$os       = Get-CimInstance Win32_OperatingSystem
$cs       = Get-CimInstance Win32_ComputerSystem
$uptime   = (Get-Date) - $os.LastBootUpTime
$content  = @"
Hostname        : $($cs.Name)
Domain          : $($cs.Domain)
User            : $($env:USERNAME)
OS              : $($os.Caption)
Version         : $($os.Version)
Build           : $($os.BuildNumber)
Architecture    : $($os.OSArchitecture)
Uptime          : $([math]::Floor($uptime.TotalHours))h $($uptime.Minutes)m $($uptime.Seconds)s
Last Boot       : $($os.LastBootUpTime)
Time Zone       : $((Get-TimeZone).DisplayName)
"@
Write-Block "01-system.txt" "BLOCK 01 - SYSTEM" $content

# ============================================================
# BLOCK 02 - HARDWARE
# ============================================================
$cpu     = Get-CimInstance Win32_Processor | Select-Object -First 1
$ram     = Get-CimInstance Win32_OperatingSystem
$ramTotal = [math]::Round($ram.TotalVisibleMemorySize / 1MB, 2)
$ramFree  = [math]::Round($ram.FreePhysicalMemory / 1MB, 2)
$ramUsed  = [math]::Round($ramTotal - $ramFree, 2)
$gpu      = Get-CimInstance Win32_VideoController | Select-Object -First 1

# VRAM via registry (AdapterRAM saturates at ~4GB as uint32)
$vramGB = "N/A"
try {
    $videoKey = Get-ChildItem "HKLM:\SYSTEM\CurrentControlSet\Control\Class\{4d36e968-e325-11ce-bfc1-08002be10318}" -ErrorAction Stop |
        Where-Object { $_.PSChildName -match '^\d{4}$' } |
        ForEach-Object { Get-ItemProperty $_.PSPath -ErrorAction SilentlyContinue } |
        Where-Object { $_.'HardwareInformation.qwMemorySize' } |
        Select-Object -First 1
    if ($videoKey) {
        $vramGB = "$([math]::Round($videoKey.'HardwareInformation.qwMemorySize' / 1GB, 1)) GB"
    }
} catch { }

$discos = Get-PSDrive -PSProvider FileSystem | Where-Object { $_.Used -ne $null } | ForEach-Object {
    $total = [math]::Round(($_.Used + $_.Free) / 1GB, 1)
    $used  = [math]::Round($_.Used / 1GB, 1)
    $free  = [math]::Round($_.Free / 1GB, 1)
    "  $($_.Name):\ | Total: ${total}GB | Used: ${used}GB | Free: ${free}GB"
}

$content = @"
CPU
  Name        : $($cpu.Name)
  Cores       : $($cpu.NumberOfCores)
  Threads     : $($cpu.NumberOfLogicalProcessors)
  Clock Max   : $($cpu.MaxClockSpeed) MHz

GPU
  Name        : $($gpu.Name)
  VRAM        : $vramGB

RAM
  Total       : ${ramTotal} GB
  Used        : ${ramUsed} GB
  Free        : ${ramFree} GB

DISKS
$($discos -join "`n")
"@
Write-Block "02-hardware.txt" "BLOCK 02 - HARDWARE" $content

# ============================================================
# BLOCK 03 - PROCESSES
# ============================================================
$procs = Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First 30 |
    ForEach-Object {
        "{0,-40} CPU: {1,8}   RAM: {2,8} MB" -f $_.Name, [math]::Round($_.CPU,2), [math]::Round($_.WorkingSet64/1MB,1)
    }

$content = $procs -join "`n"
Write-Block "03-processes.txt" "BLOCK 03 - PROCESSES (Top 30 by RAM)" $content

# ============================================================
# BLOCK 04 - SERVICES
# ============================================================
$svcs = Get-Service | Where-Object { $_.Status -eq "Running" } | Sort-Object DisplayName |
    ForEach-Object {
        "{0,-50} {1,-10} {2}" -f $_.DisplayName, $_.Status, $_.StartType
    }

$content = $svcs -join "`n"
Write-Block "04-services.txt" "BLOCK 04 - RUNNING SERVICES" $content

# ============================================================
# BLOCK 05 - STARTUP
# ============================================================
$startup = Get-CimInstance Win32_StartupCommand | Sort-Object Name |
    ForEach-Object {
        "{0,-45} {1}" -f $_.Name, $_.Command
    }

$content = $startup -join "`n"
Write-Block "05-startup.txt" "BLOCK 05 - STARTUP PROGRAMS" $content

# ============================================================
# BLOCK 06 - NETWORK
# ============================================================
# Fetch connections once and cache processes by Id
$tcp = Get-NetTCPConnection
$procCache = @{}
Get-Process | ForEach-Object { $procCache[$_.Id] = $_.Name }

function Resolve-ProcName($processId) {
    if ($procCache.ContainsKey([int]$processId)) { $procCache[[int]$processId] } else { "unknown" }
}

$conns = $tcp | Where-Object { $_.State -eq "Established" } |
    ForEach-Object {
        $proc = Resolve-ProcName $_.OwningProcess
        "{0,-25} {1,-22} {2,-22} {3}" -f $proc, "$($_.LocalAddress):$($_.LocalPort)", "$($_.RemoteAddress):$($_.RemotePort)", $_.State
    }

$ports = $tcp | Where-Object { $_.State -eq "Listen" } |
    ForEach-Object {
        $proc = Resolve-ProcName $_.OwningProcess
        "{0,-25} {1}" -f $proc, "$($_.LocalAddress):$($_.LocalPort)"
    }

$content = @"
ESTABLISHED CONNECTIONS
$($conns -join "`n")

OPEN PORTS (LISTEN)
$($ports -join "`n")
"@
Write-Block "06-network.txt" "BLOCK 06 - NETWORK" $content

# ============================================================
# BLOCK 07 - SCHEDULED TASKS
# ============================================================
$tasks = Get-ScheduledTask | Where-Object { $_.State -eq "Ready" -or $_.State -eq "Running" } |
    Sort-Object TaskName |
    ForEach-Object {
        "{0,-50} {1,-10} {2}" -f $_.TaskName, $_.State, $_.TaskPath
    }

$content = $tasks -join "`n"
Write-Block "07-tasks.txt" "BLOCK 07 - SCHEDULED TASKS (Active)" $content

# ============================================================
# BLOCK 08 - HYPER-V
# ============================================================
if (Get-Command Get-VM -ErrorAction SilentlyContinue) {
    try {
        $vms = Get-VM -ErrorAction Stop | ForEach-Object {
            $mem = [math]::Round($_.MemoryAssigned / 1MB, 0)
            "{0,-25} {1,-12} CPU: {2,5}%   RAM: {3} MB   Uptime: {4}" -f $_.Name, $_.State, $_.CPUUsage, $mem, $_.Uptime
        }
        $content = if ($vms) { $vms -join "`n" } else { "No VMs found." }
    } catch {
        $content = "Error querying VMs: $($_.Exception.Message)"
    }
} else {
    $content = "Hyper-V module not available on this machine."
}
Write-Block "08-hyperv.txt" "BLOCK 08 - HYPER-V VMs" $content

# ============================================================
# END
# ============================================================
Write-Host ""
Write-Host "Audit complete: $logDir"
