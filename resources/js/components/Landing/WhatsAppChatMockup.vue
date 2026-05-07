<template>
    <div ref="mockupContainer" class="flex justify-center items-center">
        <!-- WhatsApp Chat Container -->
        <div class="relative w-[380px] md:w-[420px] h-[580px] md:h-[650px] wa-chat-bg rounded-2xl overflow-hidden shadow-xl">
            <!-- Header (sedikit lebih ringkas di md+ agar tidak terpotong di mockup desktop) -->
            <div
                class="flex shrink-0 items-center justify-between bg-[#075e54] px-3 py-2 md:px-2.5 md:py-1.5"
            >
                <div class="flex min-w-0 flex-1 items-center gap-2 md:gap-1.5">
                    <img
                        src="/finwalogo.png"
                        alt="FinWa"
                        class="h-9 w-9 shrink-0 rounded-full bg-white p-0.5 md:h-8 md:w-8 md:p-px"
                        width="36"
                        height="36"
                        loading="lazy"
                        decoding="async"
                    />
                    <div class="min-w-0">
                        <div class="flex min-w-0 items-center gap-1">
                            <span class="truncate text-sm font-medium text-white md:text-xs">FinWa</span>
                            <img
                                src="/verify.png"
                                alt="Verified"
                                class="h-3.5 w-3.5 shrink-0 md:h-3 md:w-3"
                                title="Verified"
                                width="14"
                                height="14"
                                loading="lazy"
                            />
                        </div>
                        <div class="text-[11px] text-white/80 md:text-[10px]">online</div>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2 md:gap-1.5">
                    <!-- Video Call Icon -->
                    <svg
                        class="h-[18px] w-[18px] shrink-0 md:h-4 md:w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="white"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" />
                        <rect x="2" y="6" width="14" height="12" rx="2" />
                    </svg>
                    <!-- Phone Call Icon -->
                    <svg
                        class="h-[18px] w-[18px] shrink-0 md:h-4 md:w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="white"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"
                        />
                    </svg>
                    <!-- Search Icon -->
                    <svg
                        class="h-[18px] w-[18px] shrink-0 md:h-4 md:w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="white"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </div>
            </div>

            <!-- Chat Area -->
            <div ref="chatContainer" class="h-[470px] md:h-[540px] overflow-y-auto px-2 py-2 sm:px-3 space-y-1">
                <!-- Date Separator (gaya WA) -->
                <div class="flex justify-center mb-3">
                    <div class="rounded-lg bg-[#ffffffd9] px-3 py-1.5 text-xs font-medium text-[#54656f] shadow-[0_1px_1px_rgba(0,0,0,0.08)]">
                        Senin, 13 April 2026
                    </div>
                </div>

                <!-- Messages -->
                <TransitionGroup name="message">
                    <div v-for="(msg, index) in visibleMessages" :key="index">
                        <!-- Pemisah waktu di tengah (seperti label waktu WA) -->
                        <div v-if="msg.type === 'time'" class="flex justify-center py-2">
                            <span class="text-[11px] font-medium text-[#667781]">{{ msg.text }}</span>
                        </div>

                        <!-- Pemisah tanggal (pill seperti WA) -->
                        <div v-else-if="msg.type === 'date'" class="mb-3 flex justify-center">
                            <div
                                class="rounded-lg bg-[#ffffffd9] px-3 py-1.5 text-xs font-medium text-[#54656f] shadow-[0_1px_1px_rgba(0,0,0,0.08)]"
                            >
                                {{ msg.text }}
                            </div>
                        </div>

                        <!-- User: kirim foto struk -->
                        <div v-else-if="msg.type === 'user_image'" class="mb-1.5 flex justify-end px-1">
                            <div class="wa-bubble-user max-w-[88%] px-1.5 pb-1 pt-1">
                                <img
                                    :src="msg.imageUrl"
                                    alt="Struk belanja"
                                    class="block max-h-52 w-auto max-w-[min(100%,260px)] rounded-md object-cover"
                                    width="260"
                                    height="200"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <div class="mt-1 flex items-center justify-end gap-1">
                                    <span class="text-[11px] text-[#667781] tabular-nums">{{ msg.timestamp }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#53bdeb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">
                                        <path d="M18 6 7 17l-5-5"/>
                                        <path d="m22 10-7.5 7.5L13 16"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- User Message (WA: radius 7.5px kecuali pojok bawah-kanan “ekor”) -->
                        <div v-else-if="msg.type === 'user'" class="flex justify-end mb-1.5 px-1">
                            <div class="wa-bubble-user max-w-[85%] px-2 py-1.5">
                                <p class="text-[14.22px] leading-[1.412] text-[#111b21]">{{ msg.text }}</p>
                                <div class="flex items-center justify-end gap-1 mt-0.5">
                                    <span class="text-[11px] text-[#667781] tabular-nums">{{ msg.timestamp }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#53bdeb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">
                                        <path d="M18 6 7 17l-5-5"/>
                                        <path d="m22 10-7.5 7.5L13 16"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Typing Indicator -->
                        <div v-else-if="msg.type === 'typing'" class="flex justify-start mb-1.5 px-1">
                            <div class="wa-incoming-bubble px-4 py-3">
                                <div class="flex gap-1">
                                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Bot: balasan OCR struk (gaya WA / FinWa) -->
                        <div v-else-if="msg.type === 'bot' && msg.receipt" class="mb-1.5 flex justify-start px-1">
                            <div class="wa-incoming-bubble max-w-[92%] px-2 py-1.5">
                                <div class="wa-bot-rich break-words text-[14.22px] font-normal leading-[1.42] text-[#111b21]">
                                    <p class="m-0">
                                        🧾 <strong>Struk Tercatat!</strong> ✅
                                    </p>
                                    <p class="m-0 mt-0.5">
                                        🏪 <strong>{{ msg.receipt.merchant }}</strong>
                                    </p>
                                    <p class="m-0 mt-0.5">📅 {{ msg.receipt.receiptDate }}</p>
                                    <p class="m-0 mt-0.5">🛒 Kategori: {{ msg.receipt.category }}</p>
                                    <p class="m-0 mt-0.5">📋 <strong>Rincian Belanja:</strong></p>
                                    <p
                                        v-for="(line, li) in msg.receipt.lines"
                                        :key="li"
                                        class="m-0 mt-0.5 pl-0.5"
                                    >
                                        {{ li + 1 }}. {{ line.label }} —
                                        <em>Rp {{ formatNumber(line.amount) }}</em>
                                    </p>
                                    <p class="m-0 my-1 text-center text-[11px] tracking-tight text-[#8696a0]">━━━━━━━━━━━━━━━</p>
                                    <p class="m-0 mt-0.5">
                                        💵 <strong>TOTAL: Rp {{ formatNumber(msg.receipt.total) }}</strong>
                                    </p>
                                    <p class="m-0 mt-0.5">
                                        👛 Sisa saldo {{ msg.receipt.wallet }}: Rp
                                        <span class="wa-mock-link cursor-default font-medium">{{
                                            formatNumber(msg.receipt.balanceAfter)
                                        }}</span>
                                    </p>
                                    <p class="m-0 mt-0.5">
                                        📊 Belanja {{ msg.receipt.monthLabel }}: Rp
                                        {{ formatNumber(msg.receipt.monthCategoryTotal) }}
                                    </p>
                                </div>
                                <div class="mt-0.5 flex justify-end">
                                    <span class="text-[11.45px] leading-none text-[#667781] tabular-nums">{{ msg.timestamp }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Bot: gaya pesan FinWa di WA (✅ Berhasil Dicatat, bold/italic, saldo, ringkasan) -->
                        <div v-else-if="msg.type === 'bot' && msg.transaction" class="flex justify-start mb-1.5 px-1">
                            <div class="wa-incoming-bubble max-w-[92%] px-2 py-1.5">
                                <div class="wa-bot-rich break-words text-[14.22px] font-normal leading-[1.42] text-[#111b21]">
                                    <p class="m-0">
                                        ✅ <strong>Berhasil Dicatat!</strong> 🎉
                                    </p>
                                    <p class="m-0 mt-0.5">
                                        💸 <strong>Pengeluaran</strong>
                                    </p>
                                    <p class="m-0 mt-0.5">💵 Rp {{ formatNumber(msg.transaction.amount) }}</p>
                                    <p class="m-0 mt-0.5">
                                        {{ categoryEmoji(msg.transaction) }} {{ categoryDisplayLine(msg.transaction) }}
                                        <span class="text-[#667781]"> • </span>
                                        <em>{{ msg.transaction.description }}</em>
                                    </p>
                                    <p v-if="msg.transaction.balanceAfter != null" class="m-0 mt-0.5">
                                        👛 Sisa saldo {{ msg.transaction.wallet }}: Rp
                                        <span class="wa-mock-link cursor-default font-medium">{{ formatNumber(msg.transaction.balanceAfter) }}</span>
                                    </p>
                                    <p v-if="msg.transaction.monthExpenseTotal != null && msg.transaction.monthLabel" class="m-0 mt-0.5">
                                        📊
                                        <em>
                                            Total pengeluaran {{ msg.transaction.monthLabel }}: Rp
                                            {{ formatNumber(msg.transaction.monthExpenseTotal) }}
                                        </em>
                                    </p>
                                </div>
                                <div class="mt-0.5 flex justify-end">
                                    <span class="text-[11.45px] leading-none text-[#667781] tabular-nums">{{ msg.timestamp }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </TransitionGroup>
            </div>

            <!-- Input Area -->
            <div class="absolute bottom-0 left-0 right-0 border-t border-black/[0.06] bg-[#f0f2f5] px-2 py-2 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-500">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                    <line x1="9" x2="9.01" y1="9" y2="9"/>
                    <line x1="15" x2="15.01" y1="9" y2="9"/>
                </svg>
                <input 
                    type="text" 
                    placeholder="Ketik pesan..." 
                    disabled
                    class="flex-1 bg-white rounded-full px-4 py-2 text-sm text-gray-400 cursor-not-allowed"
                >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-500">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                    <line x1="12" x2="12" y1="19" y2="22"/>
                </svg>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick, onUnmounted } from 'vue'

interface ReceiptLine {
    label: string
    amount: number
}

interface ReceiptParsed {
    merchant: string
    receiptDate: string
    category: string
    lines: ReceiptLine[]
    total: number
    wallet: string
    balanceAfter: number
    monthLabel: string
    monthCategoryTotal: number
}

interface Transaction {
    amount: number
    category: string
    wallet: string
    description: string
    date: string
    time: string
    /** Label kategori panjang, mis. "Makanan & Minuman" */
    categoryDisplay?: string
    categoryEmoji?: string
    /** Saldo setelah transaksi (contoh WA) */
    balanceAfter?: number
    monthExpenseTotal?: number
    monthLabel?: string
}

interface Message {
    type: 'user' | 'bot' | 'typing' | 'time' | 'date' | 'user_image'
    text?: string
    imageUrl?: string
    transaction?: Transaction
    receipt?: ReceiptParsed
    timestamp?: string
}

const chatContainer = ref<HTMLElement | null>(null)
const mockupContainer = ref<HTMLElement | null>(null)
const visibleMessages = ref<Message[]>([])
const hasAnimated = ref(false)

/**
 * Urutan: (1) chat sayur + tercatat → (2) struk foto + Struk Tercatat → (3) parkir + tercatat.
 * Waktu naik supaya satu hari (pill tanggal tetap di atas).
 */
const allMessages: Message[] = [
    { type: 'time', text: '08:57' },
    { type: 'user', text: 'beli sayur 50 rb', timestamp: '08:57' },
    { type: 'typing' },
    {
        type: 'bot',
        timestamp: '08:57',
        transaction: {
            amount: 50000,
            category: 'Makanan',
            categoryDisplay: 'Makanan & Minuman',
            categoryEmoji: '🍽️',
            wallet: 'BRI',
            description: 'beli sayur 50 rb',
            date: '2026-04-13',
            time: '08:57:00',
            balanceAfter: 4_706_000,
            monthExpenseTotal: 793_000,
            monthLabel: 'April',
        },
    },
    { type: 'time', text: '09:02' },
    { type: 'user_image', imageUrl: '/struk.jpeg', timestamp: '09:02' },
    { type: 'typing' },
    {
        type: 'bot',
        timestamp: '09:02',
        receipt: {
            merchant: 'PERTAMINA',
            receiptDate: '26 April 2026',
            category: 'Belanja',
            lines: [{ label: 'Pertamax', amount: 300_000 }],
            total: 300_000,
            wallet: 'BRI',
            balanceAfter: 4_456_000,
            monthLabel: 'April',
            monthCategoryTotal: 743_000,
        },
    },
    { type: 'time', text: '09:05' },
    { type: 'user', text: 'parkir 15rb', timestamp: '09:05' },
    { type: 'typing' },
    {
        type: 'bot',
        timestamp: '09:06',
        transaction: {
            amount: 15000,
            category: 'Transportasi',
            categoryDisplay: 'Transportasi',
            categoryEmoji: '🚗',
            wallet: 'Cash',
            description: 'parkir 15rb',
            date: '2026-04-13',
            time: '09:06:00',
            balanceAfter: 485_000,
            monthExpenseTotal: 808_000,
            monthLabel: 'April',
        },
    },
]

const formatNumber = (num: number): string => {
    return num.toLocaleString('id-ID')
}

const categoryDisplayLine = (t: Transaction): string => {
    return t.categoryDisplay ?? t.category
}

const categoryEmoji = (t: Transaction): string => {
    if (t.categoryEmoji) {
        return t.categoryEmoji
    }
    const c = t.category.toLowerCase()
    if (c.includes('makan')) {
        return '🍽️'
    }
    if (c.includes('transport')) {
        return '🚗'
    }
    return '📌'
}

const scrollToBottom = () => {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTo({
                top: chatContainer.value.scrollHeight,
                behavior: 'smooth'
            })
        }
    })
}

