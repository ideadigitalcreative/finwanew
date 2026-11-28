$file = 'c:\Users\melis\Herd\finwa\resources\js\pages\Subscriptions\Index.vue'
$c = Get-Content $file -Raw -Encoding UTF8

# Border replacements (longer matches first)
$c = $c -replace 'border-gray-200/50 dark:border-gray-700/30', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-200 dark:border-gray-700', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-200 dark:border-gray-800/50', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-200 dark:border-gray-600', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-200 bg-white dark:bg-gray-800', 'border-[#eae8e2] bg-white'
$c = $c -replace 'border-gray-200 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/30', 'border-[#eae8e2] bg-[#f5f3ee]/50'
$c = $c -replace 'border-gray-200 bg-gray-50/50', 'border-[#eae8e2] bg-[#f5f3ee]/50'
$c = $c -replace 'border-gray-200 bg-white border-green-500 ring-1 ring-green-500 dark:bg-green-900/20 dark:border-green-500', 'border-[#eae8e2] bg-white border-[#ffd23f] ring-1 ring-[#ffd23f]'
$c = $c -replace 'border-gray-200 bg-white', 'border-[#eae8e2] bg-white'
$c = $c -replace 'border-gray-200 bg-gray-50', 'border-[#eae8e2] bg-[#f5f3ee]'
$c = $c -replace 'border-gray-200', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-100 dark:border-gray-700', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-100 dark:border-gray-800', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-100 dark:border-gray-800/50', 'border-[#eae8e2]'
$c = $c -replace 'border-gray-100', 'border-[#eae8e2]'

# Divide replacements
$c = $c -replace 'divide-gray-100 dark:divide-gray-800', 'divide-[#eae8e2]'
$c = $c -replace 'divide-gray-100 dark:divide-gray-700', 'divide-[#eae8e2]'
$c = $c -replace 'divide-gray-100', 'divide-[#eae8e2]'

# BG replacements (longer matches first)
$c = $c -replace 'bg-gray-50/50 dark:bg-black/10', 'bg-[#fbf9f3]'
$c = $c -replace 'bg-gray-50/80 dark:bg-gray-800/40', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50/50', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50 dark:bg-gray-900/50', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50 dark:bg-gray-700/50', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50 dark:bg-gray-900/30', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50 dark:bg-gray-900', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50 dark:bg-gray-800/50', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50 dark:bg-gray-800', 'bg-[#f5f3ee]'
$c = $c -replace 'bg-gray-50', 'bg-[#f5f3ee]'

# Text replacements (longer matches first)
$c = $c -replace 'text-gray-900 dark:text-white', 'text-[#1b1c19]'
$c = $c -replace 'text-gray-900 dark:text-gray-100', 'text-[#1b1c19]'
$c = $c -replace 'text-gray-800 dark:text-white', 'text-[#1b1c19]'
$c = $c -replace 'text-gray-800 dark:text-gray-200', 'text-[#1b1c19]'
$c = $c -replace 'text-gray-800', 'text-[#1b1c19]'
$c = $c -replace 'text-gray-700 dark:text-white', 'text-[#4d4634]'
$c = $c -replace 'text-gray-700 dark:text-gray-300', 'text-[#4d4634]'
$c = $c -replace 'text-gray-700', 'text-[#4d4634]'
$c = $c -replace 'text-gray-600 dark:text-white', 'text-[#4d4634]/70'
$c = $c -replace 'text-gray-600 dark:text-gray-400', 'text-[#4d4634]/60'
$c = $c -replace 'text-gray-600 dark:text-gray-300', 'text-[#4d4634]/70'
$c = $c -replace 'text-gray-600', 'text-[#4d4634]/60'
$c = $c -replace 'text-gray-500 dark:text-white', 'text-[#4d4634]/60'
$c = $c -replace 'text-gray-500 dark:text-gray-400', 'text-[#4d4634]/60'
$c = $c -replace 'text-gray-500 dark:text-gray-300', 'text-[#4d4634]/60'
$c = $c -replace 'text-gray-500', 'text-[#4d4634]/60'
$c = $c -replace 'text-gray-400', 'text-[#4d4634]/40'

# Shadow
$c = $c -replace 'shadow-\[0_2px_10px_rgba\(0,0,0,0\.04\)\] dark:bg-gray-800', 'border border-[#eae8e2] shadow-sm'
$c = $c -replace 'shadow-\[0_2px_10px_rgba\(0,0,0,0\.04\)\]', 'border border-[#eae8e2] shadow-sm'

# Rounded
$c = $c -replace 'rounded-2xl border border-\[#eae8e2\] shadow-sm border border-\[#eae8e2\] shadow-sm', 'rounded-2xl border border-[#eae8e2] shadow-sm'

# Active/badges green -> CuanCeria
$c = $c -replace 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400', 'bg-[#51fac1]/30 text-[#006c4f]'
$c = $c -replace 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400', 'bg-[#ffd23f]/30 text-[#725a00]'
$c = $c -replace 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400', 'bg-[#ffc9d0]/40 text-[#ad2c4f]'
$c = $c -replace 'bg-red-100 text-red-800', 'bg-[#ffc9d0]/40 text-[#ad2c4f]'
$c = $c -replace 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300', 'bg-[#ffc9d0]/20 text-[#ad2c4f]'

# Pending alert (amber/yellow)
$c = $c -replace 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-50', 'border-[#ffd23f]/40 bg-[#ffd23f]/10 text-[#725a00]'
$c = $c -replace 'bg-amber-100 dark:bg-amber-900/50', 'bg-[#ffd23f]/30'
$c = $c -replace 'text-amber-600 dark:text-amber-400', 'text-[#725a00]'
$c = $c -replace 'text-amber-700 dark:text-amber-400', 'text-[#725a00]'
$c = $c -replace 'text-amber-900 dark:text-amber-100', 'text-[#725a00]'
$c = $c -replace 'text-amber-900 dark:text-amber-50', 'text-[#725a00]'
$c = $c -replace 'text-amber-800 dark:text-amber-200', 'text-[#725a00]/80'
$c = $c -replace 'border-amber-200 dark:border-amber-800/50', 'border-[#ffd23f]/30'

# Blue -> Mint
$c = $c -replace 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400', 'bg-[#51fac1]/10 text-[#006c4f]'
$c = $c -replace 'bg-blue-50 border-blue-100 dark:bg-blue-900/20 dark:border-blue-800/30', 'bg-[#51fac1]/10 border-[#51fac1]/30'
$c = $c -replace 'text-blue-600 dark:text-blue-400', 'text-[#006c4f]'
$c = $c -replace 'text-blue-900 dark:text-blue-100', 'text-[#006c4f]'
$c = $c -replace 'text-blue-700 dark:text-blue-300', 'text-[#006c4f]/80'

# Green buttons -> Gold
$c = $c -replace 'bg-green-600 hover:bg-green-700', 'bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089]'
$c = $c -replace 'bg-green-600', 'bg-[#ffd23f] text-[#725a00]'
$c = $c -replace 'bg-green-50 text-green-700 hover:bg-green-100', 'bg-[#ffd23f]/20 text-[#725a00] hover:bg-[#ffd23f]/30'
$c = $c -replace 'file:bg-green-50 file:text-green-700 hover:file:bg-green-100', 'file:bg-[#ffd23f]/20 file:text-[#725a00] hover:file:bg-[#ffd23f]/30'
$c = $c -replace 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20 dark:border-green-500', 'border-[#ffd23f] bg-[#ffd23f]/10 ring-1 ring-[#ffd23f]'
$c = $c -replace 'text-green-600 dark:text-green-400', 'text-[#006c4f]'
$c = $c -replace 'text-green-600', 'text-[#006c4f]'
$c = $c -replace 'border-green-500 bg-green-500', 'border-[#ffd23f] bg-[#ffd23f]'

# oklch button -> Gold
$c = $c -replace "style=""background-color: oklch\(0\.65 0\.19 137\.46\);""", 'class="bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089]"'
$c = $c -replace "style=""background-color: oklch\(0\.65 0\.19 137\.46\); font-weight: 600;""", 'class="bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-semibold"'

# Yellow warning
$c = $c -replace 'bg-yellow-50 border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-800', 'bg-[#ffd23f]/10 border-[#ffd23f]/30'
$c = $c -replace 'text-yellow-700 dark:text-yellow-300', 'text-[#725a00]'
$c = $c -replace 'bg-yellow-50 dark:bg-yellow-900/20', 'bg-[#ffd23f]/10'

# Input fields
$c = $c -replace 'focus:border-green-500 focus:ring-green-500', 'focus:border-[#ffd23f] focus:ring-[#ffd23f]'
$c = $c -replace 'focus:border-green-500', 'focus:border-[#ffd23f]'
$c = $c -replace 'focus:ring-green-500', 'focus:ring-[#ffd23f]'

# Hover states
$c = $c -replace 'hover:bg-gray-50 dark:hover:bg-gray-700/50', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-50/50 dark:hover:bg-gray-700/50', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-50 dark:hover:bg-gray-800/50', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-50 dark:hover:bg-gray-700', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-50 dark:hover:bg-gray-800', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-100 dark:hover:bg-gray-700', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-100', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:bg-gray-50', 'hover:bg-[#f5f3ee]'
$c = $c -replace 'hover:border-gray-300 dark:hover:border-gray-600', 'hover:border-[#eae8e2]'
$c = $c -replace 'hover:text-gray-600 dark:hover:text-gray-300', 'hover:text-[#4d4634]'

# Dark-only bg remnants
$c = $c -replace 'dark:bg-black/10', ''
$c = $c -replace 'dark:bg-gray-900/50', ''
$c = $c -replace 'dark:bg-gray-900/40', ''
$c = $c -replace 'dark:bg-gray-900/30', ''
$c = $c -replace 'dark:bg-gray-900', ''
$c = $c -replace 'dark:bg-gray-800/50', ''
$c = $c -replace 'dark:bg-gray-800/40', ''
$c = $c -replace 'dark:bg-gray-800/30', ''
$c = $c -replace 'dark:bg-gray-800', ''
$c = $c -replace 'dark:bg-gray-700/50', ''
$c = $c -replace 'dark:bg-gray-700/30', ''
$c = $c -replace 'dark:bg-gray-700', ''
$c = $c -replace 'dark:bg-gray-900', ''

# Dark-only text remnants
$c = $c -replace 'dark:text-white', ''
$c = $c -replace 'dark:text-gray-400', ''
$c = $c -replace 'dark:text-gray-300', ''
$c = $c -replace 'dark:text-gray-200', ''

# Dark-only border remnants
$c = $c -replace 'dark:border-gray-800', ''
$c = $c -replace 'dark:border-gray-700', ''
$c = $c -replace 'dark:border-gray-600', ''

# Dark-only divide remnants
$c = $c -replace 'dark:divide-gray-800', ''
$c = $c -replace 'dark:divide-gray-700', ''

# Active states
$c = $c -replace 'active:bg-gray-50 dark:active:bg-gray-800/80', 'active:bg-[#f5f3ee]'

# bg-background
$c = $c -replace 'bg-background', 'bg-[#fbf9f3]'

# Green icon container
$c = $c -replace 'bg-white border-gray-200 dark:border-gray-600 text-green-600', 'bg-white border-[#eae8e2] text-[#006c4f]'
$c = $c -replace 'text-green-600', 'text-[#006c4f]'

# Tailwind config oklch reference
$c = $c -replace "color-mix\(in srgb, oklch\(0\.65 0\.19 137\.46\) 100%, black\)", '#006c4f'
$c = $c -replace "color-mix\(in srgb, oklch\(0\.65 0\.19 137\.46\) 100%, transparent\)", '#006c4f'

Set-Content -Path $file -Value $c -Encoding UTF8
Write-Host "Done!"
