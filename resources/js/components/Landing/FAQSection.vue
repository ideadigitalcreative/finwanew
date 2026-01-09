<template>
    <section id="faq" class="relative border-t border-gray-200/60 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-bold tracking-tight text-gray-900 leading-tight">Tanya Jawab Seputar FinWa</h2>
                <p class="mt-3 text-gray-600">Berikut ini beberapa pertanyaan yang sering ditanyakan.</p>
            </div>

            <div class="mt-12 max-w-3xl mx-auto space-y-4">
                <div
                    v-for="(faq, index) in faqs"
                    :key="index"
                    class="group rounded-2xl ring-1 ring-gray-200 bg-white hover:bg-gray-50 hover:ring-emerald-500/20 transition-all duration-300 overflow-hidden"
                    :class="{ 'ring-emerald-500/30 bg-emerald-50/30': openIndex === index }"
                >
                    <button
                        type="button"
                        @click="toggleFaq(index)"
                        :aria-expanded="openIndex === index"
                        class="w-full flex items-center justify-between p-5 text-left focus:outline-none"
                    >
                        <span class="text-base font-semibold text-gray-900 group-hover:text-emerald-700 transition-colors">{{ faq.question }}</span>
                        <span 
                            class="shrink-0 h-8 w-8 grid place-items-center rounded-full bg-gray-100 ring-1 ring-gray-200 text-gray-500 group-hover:bg-emerald-100 group-hover:text-emerald-600 group-hover:ring-emerald-200 transition-all duration-300" 
                            :class="{ 'rotate-45 bg-emerald-100 text-emerald-600 ring-emerald-200': openIndex === index }"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </span>
                    </button>
                    <div
                        v-show="openIndex === index"
                        class="px-5 pb-5 text-sm text-gray-600 leading-relaxed animate-fade-in-down"
                    >
                        {{ faq.answer }}
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';

const openIndex = ref<number | null>(null);

const faqs = [
    {
        question: 'Apa itu FinWa?',
        answer: 'FinWa adalah platform pencatatan keuangan berbasis AI yang terintegrasi dengan WhatsApp untuk memudahkan pengelolaan keuangan pribadi atau bisnis.',
    },
    {
        question: 'Bagaimana mencatat keuangan saya dengan menggunakan FinWa?',
        answer: 'Cukup kirim pesan ke nomor WhatsApp yang telah disediakan dengan format bebas, misalnya "Makan ayam penyet 20 K". Sistem AI akan otomatis mencatat transaksi tersebut.',
    },
    {
        question: 'Apakah ada format baku yang harus saya input agar proses pencatatan berjalan baik tanpa error?',
        answer: 'Tidak ada format baku yang diperlukan. Sistem AI FinWa dapat memahami berbagai format pesan natural language. Namun, pastikan untuk menyertakan nominal dan deskripsi transaksi.',
    },
    {
        question: 'Apakah bisa menginput pencatatan dari banyak nomor Whatsapp?',
        answer: 'Ya, paket Premium mendukung multi-user dengan beberapa nomor WhatsApp yang dapat terhubung ke satu dashboard.',
    },
    {
        question: 'Apakah saya bisa mengubah data di dashboard saya?',
        answer: 'Ya, Anda dapat mengedit atau menghapus transaksi yang sudah tercatat melalui dashboard web yang tersedia.',
    },
    {
        question: 'Apakah data saya aman?',
        answer: 'Tentu! Keamanan data adalah prioritas utama kami. Semua data Anda dienkripsi menggunakan standar keamanan tinggi (SSL/TLS). Data disimpan di server yang aman dan tidak pernah dibagikan ke pihak ketiga. Kami juga menerapkan kebijakan privasi yang ketat untuk melindungi informasi keuangan Anda.',
    },
    {
        question: 'Cara berhenti susah gak?',
        answer: 'Sama sekali tidak susah! Anda bisa berhenti berlangganan kapan saja tanpa syarat ribet. Cukup hubungi tim support kami via WhatsApp atau email, dan langganan akan dihentikan. Tidak ada biaya tersembunyi atau penalti pembatalan. Data Anda juga bisa di-export sebelum berhenti.',
    },
    {
        question: 'Bisa dipakai suami/istri?',
        answer: 'Bisa banget! FinWa memang dirancang untuk keluarga. Suami dan istri bisa sama-sama mengirim pesan pencatatan dari nomor WhatsApp masing-masing, dan semua transaksi akan tercatat dalam satu dashboard yang sama. Cocok untuk mengatur keuangan rumah tangga bersama-sama dengan lebih transparan dan terorganisir.',
    },
];

const toggleFaq = (index: number) => {
    openIndex.value = openIndex.value === index ? null : index;
};
</script>

