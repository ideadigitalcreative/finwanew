$filePath = 'c:\Users\melis\Herd\finwa\resources\js\pages\Subscriptions\Index.vue'
$content = Get-Content $filePath -Raw -Encoding UTF8

# Remove Lucide import
$content = $content -replace "import \{ Upload, X, Eye \} from 'lucide-vue-next';", ""

# Main container
$content = $content -replace 'bg-gray-50/50 dark:bg-black/10', 'bg-[#fbf9f3]'

# Cards with shadow
$content = $content -replace "bg-white shadow-\[0_2px_10px_rgba\(0,0,0,0\.04\)\] dark:bg-gray-800", 'bg-white border border-[#eae8e2] shadow-sm rounded-2xl'

# Text colors - order matters (specific first)
$content = $content -replace 'text-gray-900 dark:text-white', 'text-[#1b1c19]'
$content = $content -replace 'text-gray-900 dark:text-gray-100', 'text-[#1b1c19]'
$content = $content -replace 'text-gray-800 dark:text-gray-200', 'text-[#1b1c19]'
$content = $content -replace 'text-gray-800', 'text-[#1b1c19]'
$content = $content -replace 'text-gray-700 dark:text-gray-300', 'text-[#4d4634]'
$content = $content -replace 'text-gray-700', 'text-[#4d4634]'
$content = $content -replace 'text-gray-600 dark:text-gray-400', 'text-[#4d4634]/60'
$content = $content -replace 'text-gray-600 dark:text-gray-300', 'text-[#4d4634]/70'
$content = $content -replace 'text-gray-600', 'text-[#4d4634]/60'
$content = $content -replace 'text-gray-500 dark:text-gray-400', 'text-[#4d4634]/60'
$content = $content -replace 'text-gray-500 dark:text-gray-300', 'text-[#4d4634]/60'
$content = $content -replace 'text-gray-500 mt-1', 'text-[#4d4634]/60 mt-1'
$content = $content -replace 'text-gray-500', 'text-[#4d4634]/60'
$content = $content -replace 'text-gray-400', 'text-[#4d4634]/40'
$content = $content -replace 'text-gray-300', 'text-[#4d4634]/30'

# Border colors
$content = $content -replace 'border-gray-200 dark:border-gray-700', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-200/50 dark:border-gray-700/30', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-200/60 dark:border-gray-700/50', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-200 dark:border-gray-600', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-100 dark:border-gray-800', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-100 dark:border-gray-700', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-100', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-200 dark:border-gray-700', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-200', 'border-[#eae8e2]'
$content = $content -replace 'border-gray-800/50', 'border-[#eae8e2]'

# Background colors
$content = $content -replace 'bg-gray-50 dark:bg-gray-900/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-50/50 dark:hover:bg-gray-700/50', 'hover:bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-50/80 dark:bg-gray-800/40', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-50 dark:bg-gray-700/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-50 dark:bg-gray-800/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-100 dark:bg-gray-800', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-100 dark:bg-gray-700/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-100', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-900/50 dark:bg-gray-900/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-900/30 dark:bg-gray-900/30', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-800/50 dark:bg-gray-800/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-700/50 dark:bg-gray-700/50', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-800 dark:bg-gray-800', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-900/40', 'bg-[#f5f3ee]'
$content = $content -replace 'bg-gray-900/30', 'bg-[#f5f3ee]'

# Hover states
$content = $content -replace 'hover:bg-gray-50 dark:hover:bg-gray-700', 'hover:bg-[#f5f3ee]'
$content = $content -replace 'hover:bg-gray-50 dark:hover:bg-gray-800', 'hover:bg-[#f5f3ee]'
$content = $content -replace 'hover:bg-gray-100 dark:hover:bg-gray-800', 'hover:bg-[#f5f3ee]'
$content = $content -replace 'hover:bg-gray-100', 'hover:bg-[#f5f3ee]'
$content = $content -replace 'hover:bg-gray-50/50 dark:hover:bg-gray-800/30', 'hover:bg-[#f5f3ee]'
$content = $content -replace 'hover:bg-gray-50', 'hover:bg-[#f5f3ee]'

# Divide
$content = $content -replace 'divide-gray-100 dark:divide-gray-700', 'divide-[#eae8e2]'
$content = $content -replace 'divide-gray-100 dark:divide-gray-800', 'divide-[#eae8e2]'
$content = $content -replace 'divide-gray-100', 'divide-[#eae8e2]'

# Active states
$content = $content -replace 'active:bg-gray-50 dark:active:bg-gray-800/80', 'active:bg-[#f5f3ee]'
$content = $content -replace 'active:bg-gray-800/80', 'active:bg-[#f5f3ee]'

