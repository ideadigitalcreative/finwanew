$file = 'c:\Users\melis\Herd\finwa\resources\js\pages\Subscriptions\Index.vue'
$c = Get-Content $file -Raw -Encoding UTF8

# Amber pending alert box
$c = $c -replace 'border-amber-200 bg-amber-50 p-6 shadow-sm dark:border-amber-900/50 dark:bg-amber-950/30', 'border-[#ffd23f]/40 bg-[#ffd23f]/10 p-6 shadow-sm'

# oklch inline style button
$c = $c -replace "background-color: oklch\(0\.65 0\.19 137\.46\);", "background-color: #ffd23f; color: #725a00;"

# Blue box
$c = $c -replace 'bg-blue-50 p-4 border border-blue-100 dark:bg-blue-900/20 dark:border-blue-800/30', 'bg-[#51fac1]/10 p-4 border border-[#51fac1]/30'

# Dark hover remnants
$c = $c -replace ' dark:hover:bg-gray-700/50', ''
$c = $c -replace ' dark:hover:border-gray-600', ''

# Dark text orange
$c = $c -replace ' dark:text-orange-400', ''

# Yellow warning boxes
$c = $c -replace 'bg-yellow-50 border border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-800', 'bg-[#ffd23f]/10 border border-[#ffd23f]/30'

# Blue link text
$c = $c -replace 'text-blue-600 hover:text-blue-700', 'text-[#006c4f] hover:text-[#006c4f]/80'

# Red close button (keep red for delete/close action)
$c = $c -replace 'bg-red-500 text-white rounded-full hover:bg-red-600', 'bg-[#ffc9d0] text-[#ad2c4f] rounded-full hover:bg-[#ffc9d0]/80'

# SVG copy icon dark hover
$c = $c -replace 'dark:hover:text-gray-300', ''

# hover border gray
$c = $c -replace 'hover:border-gray-300', 'hover:border-[#eae8e2]'

Set-Content -Path $file -Value $c -Encoding UTF8
Write-Host "Done!"
