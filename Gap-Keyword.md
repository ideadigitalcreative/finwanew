Konflik \& Duplikasi (perlu diperbaiki)

konflik

Duplikat di expense\_keywords

'sunblock' → muncul 2x

Baris 1: pengeluaran\_perawatan\_diri — Baris 2: pengeluaran\_perawatan\_diri (aman, tapi buang slot)

'lulur' → muncul 2x

Dua entry identik di perawatan\_diri — salah satu bisa dihapus

'karaoke' → muncul 2x

Dua entry di pengeluaran\_hiburan — hapus salah satu

'kerudung' → muncul 2x

Dua entry di pengeluaran\_pakaian — hapus salah satu

ambiguitas

Keyword terlalu generik

'motor' → pengeluaran\_transport

Harusnya juga bisa cicilan/otomotif. Pertimbangkan context boost atau frasa lebih panjang

'mobil' → pengeluaran\_transport

Sama seperti 'motor' — cicilan mobil, servis mobil harusnya kalahkan ini

'tahu' → pengeluaran\_makanan

"Tahu aku lagi" dll — false positive tinggi untuk kata kerja 'tahu'

'es' → pengeluaran\_makanan

Sangat pendek — bisa false match di banyak kalimat

Gap 1 — Makanan \& Minuman (perlu tambah)

Brand \& item populer yang hilang

geprek bensu

d'cost

warung padang

warung lamongan

pecel lele

lele

bakmie

bakmie gajah mada

sari roti

minyak zaitun

kopi luwak

kopi toraja

kopi arabika

kopi robusta

nescafe

good day

cappuccino cincau

kurma

kolak

takjil

sahur

berbuka

iftar

sarden

kornet sapi

pindang

teri

cakalang

tongkol

ikan asin

belut

lobster

kepiting

tiram

cumi

sotong

nasi bakar

nasi liwet

nasi bungkus

warung nasi

Juga hilang: kata untuk konteks Makassar/Sulawesi seperti coto, konro, pallubasa, kapurung, gogos, pisang epe

Gap 2 — Makanan Lokal Sulawesi / Regional (sangat penting untuk user Anda)

Kuliner khas daerah belum ada sama sekali

coto makassar

coto

konro

sop konro

pallubasa

kapurung

gogos

pisang epe

jalangkote

barongko

sop saudara

es pisang ijo

pallu butung

bassang

sarabba

mie titi

mi kering

mi lembek

nasi kuning makassar

kanre jangang

buras

putu cangkir

dangke

mie cakalang

Finwa dipakai user Indonesia → makanan lokal tiap daerah penting. Pertimbangkan juga masakan Jawa Timur, Sunda, Manado, Ambon, dll yang belum ter-cover

Gap 3 — Transport \& Mobilitas

Keyword transport yang hilang

pete-pete

pete pete

bentor

becak motor

becak

andong

delman

tiket kereta

tiket pesawat

tiket kapal

kapal feri

feri

tiket bus

tiket travel

tiket damri

damri

travel

sewa motor

sewa mobil

rental motor

rental mobil

cas motor

charge motor listrik

motor listrik

pln kendaraan

tiket tol

e-toll

kartu tol

Gap 4 — Kesehatan \& Obat-obatan

Obat \& layanan kesehatan yang belum ada

beli susu bayi

susu formula

susu ibu hamil

prenatal

kontrasepsi

kb

pil kb

kondom

tetes mata

tetes telinga

ear drops

suplemen

zinc

omega 3

fish oil

vitaline

curcuma

scott emulsion

youvit

enervon c

hemaviton

becom c

redoxon

natrium diklofenak

ibuprofen

amoxicillin

antasida

loperamide

cetirizine

loratadine

kemoterapi

hemodialisis

rawat inap

rawat jalan

iur bpjs

denda bpjs

nakes

Gap 5 — Ibadah \& Keagamaan (penting untuk user Muslim)

Pengeluaran ibadah belum punya kategori sendiri — masuk ke donasi atau lainnya

haji

umroh

bpih

biaya haji

biaya umroh

travel umroh

daftar haji

buku iqro

al quran

sajadah

tasbih

peci

→ pakaian sudah ada

sarung

→ pakaian sudah ada

biaya pengajian

iuran masjid

kas masjid

infaq masjid

renovasi masjid

qurban sapi

qurban kambing

aqiqah kambing

kurban

Rekomendasi: tambah kategori pengeluaran\_ibadah terpisah dari donasi untuk haji/umroh/biaya pengajian

Gap 6 — Digital \& Teknologi

SaaS, tools, dan kebutuhan digital yang hilang

figma

notion

slack

zoom

dropbox

grammarly

semrush

ahrefs

vercel

netlify

aws

gcp

digitalocean

vultr

vps

server

cpanel

ssl

antivirus

kaspersky

windows license

office 365

github copilot

claude pro

gemini

openai

api key

top up token ai

midjourney

Ini relevan untuk segmen user developer/freelancer Finwa. Masukkan ke pengeluaran\_langganan

Gap 7 — Income: Freelance \& Gig Economy

Pendapatan non-formal yang belum terdaftar di income\_keywords

desain grafis

jasa desain

jasa edit

jasa foto

jasa video

jasa tulis

jasa ketik

jasa admin

jasa ojek

hasil narik

hasil ojol

komisi penjualan

komisi afiliasi

passive income

pendapatan pasif

adsense

monetisasi

youtube

tiktok creator

creator fund

content creator

hasil konten

penghasilan konten

hasil freelance

bayaran proyek

Gap 8 — Hewan Peliharaan (belum ada kategori)

Semua keyword pet saat ini jatuh ke pengeluaran\_lainnya atau salah kategori

pakan kucing

pakan anjing

makanan kucing

makanan anjing

whiskas

friskies

royal canin

obat kucing

obat anjing

vaksin kucing

vaksin anjing

grooming kucing

grooming anjing

pet shop

dokter hewan

vet

kandang

pasir kucing

cat litter

aquarium

pakan ikan

Rekomendasi: tambah kategori pengeluaran\_hewan\_peliharaan — segmen pet owner di Indonesia cukup besar

Yang sudah bagus ✓

makanan — sangat lengkap

donasi \& zakat — komprehensif

ojol income detection

weighted match logic

batch transaction keywords

ai\_category\_overrides

ekspedisi \& logistik

UMKM / bisnis keywords

