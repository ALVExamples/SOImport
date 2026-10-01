<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { fetchImports, uploadFile } from '../api';

const POLL_INTERVAL = 1000;

const statusLabels = {
    pending: 'У черзі',
    processing: 'Обробляється',
    completed: 'Завершено',
    failed: 'Помилка',
};

const statusClasses = {
    pending: 'bg-slate-100 text-slate-700',
    processing: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
};

const file = ref(null);
const fileInput = ref(null);
const dragging = ref(false);
const uploading = ref(false);
const uploadProgress = ref(0);
const error = ref('');
const imports = ref([]);

let timer = null;

const hasActiveImports = computed(() =>
    imports.value.some((item) => item.status === 'pending' || item.status === 'processing'),
);

const numberFormat = new Intl.NumberFormat('uk-UA');
const dateFormat = new Intl.DateTimeFormat('uk-UA', { dateStyle: 'short', timeStyle: 'medium' });

function formatNumber(value) {
    return value === null ? '—' : numberFormat.format(value);
}

function formatSize(bytes) {
    return `${(bytes / 1024 / 1024).toFixed(1)} МБ`;
}

function selectFile(selected) {
    error.value = '';

    if (!selected) {
        return;
    }

    if (!selected.name.toLowerCase().endsWith('.xlsx')) {
        error.value = 'Підтримуються лише файли .xlsx.';
        return;
    }

    file.value = selected;
}

function onDrop(event) {
    dragging.value = false;
    selectFile(event.dataTransfer.files[0]);
}

async function refresh() {
    try {
        imports.value = await fetchImports();
    } catch (exception) {
        error.value = exception.message;
    }

    schedule();
}

function schedule() {
    clearTimeout(timer);

    if (hasActiveImports.value) {
        timer = setTimeout(refresh, POLL_INTERVAL);
    }
}

async function submit() {
    if (!file.value || uploading.value) {
        return;
    }

    error.value = '';
    uploading.value = true;
    uploadProgress.value = 0;

    try {
        const created = await uploadFile(file.value, (percent) => (uploadProgress.value = percent));

        imports.value = [created, ...imports.value].slice(0, 10);
        file.value = null;
        fileInput.value.value = '';
        schedule();
    } catch (exception) {
        error.value = exception.message;
    } finally {
        uploading.value = false;
    }
}

onMounted(refresh);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-10">
        <h1 class="text-2xl font-semibold">Імпорт заявок</h1>
        <p class="mt-1 text-sm text-slate-500">
            Завантажте xlsx-файл із заявками. Файл передається частинами та обробляється у фоні.
        </p>

        <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <label
                class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-10 text-center transition"
                :class="dragging ? 'border-blue-500 bg-blue-50' : 'border-slate-300 hover:border-slate-400'"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <input
                    ref="fileInput"
                    type="file"
                    accept=".xlsx"
                    class="hidden"
                    :disabled="uploading"
                    @change="selectFile($event.target.files[0])"
                />
                <span v-if="file" class="font-medium">{{ file.name }} · {{ formatSize(file.size) }}</span>
                <span v-else class="font-medium">Перетягніть файл сюди або натисніть, щоб обрати</span>
                <span class="mt-1 text-sm text-slate-500">Формат .xlsx</span>
            </label>

            <div v-if="uploading" class="mt-4">
                <div class="flex justify-between text-sm text-slate-600">
                    <span>Завантаження файлу</span>
                    <span>{{ uploadProgress }}%</span>
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full bg-blue-600 transition-all" :style="{ width: `${uploadProgress}%` }"></div>
                </div>
            </div>

            <p v-if="error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

            <button
                type="button"
                class="mt-4 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                :disabled="!file || uploading"
                @click="submit"
            >
                {{ uploading ? 'Завантаження…' : 'Імпортувати' }}
            </button>
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">Останні імпорти</h2>

            <p v-if="!imports.length" class="mt-3 text-sm text-slate-500">Імпортів ще не було.</p>

            <ul class="mt-3 space-y-3">
                <li
                    v-for="item in imports"
                    :key="item.id"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ item.original_name }}</p>
                            <p class="text-sm text-slate-500">
                                #{{ item.id }} · {{ dateFormat.format(new Date(item.created_at)) }}
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-medium" :class="statusClasses[item.status]">
                            {{ statusLabels[item.status] }}
                        </span>
                    </div>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full transition-all"
                            :class="item.status === 'failed' ? 'bg-red-500' : 'bg-emerald-500'"
                            :style="{ width: `${item.progress}%` }"
                        ></div>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-slate-500">Записано рядків</dt>
                            <dd class="font-medium">{{ formatNumber(item.processed_rows) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Усього рядків</dt>
                            <dd class="font-medium">{{ formatNumber(item.total_rows) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Рядків із зауваженнями</dt>
                            <dd class="font-medium">{{ formatNumber(item.issues_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Час обробки</dt>
                            <dd class="font-medium">{{ item.duration === null ? '—' : `${item.duration} с` }}</dd>
                        </div>
                    </dl>

                    <p v-if="item.error" class="mt-3 text-sm text-red-700">{{ item.error }}</p>
                </li>
            </ul>
        </section>
    </main>
</template>