const animateMessages = async () => {
    if (hasAnimated.value) return
    hasAnimated.value = true
    
    for (let i = 0; i < allMessages.length; i++) {
        const msg = allMessages[i]
        
        // Add message
        visibleMessages.value.push(msg)
        scrollToBottom()
        
        // If it's a typing indicator, wait 1.5s then remove it
        if (msg.type === 'typing') {
            await new Promise(resolve => setTimeout(resolve, 1500))
            visibleMessages.value.pop() // Remove typing indicator
        } else {
            // Wait before next message
            await new Promise(resolve => setTimeout(resolve, 800))
        }
    }
}

let observer: IntersectionObserver | null = null

onMounted(() => {
    // Use IntersectionObserver to only start animation when visible
    if (mockupContainer.value && 'IntersectionObserver' in window) {
        observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && !hasAnimated.value) {
                        setTimeout(() => {
                            animateMessages()
                        }, 300)
                    }
                })
            },
            { threshold: 0.3 }
        )
        observer.observe(mockupContainer.value)
    } else {
        // Fallback for browsers without IntersectionObserver
        setTimeout(() => {
            animateMessages()
        }, 500)
    }
})

onUnmounted(() => {
    if (observer) {
        observer.disconnect()
    }
})
</script>

<style scoped>
/* Latar chat mirip WA (warna + tekstur halus) */
.wa-chat-bg {
    background-color: #e6ded4;
    background-image: radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.12) 0, transparent 45%),
        radial-gradient(circle at 80% 70%, rgba(0, 0, 0, 0.03) 0, transparent 40%);
}

/* Keluar: hijau WA, pojok bawah-kanan rata (ekor) */
.wa-bubble-user {
    background-color: #d9fdd3;
    box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
    border-radius: 7.5px 7.5px 0 7.5px;
}

/* Masuk: putih WA Web — radius standar (kiri atas 0) */
.wa-incoming-bubble {
    background-color: #fff;
    box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
    border-radius: 0 7.5px 7.5px 7.5px;
}

.wa-bot-rich em {
    font-style: italic;
}

/* Mirip link nominal di WA Web (non-klik di mockup) */
.wa-mock-link {
    color: #039be5;
}

/* Smooth scrollbar */
.overflow-y-auto::-webkit-scrollbar {
    width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 3px;
}

/* Message animations */
.message-enter-active {
    transition: all 0.3s ease-out;
}

.message-enter-from {
    opacity: 0;
    transform: translateY(20px);
}

.message-enter-to {
    opacity: 1;
    transform: translateY(0);
}

/* Bounce animation for typing dots */
@keyframes bounce {
    0%, 60%, 100% {
        transform: translateY(0);
    }
    30% {
        transform: translateY(-10px);
    }
}

.animate-bounce {
    animation: bounce 1.4s infinite;
}
</style>
