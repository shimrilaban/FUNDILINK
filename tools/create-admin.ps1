$ErrorActionPreference = 'Stop'

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    $phpPath = $php.Source
} elseif (Test-Path -LiteralPath 'C:\xampp\php\php.exe') {
    $phpPath = 'C:\xampp\php\php.exe'
} else {
    throw 'PHP was not found. Install PHP or XAMPP, then run this script again.'
}

$name = (Read-Host 'Administrator full name').Trim()
$email = (Read-Host 'Administrator email address').Trim()
$phone = (Read-Host 'Optional Kenyan phone number (press Enter to skip)').Trim()
$securePassword = Read-Host 'Choose an administrator password (12+ characters)' -AsSecureString
$passwordPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $confirmation = Read-Host 'Confirm administrator password' -AsSecureString
    $confirmationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmation)
    try {
        $plainConfirmation = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmationPointer)
        if ($plainPassword -cne $plainConfirmation) {
            throw 'The passwords do not match.'
        }
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmationPointer)
        $confirmation.Dispose()
    }

    $env:FUNDILINK_ADMIN_NAME = $name
    $env:FUNDILINK_ADMIN_EMAIL = $email
    $env:FUNDILINK_ADMIN_PHONE = $phone
    $env:FUNDILINK_ADMIN_PASSWORD = $plainPassword
    & $phpPath (Join-Path $PSScriptRoot 'create-admin.php')
    if ($LASTEXITCODE -ne 0) {
        throw 'Administrator setup did not complete.'
    }
} finally {
    Remove-Item Env:\FUNDILINK_ADMIN_NAME -ErrorAction SilentlyContinue
    Remove-Item Env:\FUNDILINK_ADMIN_EMAIL -ErrorAction SilentlyContinue
    Remove-Item Env:\FUNDILINK_ADMIN_PHONE -ErrorAction SilentlyContinue
    Remove-Item Env:\FUNDILINK_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    $plainPassword = $null
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    $securePassword.Dispose()
}
