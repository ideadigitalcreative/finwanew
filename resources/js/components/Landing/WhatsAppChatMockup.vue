<template>
    <div ref="mockupContainer" class="flex justify-center items-center">
        <!-- WhatsApp Chat Container -->
        <div class="relative w-[380px] md:w-[420px] h-[580px] md:h-[650px] bg-[#e5ddd5] rounded-2xl overflow-hidden shadow-xl">
            <!-- Header -->
            <div class="bg-[#075e54] px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img src="/finwalogo.png" alt="FinWa" class="w-10 h-10 rounded-full bg-white p-1" width="40" height="40" loading="lazy" decoding="async">
                    <div>
                        <div class="text-white font-medium text-sm">FinWa</div>
                        <div class="text-white/80 text-xs">online</div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <!-- Video Call Icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/>
                        <rect x="2" y="6" width="14" height="12" rx="2"/>
                    </svg>
                    <!-- Phone Call Icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                    <!-- Search Icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.3-4.3"/>
                    </svg>
                </div>
            </div>

            <!-- Chat Area -->
            <div ref="chatContainer" class="h-[470px] md:h-[540px] overflow-y-auto px-4 py-3 space-y-3">
                <!-- Date Separator -->
                <div class="flex justify-center mb-4">
                    <div class="bg-white/90 px-3 py-1 rounded-full text-xs text-gray-600 shadow-sm">
                        Mon, 14 Apr
                    </div>
                </div>

                <!-- Messages -->
                <TransitionGroup name="message">
                    <div v-for="(msg, index) in visibleMessages" :key="index">
                        <!-- User Message -->
                        <div v-if="msg.type === 'user'" class="flex justify-end mb-2">
                            <div class="max-w-[80%] bg-[#dcf8c6] rounded-lg rounded-tr-none px-3 py-2 shadow-sm">
                                <p class="text-sm text-gray-800">{{ msg.text }}</p>
                                <div class="flex items-center justify-end gap-1 mt-1">
                                    <span class="text-[10px] text-gray-600">{{ msg.timestamp }}</span>
                                    <!-- Double Check Mark -->
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4fc3f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 6 7 17l-5-5"/>
                                        <path d="m22 10-7.5 7.5L13 16"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Typing Indicator -->
                        <div v-else-if="msg.type === 'typing'" class="flex justify-start mb-2">
                            <div class="bg-white rounded-lg rounded-tl-none px-4 py-3 shadow-sm">
                                <div class="flex gap-1">
                                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Bot Message with Transaction Card -->
                        <div v-else-if="msg.type === 'bot' && msg.transaction" class="flex justify-start mb-2">
                            <div class="max-w-[80%] bg-white rounded-lg rounded-tl-none px-3 py-2 shadow-md">
                                <!-- Transaction Card -->
                                <div class="bg-gradient-to-br from-emerald-50 to-cyan-50 rounded-lg p-3 border border-emerald-100">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-medium text-emerald-700">Transaksi Tercatat</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                            <path d="m9 11 3 3L22 4"/>
                                        </svg>
                                    </div>
                                    <div class="space-y-1.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg font-bold text-gray-800">💰 Rp {{ formatNumber(msg.transaction.amount) }}</span>
                                        </div>
                                        <div class="text-xs text-gray-600 space-y-0.5">
                                            <div><span class="font-medium">Kategori:</span> {{ msg.transaction.category }}</div>
                                            <div><span class="font-medium">Dompet:</span> {{ msg.transaction.wallet }}</div>
                                            <div><span class="font-medium">Keterangan:</span> {{ msg.transaction.description }}</div>
                                            <div class="flex gap-3 mt-1">
                                                <span>📅 {{ msg.transaction.date }}</span>
                                                <span>🕐 {{ msg.transaction.time }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-end gap-1 mt-1">
                                    <span class="text-[10px] text-gray-600">{{ msg.timestamp }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </TransitionGroup>
            </div>

            <!-- Input Area -->
            <div class="absolute bottom-0 left-0 right-0 bg-[#f0f0f0] px-3 py-2 flex items-center gap-2">
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

interface Transaction {
    amount: number
    category: string
    wallet: string
    description: string
    date: string
    time: string
}

interface Message {
    type: 'user' | 'bot' | 'typing'
    text?: string
    transaction?: Transaction
    timestamp?: string
}

const chatContainer = ref<HTMLElement | null>(null)
const mockupContainer = ref<HTMLElement | null>(null)
const visibleMessages = ref<Message[]>([])
const hasAnimated = ref(false)

const allMessages: Message[] = [
    { type: 'user', text: 'bensin 200 ribu', timestamp: '08:56' },
    { type: 'typing' },
    { 
        type: 'bot',
        timestamp: '08:56',
        transaction: {
            amount: 200000,
            category: 'Transportasi',
            wallet: 'Cash',
            description: 'bensin 200 ribu',
            date: '2025-04-13',
            time: '08:56:00'
        }
    },
    { type: 'user', text: 'makan siang 50rb', timestamp: '08:57' },
    { type: 'typing' },
    { 
        type: 'bot',
        timestamp: '08:57',
        transaction: {
            amount: 50000,
            category: 'Makanan',
            wallet: 'Cash',
            description: 'makan siang 50rb',
            date: '2025-04-13',
            time: '08:56:00'
        }
    },
    { type: 'user', text: 'parkir 15rb', timestamp: '08:58' },
    { type: 'typing' },
    { 
        type: 'bot',
        timestamp: '08:58',
        transaction: {
            amount: 15000,
            category: 'Transportasi',
            wallet: 'Cash',
            description: 'parkir 15rb',
            date: '2025-04-13',
            time: '08:56:00'
        }
    }
]

const formatNumber = (num: number): string => {
    return num.toLocaleString('id-ID')
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