# Green/emerald colors (buttons, badges)
$content = $content -replace 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400', 'bg-[#51fac1]/30 text-[#006c4f]'
$content = $content -replace 'bg-green-100 text-green-800', 'bg-[#51fac1]/30 text-[#006c4f]'
$content = $content -replace 'text-green-600 dark:text-green-400', 'text-[#006c4f]'
$content = $content -replace 'text-green-600', 'text-[#006c4f]'
$content = $content -replace 'text-green-700', 'text-[#006c4f]'
$content = $content -replace 'text-green-800', 'text-[#006c4f]'
$content = $content -replace 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20 dark:border-green-500', 'border-[#ffd23f] bg-[#ffd23f]/10 ring-1 ring-[#ffd23f]'
$content = $content -replace 'border-green-500', 'border-[#ffd23f]'
$content = $content -replace 'focus:border-green-500 focus:ring-green-500', 'focus:border-[#ffd23f] focus:ring-[#ffd23f]'
$content = $content -replace 'border-green-500/40', 'border-[#ffd23f]/40'
$content = $content -replace 'bg-green-500/10', 'bg-[#ffd23f]/10'
$content = $content -replace 'bg-green-500/20', 'bg-[#ffd23f]/20'
$content = $content -replace 'text-green-700 dark:text-green-300', 'text-[#725a00]'

# Yellow/amber colors
$content = $content -replace 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/30', 'border-[#ffd23f]/40 bg-[#ffd23f]/10'
$content = $content -replace 'bg-amber-100 dark:bg-amber-900/50', 'bg-[#ffd23f]/30'
$content = $content -replace 'text-amber-600 dark:text-amber-400', 'text-[#725a00]'
$content = $content -replace 'text-amber-700 dark:text-amber-400', 'text-[#725a00]'
$content = $content -replace 'text-amber-900 dark:text-amber-50', 'text-[#725a00]'
$content = $content -replace 'text-amber-800 dark:text-amber-200', 'text-[#725a00]/80'
$content = $content -replace 'text-amber-900 dark:text-amber-100', 'text-[#725a00]'
$content = $content -replace 'border-amber-200 dark:border-amber-800/50', 'border-[#ffd23f]/30'
$content = $content -replace 'border-amber-200', 'border-[#ffd23f]/30'

# Blue colors
$content = $content -replace 'bg-blue-50 border-blue-100 dark:bg-blue-900/20 dark:border-blue-800/30', 'bg-[#51fac1]/10 border-[#51fac1]/30'
$content = $content -replace 'text-blue-600 dark:text-blue-400', 'text-[#006c4f]'
$content = $content -replace 'text-blue-900 dark:text-blue-100', 'text-[#006c4f]'
$content = $content -replace 'text-blue-700 dark:text-blue-300', 'text-[#006c4f]/80'
$content = $content -replace 'text-blue-600 hover:text-blue-700', 'text-[#006c4f] hover:text-[#006c4f]/80'

# Red/badge colors
$content = $content -replace 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400', 'bg-[#ffc9d0]/40 text-[#ad2c4f]'

# Yellow badge
$content = $content -replace 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400', 'bg-[#ffd23f]/30 text-[#725a00]'

# oklch button color (payment buttons)
$content = $content -replace "style=""background-color: oklch\(0\.65 0\.19 137\.46\);""", 'class="bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089]"'

# Upload/X/Eye lucide replacements
$content = $content -replace '<Upload class="mr-2 h-4 w-4" />', '<span class="material-symbols-outlined text-lg mr-1">upload</span>'
$content = $content -replace '<Eye class="mr-2 h-4 w-4" />', '<span class="material-symbols-outlined text-lg mr-1">visibility</span>'
$content = $content -replace '<X class="h-4 w-4" />', '<span class="material-symbols-outlined text-lg">close</span>'
$content = $content -replace '<Eye class="w-4 h-4" />', '<span class="material-symbols-outlined text-lg">visibility</span>'
$content = $content -replace '<Upload class="w-4 h-4" />', '<span class="material-symbols-outlined text-lg">upload</span>'
$content = $content -replace '<X class="h-5 w-5" />', '<span class="material-symbols-outlined text-xl">close</span>'

# File input styling
$content = $content -replace 'file:bg-green-50 file:text-green-700 hover:file:bg-green-100', 'file:bg-[#ffd23f]/20 file:text-[#725a00] hover:file:bg-[#ffd23f]/30'

# remaining green background
$content = $content -replace 'bg-green-900/20 dark:bg-green-900/20', 'bg-[#51fac1]/10'

# Dark backgrounds that remain
$content = $content -replace 'dark:bg-gray-800', ''
$content = $content -replace 'dark:bg-gray-900', ''
$content = $content -replace 'dark:bg-black/10', ''

# Remaining dark: prefixed classes
$content = $content -replace ' dark:text-white', ''
$content = $content -replace ' dark:text-gray-\d+', ''
$content = $content -replace ' dark:border-gray-\d+', ''
$content = $content -replace ' dark:bg-gray-\d+', ''
$content = $content -replace ' dark:hover:bg-gray-\d+', ''
$content = $content -replace ' dark:divide-gray-\d+', ''
$content = $content -replace ' dark:active:bg-gray-\d+', ''
$content = $content -replace ' dark:decoration-gray-\d+', ''
$content = $content -replace ' dark:bg-emerald-\d+', ''
$content = $content -replace ' dark:text-emerald-\d+', ''
$content = $content -replace ' dark:bg-rose-\d+', ''
$content = $content -replace ' dark:text-rose-\d+', ''
$content = $content -replace ' dark:bg-amber-\d+', ''
$content = $content -replace ' dark:text-amber-\d+', ''
$content = $content -replace ' dark:bg-blue-\d+', ''
$content = $content -replace ' dark:text-blue-\d+', ''
$content = $content -replace ' dark:bg-green-\d+', ''
$content = $content -replace ' dark:text-green-\d+', ''
$content = $content -replace ' dark:border-amber-\d+', ''
$content = $content -replace ' dark:border-green-\d+', ''
$content = $content -replace ' dark:border-blue-\d+', ''
$content = $content -replace ' dark:border-emerald-\d+', ''
$content = $content -replace ' dark:border-rose-\d+', ''

Set-Content -Path $filePath -Value $content -Encoding UTF8 -NoNewline
Write-Host "Done!"
